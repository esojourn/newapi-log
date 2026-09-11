<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardChartsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 独立内存连接，不迁移、清空或写入外部 NewAPI 库。
        config([
            'database.connections.dashboard_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
        ]);
        DB::setDefaultConnection('dashboard_test');
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:30:00'));

        // SQLite 补齐查询所需的 MySQL 函数；这些测试验证时间分桶和汇总范围。
        $pdo = DB::connection('dashboard_test')->getPdo();
        $pdo->sqliteCreateFunction('FROM_UNIXTIME', fn ($timestamp) => date('Y-m-d H:i:s', $timestamp), 1);
        $pdo->sqliteCreateFunction('DATE_FORMAT', fn ($date, $format) => Carbon::parse($date)->format(
            strtr($format, ['%m' => 'm', '%d' => 'd', '%H' => 'H'])
        ), 2);
        $pdo->sqliteCreateFunction('GREATEST', fn (...$values) => max($values));
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value, 1);

        Schema::connection('dashboard_test')->create('logs', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('created_at');
            $table->string('token_name');
            $table->string('model_name');
            $table->integer('prompt_tokens');
            $table->integer('completion_tokens');
            $table->bigInteger('quota');
            $table->text('other')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('dashboard_test');

        parent::tearDown();
    }

    public function trendRanges(): array
    {
        return [
            'hourly' => [1, 24, 'm-d H:00'],
            'daily' => [3, 4, 'Y-m-d'],
        ];
    }

    /** @dataProvider trendRanges */
    public function test_trends_include_all_users_and_models_with_zero_filled_buckets(int $days, int $buckets, string $format): void
    {
        $end = Carbon::parse('2026-09-10 12:00:00');
        if ($days !== 1) {
            $end->startOfDay();
        }
        $since = $days === 1 ? $end->copy()->subHours(23) : $end->copy()->subDays($days);
        $previous = $days === 1 ? $end->copy()->subHour() : $end->copy()->subDay();
        $until = $days === 1 ? $end->copy()->addHour() : $end->copy()->addDay();

        // 11 个用户、6 个模型，确保总金额和「全部模型」不受 Top 10 / Top 5 限制。
        for ($i = 0; $i < 11; $i++) {
            $this->insertLog($previous->copy()->addMinutes(15), 'user-' . $i, 'model-' . ($i % 6), (11 - $i) * 500000, (11 - $i) * 100);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->insertLog($end->copy()->addMinutes(15), 'user-0', 'model-0', 125000);
        }
        $this->insertLog($since, 'user-1', 'model-1', 62500);
        $this->insertLog($since->copy()->subSecond(), 'outside', 'outside', 5000000);
        $this->insertLog($until, 'outside', 'outside', 5000000);

        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin?days=' . $days . '&at=2026-09-10T12:00')
            ->assertOk();

        $dates = $response->viewData('dates');
        $this->assertCount($buckets, $dates);
        $this->assertNotContains('user-10', $response->viewData('topUserNames'));
        $this->assertCount(5, $response->viewData('cacheModelNames'));
        $this->assertNotContains('model-5', $response->viewData('cacheModelNames'));

        $expectedCounts = array_fill_keys($dates, 0);
        $expectedCounts[$since->format($format)] = 1;
        $expectedCounts[$previous->format($format)] = 11;
        $expectedCounts[$end->format($format)] = 3;
        $this->assertSame($expectedCounts, $response->viewData('dailyCacheData')['request_count']);

        $expectedAmounts = array_fill_keys($dates, 0);
        $expectedAmounts[$since->format($format)] = 0.125;
        $expectedAmounts[$previous->format($format)] = 66.0;
        $expectedAmounts[$end->format($format)] = 0.75;
        $this->assertSame($expectedAmounts, $response->viewData('dailyTotalAmountData'));

        $models = $response->viewData('modelCacheData');
        $expectedModelCounts = array_fill_keys($dates, 0);
        $expectedModelCounts[$previous->format($format)] = 2;
        $expectedModelCounts[$end->format($format)] = 3;
        $this->assertSame($expectedModelCounts, $models['model-0']['request_count']);
        $this->assertSame(15, array_sum($expectedCounts));
        $this->assertSame(14, array_sum(array_map(fn ($series) => array_sum($series['request_count']), $models)));
    }

    public function test_empty_window_keeps_zero_values_for_both_charts(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin?at=2026-09-10T12:00')
            ->assertOk();

        $dates = $response->viewData('dates');
        $this->assertCount(24, $dates);
        $this->assertSame(array_fill_keys($dates, 0), $response->viewData('dailyTotalAmountData'));
        $this->assertSame(array_fill_keys($dates, 0), $response->viewData('dailyCacheData')['request_count']);
        $this->assertSame([], $response->viewData('modelCacheData'));
    }

    private function insertLog(Carbon $at, string $user, string $model, int $quota, int $tokens = 100): void
    {
        DB::connection('dashboard_test')->table('logs')->insert([
            'created_at' => $at->timestamp,
            'token_name' => $user,
            'model_name' => $model,
            'prompt_tokens' => $tokens,
            'completion_tokens' => 10,
            'quota' => $quota,
            'other' => json_encode(['cache_tokens' => 20, 'cache_ratio' => 0.1, 'model_ratio' => 1]),
        ]);
    }
}
