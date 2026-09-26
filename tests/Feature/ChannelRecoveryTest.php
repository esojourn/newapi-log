<?php

namespace Tests\Feature;

use App\Services\ChannelRecoveryChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChannelRecoveryTest extends TestCase
{
    private array $apiChannels = [];
    private $onTest;
    private $onEnable;
    private bool $legacyApi = false;

    protected function setUp(): void
    {
        parent::setUp();

        // 只在专用内存连接造数据，绝不迁移或写入外部 NewAPI 库。
        config([
            'database.connections.channel_recovery_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'channels.recovery_enabled' => true,
            'channels.schedule_cron' => '*/7 * * * *',
            'channels.base_url' => 'https://newapi.example',
            'channels.access_token' => 'private-admin-token',
            'channels.user_id' => '42',
            'channels.http_timeout' => 30,
        ]);
        DB::setDefaultConnection('channel_recovery_test');
        Schema::connection('channel_recovery_test')->create('channels', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->integer('status');
            $table->integer('auto_ban')->nullable();
        });

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && preg_match('#^/api/channel/(\d+)$#', $path, $match)) {
                return Http::response(['success' => true, 'data' => $this->apiChannels[(int) $match[1]] ?? null]);
            }

            if ($request->method() === 'GET' && preg_match('#^/api/channel/test/(\d+)$#', $path, $match)) {
                return $this->onTest
                    ? ($this->onTest)((int) $match[1])
                    : Http::response(['success' => true, 'time' => 0.2]);
            }

            if ($request->method() === 'POST' && preg_match('#^/api/channel/(\d+)/status$#', $path, $match)) {
                if ($this->legacyApi) {
                    return Http::response([], 404);
                }

                return $this->enable((int) $match[1]);
            }

            if ($request->method() === 'PUT' && $path === '/api/channel/') {
                return $this->enable((int) $request['id']);
            }

            throw new \LogicException('Unexpected HTTP request in test');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('channel_recovery_test');

        parent::tearDown();
    }

    public function test_only_auto_banned_and_auto_disabled_channels_are_tested_and_restored(): void
    {
        $this->seedChannel(1);
        $this->seedChannel(2, 1, 1);
        $this->seedChannel(3, 2, 1);
        $this->seedChannel(4, 3, 0);
        $this->seedChannel(5, 3, null);
        $this->seedChannel(6, 1, 0);
        $this->seedChannel(7, 2, 0);
        DB::enableQueryLog();

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(['checked' => 1, 'healthy' => 1, 'recovered' => 1, 'skipped' => 0, 'failed' => 0], $stats);
        Http::assertSentCount(5);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://newapi.example/api/channel/1/status'
                && $request->data() === ['status' => 1]
                && $request->hasHeader('Authorization', 'Bearer private-admin-token')
                && $request->hasHeader('New-Api-User', '42');
        });
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('*', $query['query']);
        }
        $this->assertSame(3, DB::table('channels')->where('id', 1)->value('status'));
    }

    public function test_disabled_feature_does_not_query_database_or_send_requests(): void
    {
        config(['channels.recovery_enabled' => false]);
        DB::enableQueryLog();

        $this->assertSame(0, app(ChannelRecoveryChecker::class)->run()['checked']);
        $this->artisan('channels:recover')->expectsOutput('渠道自动恢复未开启，请配置 CHANNEL_RECOVERY_ENABLED=true。')->assertExitCode(0);

        $this->assertSame([], DB::getQueryLog());
        Http::assertNothingSent();
    }

    public function ineligibleStates(): array
    {
        return [
            'manually disabled' => [2, 1],
            'already enabled' => [1, 1],
            'auto ban off' => [3, 0],
            'auto ban unset' => [3, null],
        ];
    }

    /** @dataProvider ineligibleStates */
    public function test_api_state_is_rechecked_before_testing(int $status, ?int $autoBan): void
    {
        $this->seedChannel(1);
        $this->apiChannels[1]['status'] = $status;
        $this->apiChannels[1]['auto_ban'] = $autoBan;

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(0, $stats['checked']);
        $this->assertSame(1, $stats['skipped']);
        Http::assertSentCount(1);
    }

    /** @dataProvider ineligibleStates */
    public function test_changes_during_probe_prevent_reenabling(int $status, ?int $autoBan): void
    {
        $this->seedChannel(1);
        $this->onTest = function ($id) use ($status, $autoBan) {
            $this->apiChannels[$id]['status'] = $status;
            $this->apiChannels[$id]['auto_ban'] = $autoBan;

            return Http::response(['success' => true]);
        };

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['healthy']);
        $this->assertSame(0, $stats['recovered']);
        $this->assertSame(1, $stats['skipped']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function failedProbeResponses(): array
    {
        return [
            'business failure' => [['success' => false, 'message' => 'private-upstream-key'], 200],
            'unauthorized' => [['success' => true], 401],
            'rate limited' => [['success' => false], 429],
            'server error' => [['success' => true], 500],
            'redirect' => ['', 302],
            'html instead of json' => ['<html>login</html>', 200],
            'empty response' => [[], 200],
            'string success is not boolean' => [['success' => 'false'], 200],
        ];
    }

    /** @dataProvider failedProbeResponses */
    public function test_unsuccessful_or_invalid_probes_never_enable_channels($body, int $status): void
    {
        $this->seedChannel(1);
        $this->onTest = fn () => Http::response($body, $status);

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['recovered']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_timeout_is_redacted_and_does_not_stop_other_channels(): void
    {
        Log::spy();
        $this->seedChannel(1);
        $this->seedChannel(2);
        $this->onTest = function ($id) {
            if ($id === 1) {
                throw new ConnectionException('private-upstream-key private-admin-token');
            }

            return Http::response(['success' => true]);
        };

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(2, $stats['checked']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['recovered']);
        Log::shouldHaveReceived('warning')->once()->with('Channel recovery failed', [
            'channel_id' => 1, 'stage' => 'test', 'error' => 'NewAPI 请求超时或连接失败。',
        ]);
    }

    public function test_dry_run_tests_health_without_enabling(): void
    {
        $this->seedChannel(1);

        $stats = app(ChannelRecoveryChecker::class)->run(true);

        $this->assertSame(1, $stats['healthy']);
        $this->assertSame(0, $stats['recovered']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_legacy_api_fallback_sends_only_id_and_status(): void
    {
        $this->seedChannel(1);
        $this->legacyApi = true;

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['recovered']);

        Http::assertSentCount(7);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->data() === ['id' => 1, 'status' => 1]);
    }

    public function test_enable_failure_is_not_counted_as_recovery_or_retried(): void
    {
        $this->seedChannel(1);
        $this->onEnable = fn () => Http::response(['success' => false], 403);

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['healthy']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['recovered']);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
        Http::assertSentCount(4);
    }

    public function test_update_success_must_be_confirmed_by_enabled_status(): void
    {
        $this->seedChannel(1);
        $this->onEnable = fn () => Http::response(['success' => true, 'data' => false]);

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(0, $stats['recovered']);
        $this->assertSame(1, $stats['failed']);
        Http::assertSentCount(5);
    }

    public function test_paging_does_not_skip_channels_when_upstream_restores_previous_page(): void
    {
        for ($id = 1; $id <= 102; $id++) {
            $this->seedChannel($id);
        }
        $this->onEnable = function ($id) {
            $this->apiChannels[$id]['status'] = 1;
            // 模拟 NewAPI 自己的写入使下一页候选集变小，不是被测服务写库。
            DB::connection('channel_recovery_test')->table('channels')->where('id', $id)->update(['status' => 1]);

            return Http::response(['success' => true]);
        };

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(102, $stats['checked']);
        $this->assertSame(102, $stats['recovered']);
        $this->assertSame(0, $stats['failed']);
    }

    public function test_single_channel_filter_still_requires_eligibility(): void
    {
        $this->seedChannel(1);
        $this->seedChannel(2);
        $this->seedChannel(3, 2);

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run(false, 2)['recovered']);
        $this->assertSame(0, app(ChannelRecoveryChecker::class)->run(false, 3)['checked']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/test/1') || str_contains($request->url(), '/test/3'));
    }

    public function test_missing_credentials_fail_before_reading_database(): void
    {
        config(['channels.access_token' => '']);
        DB::enableQueryLog();

        $this->artisan('channels:recover')->assertExitCode(1);

        $this->assertSame([], DB::getQueryLog());
        Http::assertNothingSent();
    }

    public function test_overlapping_runs_are_rejected_and_lock_is_released_after_failure(): void
    {
        $this->seedChannel(1);
        $lock = Cache::lock('channels:recover', 86400);
        $this->assertTrue($lock->get());

        $this->artisan('channels:recover')->expectsOutput('已有渠道恢复检查正在运行，请稍后重试。')->assertExitCode(1);
        Http::assertNothingSent();
        $lock->release();

        $this->onTest = fn () => Http::response(['success' => false]);
        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['failed']);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_database_failure_also_releases_lock(): void
    {
        Schema::connection('channel_recovery_test')->drop('channels');

        $this->artisan('channels:recover')->assertExitCode(1);

        $lock = Cache::lock('channels:recover', 86400);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_command_validates_id_and_reports_probe_failure(): void
    {
        $this->seedChannel(1);
        $this->onTest = fn () => Http::response(['success' => false]);

        foreach (['abc', '0', '-1', '1.5'] as $id) {
            $this->artisan('channels:recover', ['--channel-id' => $id])->assertExitCode(1);
        }
        Http::assertNothingSent();
        $this->artisan('channels:recover', ['--channel-id' => '1'])->assertExitCode(1);
    }

    public function test_schedule_uses_configured_interval_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'channels:recover'));

        $this->assertCount(1, $events);
        $this->assertSame('*/7 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_schedule_is_not_registered_when_disabled(): void
    {
        config(['channels.recovery_enabled' => false]);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'channels:recover'));

        $this->assertCount(0, $events);
    }

    private function seedChannel(int $id, int $status = 3, ?int $autoBan = 1): void
    {
        $channel = ['id' => $id, 'status' => $status, 'auto_ban' => $autoBan];
        DB::connection('channel_recovery_test')->table('channels')->insert($channel);
        $this->apiChannels[$id] = $channel;
    }

    private function enable(int $id)
    {
        if ($this->onEnable) {
            return ($this->onEnable)($id);
        }

        $this->apiChannels[$id]['status'] = 1;

        return Http::response(['success' => true]);
    }
}
