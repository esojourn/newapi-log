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
        $pdo->sqliteCreateFunction('HOUR', fn ($date) => (int) Carbon::parse($date)->format('H'), 1);

        Schema::connection('dashboard_test')->create('logs', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('created_at');
            $table->string('token_name');
            $table->string('model_name');
            $table->integer('channel_id')->default(0);
            $table->string('channel_name')->default('');
            $table->integer('prompt_tokens');
            $table->integer('completion_tokens');
            $table->bigInteger('quota');
            $table->text('other')->nullable();
            $table->string('group')->default('default');
            $table->integer('use_time')->default(1);
            $table->boolean('is_stream')->default(false);
            $table->text('content')->default('');
        });
        Schema::connection('dashboard_test')->create('channels', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
            $table->bigInteger('priority')->nullable()->default(0);
            $table->integer('weight')->nullable()->default(0);
            $table->string('key')->default('upstream-api-secret');
        });
        Schema::connection('dashboard_test')->create('tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('key');
            $table->integer('remain_quota')->default(500000);
            $table->boolean('unlimited_quota')->default(false);
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
        $this->assertSame($expectedAmounts, $response->viewData('dailyCacheData')['amounts']);

        $expectedTokens = array_fill_keys($dates, 0);
        $expectedTokens[$since->format($format)] = 110;
        $expectedTokens[$previous->format($format)] = 6710;
        $expectedTokens[$end->format($format)] = 330;
        $this->assertSame($expectedTokens, $response->viewData('dailyCacheData')['total_tokens']);
        $this->assertSame(array_sum($expectedTokens), $response->viewData('overview')->total_tokens);

        $models = $response->viewData('modelCacheData');
        $expectedModelCounts = array_fill_keys($dates, 0);
        $expectedModelCounts[$previous->format($format)] = 2;
        $expectedModelCounts[$end->format($format)] = 3;
        $this->assertSame($expectedModelCounts, $models['model-0']['request_count']);
        $expectedModelAmounts = array_fill_keys($dates, 0);
        $expectedModelAmounts[$previous->format($format)] = 16.0;
        $expectedModelAmounts[$end->format($format)] = 0.75;
        $this->assertSame($expectedModelAmounts, $models['model-0']['amounts']);
        $expectedModelTokens = array_fill_keys($dates, 0);
        $expectedModelTokens[$previous->format($format)] = 1620;
        $expectedModelTokens[$end->format($format)] = 330;
        $this->assertSame($expectedModelTokens, $models['model-0']['total_tokens']);
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
        foreach ($response->viewData('dailyCacheData') as $series) {
            $this->assertSame(array_fill_keys($dates, 0), $series);
        }
        $this->assertSame([], $response->viewData('modelCacheData'));
        $this->assertSame([], $response->viewData('cacheChannels'));
    }

    public function test_admin_channel_options_include_all_logged_channels_and_deleted_channels(): void
    {
        $at = Carbon::parse('2026-09-10 12:15:00');
        $escapedName = 'upstream-private-<script>alert(1)</script>';
        DB::table('channels')->insert([
            ['id' => 7, 'name' => $escapedName],
            ['id' => 8, 'name' => $escapedName],
            ['id' => 10, 'name' => 'outside-window-private'],
        ]);
        foreach ([7, 8, 9, 0] as $id) {
            $this->insertLog($at, 'user', 'model', 500000, 100, $id);
        }
        $this->insertLog($at->copy()->addHour(), 'user', 'model', 500000, 100, 10);

        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin?at=2026-09-10T12:00')->assertOk()
            ->assertSee('cacheChannelFilter', false)
            ->assertSee(e($escapedName), false)
            ->assertDontSee($escapedName, false)
            ->assertDontSee('upstream-api-secret', false)
            ->assertDontSee('outside-window-private', false);

        $this->assertSame([
            ['id' => 7, 'name' => $escapedName],
            ['id' => 8, 'name' => $escapedName],
            ['id' => 9, 'name' => '已删除或未命名渠道'],
        ], $response->viewData('cacheChannels'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_admin_channel_options_sort_by_priority_then_weight_with_deleted_channels_last(): void
    {
        DB::table('channels')->insert([
            ['id' => 7, 'name' => '低优先级', 'priority' => -1, 'weight' => 100],
            ['id' => 8, 'name' => '高优先级低权重', 'priority' => 10, 'weight' => 1],
            ['id' => 9, 'name' => '高优先级高权重', 'priority' => 10, 'weight' => 50],
            ['id' => 10, 'name' => '同优先级同权重', 'priority' => 10, 'weight' => 50],
            ['id' => 11, 'name' => '默认排序配置', 'priority' => null, 'weight' => null],
            ['id' => 12, 'name' => '零优先级', 'priority' => 0, 'weight' => 0],
            ['id' => 13, 'name' => '时段内无日志', 'priority' => 100, 'weight' => 100],
        ]);
        foreach ([6, 7, 8, 9, 10, 11, 12, 14] as $id) {
            $this->insertLog(Carbon::parse('2026-09-10 12:15:00'), 'user', 'model', 500000, 100, $id);
        }

        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin?at=2026-09-10T12:00')->assertOk();

        $this->assertSame([9, 10, 8, 11, 12, 7, 6, 14], array_column($response->viewData('cacheChannels'), 'id'));
        $response->assertSeeInOrder(array_map(fn ($id) => '<option value="' . $id . '">', [9, 10, 8, 11, 12, 7, 6, 14]), false);
    }

    /** @dataProvider trendRanges */
    public function test_cache_trend_combines_channel_model_and_time_filters(int $days, int $buckets, string $format): void
    {
        $end = Carbon::parse('2026-09-10 12:00:00');
        if ($days !== 1) {
            $end->startOfDay();
        }
        $since = $days === 1 ? $end->copy()->subHours(23) : $end->copy()->subDays($days);
        $until = $days === 1 ? $end->copy()->addHour() : $end->copy()->addDay();

        $this->insertLog($since, 'user-1', 'model-0', 62500, 100, 7, [
            'cache_tokens' => 20000, 'cache_creation_tokens' => 1000, 'cache_ratio' => 0.1, 'model_ratio' => 1,
            'usage_semantic' => 'anthropic',
        ]);
        $this->insertLog($end, 'user-2', 'model-0', 375000, 50000, 7, [
            'cache_tokens' => 40000, 'cache_creation_tokens' => 1000, 'cache_write_tokens' => 1500,
            'cache_ratio' => 0.1, 'model_ratio' => 1, 'usage_semantic' => 'openai',
        ]);
        $this->insertLog($end, 'user-1', 'model-5', 1000000, 100, 7);
        $this->insertLog($end, 'other-channel', 'model-0', 2000000, 100, 8);
        $this->insertLog($since->copy()->subSecond(), 'outside', 'model-0', 500000, 100, 7);
        $this->insertLog($until, 'outside', 'model-0', 500000, 100, 7);

        $baseUrl = '/admin/cache-trend?days=' . $days . '&at=2026-09-10T12:00';
        $this->withSession(['admin_authenticated' => true]);
        $combined = $this->getJson($baseUrl . '&channel_id=7&model=model-0')->assertOk();
        $dates = $combined->json('dates');
        $this->assertCount($buckets, $dates);
        $expectedCounts = array_fill_keys($dates, 0);
        $expectedCounts[$since->format($format)] = 1;
        $expectedCounts[$end->format($format)] = 1;
        $this->assertSame($expectedCounts, $combined->json('data.request_count'));
        $expectedAmounts = array_fill_keys($dates, 0);
        $expectedAmounts[$since->format($format)] = 0.125;
        $expectedAmounts[$end->format($format)] = 0.75;
        $this->assertSame($expectedAmounts, $combined->json('data.amounts'));
        $expectedTokens = array_fill_keys($dates, 0);
        $expectedTokens[$since->format($format)] = 21110;
        $expectedTokens[$end->format($format)] = 50510;
        $this->assertSame($expectedTokens, $combined->json('data.total_tokens'));
        $this->assertSame(60000, array_sum($combined->json('data.cache_tokens')));
        $this->assertSame(2500, array_sum($combined->json('data.cache_creation_tokens')));
        $this->assertSame(9100, array_sum($combined->json('data.uncached_prompt_tokens')));
        $this->assertSame(0.108, $combined->json('saved_amount'));
        $this->assertStringContainsString('no-store', $combined->headers->get('Cache-Control'));

        $channelOnly = $this->getJson($baseUrl . '&channel_id=7')->assertOk();
        $this->assertSame(3, array_sum($channelOnly->json('data.request_count')));
        $this->assertSame(2.875, array_sum($channelOnly->json('data.amounts')));
        $this->assertSame(71730, array_sum($channelOnly->json('data.total_tokens')));
        $this->assertSame(0.108, $channelOnly->json('saved_amount'));
        $modelOnly = $this->getJson($baseUrl . '&model=model-0')->assertOk();
        $this->assertSame(3, array_sum($modelOnly->json('data.request_count')));
        $this->assertSame(4.875, array_sum($modelOnly->json('data.amounts')));
        $this->assertSame(71730, array_sum($modelOnly->json('data.total_tokens')));
        $all = $this->getJson($baseUrl)->assertOk();
        $this->assertSame(4, array_sum($all->json('data.request_count')));
        $this->assertSame(6.875, array_sum($all->json('data.amounts')));
        $this->assertSame(71840, array_sum($all->json('data.total_tokens')));
        $dashboard = $this->get('/admin?days=' . $days . '&at=2026-09-10T12:00')->assertOk();
        $this->assertSame($dashboard->viewData('dailyCacheData'), $all->json('data'));
        $this->assertSame($dashboard->viewData('overview')->cache_saved_amount, $all->json('saved_amount'));

        foreach (['&channel_id=999', '&channel_id=7&model=missing'] as $filter) {
            $empty = $this->getJson($baseUrl . $filter)->assertOk();
            foreach ($empty->json('data') as $series) {
                $this->assertSame(array_fill_keys($dates, 0), $series);
            }
            $this->assertEquals(0, $empty->json('saved_amount'));
        }
    }

    public function test_cache_trend_rejects_invalid_filters(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        foreach (['channel_id=0', 'channel_id=-1', 'channel_id=abc', 'channel_id[]=7', 'model[]=x', 'model=' . str_repeat('a', 256)] as $query) {
            $this->getJson('/admin/cache-trend?' . $query)->assertStatus(422);
        }
    }

    public function test_api_key_users_cannot_access_admin_channel_data(): void
    {
        foreach (['/admin', '/admin/cache-trend?channel_id=7&model=private'] as $url) {
            $this->getJson($url)->assertRedirect('/admin/login');
        }
        $this->withSession(['user_api_key' => 'sk-user-key', 'user_token_name' => 'user']);
        foreach (['/admin', '/admin/cache-trend?channel_id=7&model=private'] as $url) {
            $this->getJson($url)->assertRedirect('/admin/login')->assertDontSee('cacheChannels', false);
        }
    }

    public function test_user_pages_and_log_apis_never_expose_channel_metadata(): void
    {
        DB::table('tokens')->insert(['name' => 'user', 'key' => 'user-key']);
        DB::table('channels')->insert(['id' => 71001, 'name' => 'upstream-private-name']);
        $this->insertLog(Carbon::parse('2026-09-10 12:15:00'), 'user', 'model', 500000, 100, 71001, [
            'cache_tokens' => 20,
            'admin_info' => ['channel_id' => 71001, 'channel_name' => 'upstream-private-name', 'key' => 'upstream-api-secret'],
        ]);
        DB::table('logs')->update(['channel_name' => 'upstream-private-name']);
        $this->insertLog(Carbon::parse('2026-09-10 12:15:00'), 'user', 'model', 500000, 100, 71002);
        DB::enableQueryLog();
        $this->post('/', ['api_key' => 'sk-user-key'])->assertRedirect('/usage');

        foreach (['/usage', '/user/sk-user-key'] as $url) {
            $response = $this->get($url . '?at=2026-09-10T12:00')->assertOk();
            $this->assertSame(2, (int) $response->viewData('overview')->total_requests);
            $this->assertArrayNotHasKey('cacheChannels', $response->original->getData());
            foreach (['cacheChannelFilter', '/admin/cache-trend', 'upstream-private-name', 'upstream-api-secret', 'channel_name', 'admin_info'] as $private) {
                $response->assertDontSee($private, false);
            }
        }
        foreach (['/usage/logs', '/user/sk-user-key/logs', '/usage/hourly?date=2026-09-10', '/user/sk-user-key/hourly?date=2026-09-10', '/api/log'] as $url) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'channel_id=71001&model=missing';
            $response = $this->withHeader('Authorization', 'Bearer sk-user-key')->getJson($url)->assertOk();
            // 分页链接可回显用户传入的参数，只检查实际返回的数据字段。
            $data = json_encode($response->json(strpos($url, '/hourly') !== false ? 'hours' : 'data'));
            foreach (['channel_id', 'channel_name', 'admin_info', 'upstream-private-name', 'upstream-api-secret', '71001', '71002'] as $private) {
                $this->assertStringNotContainsString($private, $data);
            }
            if (strpos($url, '/hourly') !== false) {
                $this->assertSame(2, $response->json('total_requests'));
            } else {
                $this->assertSame(2, $response->json('total'));
            }
        }
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('"channels"', $query['query']);
        }
    }

    private function insertLog(Carbon $at, string $user, string $model, int $quota, int $tokens = 100, int $channelId = 0, ?array $other = null): void
    {
        DB::connection('dashboard_test')->table('logs')->insert([
            'created_at' => $at->timestamp,
            'token_name' => $user,
            'model_name' => $model,
            'channel_id' => $channelId,
            'prompt_tokens' => $tokens,
            'completion_tokens' => 10,
            'quota' => $quota,
            'other' => json_encode($other ?? ['cache_tokens' => 20, 'cache_ratio' => 0.1, 'model_ratio' => 1]),
        ]);
    }
}
