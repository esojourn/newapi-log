<?php

namespace Tests\Feature;

use App\Models\ChannelMonitorLog;
use App\Models\ChannelRecoveryLog;
use App\Models\ChannelRecoveryRun;
use App\Models\ChannelRecoverySetting;
use App\Models\ChannelStatusSample;
use App\Services\ChannelRecoveryChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
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
        Artisan::call('migrate', [
            '--database' => 'alerts',
            '--path' => 'database/migrations/alerts',
            '--force' => true,
        ]);
        DB::setDefaultConnection('channel_recovery_test');
        Schema::connection('channel_recovery_test')->create('channels', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->nullable();
            $table->integer('status');
            $table->integer('auto_ban')->nullable();
            $table->bigInteger('priority')->nullable()->default(0);
            $table->unsignedBigInteger('weight')->nullable()->default(0);
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
        Carbon::setTestNow();
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
        $action = ChannelRecoveryLog::sole();
        $this->assertSame(1, $action->channel_id);
        $this->assertSame('测试渠道 1', $action->channel_name);
        $this->assertSame('manual', $action->source);
        $this->assertSame('recovered', $action->result);
        $this->assertSame(3, $action->from_status);
        $this->assertSame(1, $action->target_status);
        $this->assertNotNull($action->completed_at);
        $monitor = ChannelMonitorLog::sole();
        $this->assertSame(1, $monitor->channel_id);
        $this->assertSame('healthy', $monitor->result);
        $this->assertFalse($monitor->dry_run);
        $this->assertSame([1, 2, 3], ChannelStatusSample::orderBy('channel_id')->pluck('channel_id')->all());
    }

    public function test_disabled_feature_does_not_query_database_or_send_requests(): void
    {
        config(['channels.recovery_enabled' => false]);
        DB::enableQueryLog();

        $this->assertSame(0, app(ChannelRecoveryChecker::class)->run()['checked']);
        $this->artisan('channels:recover')->expectsOutput('渠道自动恢复未开启，请在管理员渠道恢复设置中开启。')->assertExitCode(0);

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(0, ChannelStatusSample::count());
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
        $this->assertSame(0, ChannelMonitorLog::count());
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
        $this->assertSame('skipped', ChannelRecoveryLog::sole()->result);
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
        $this->assertSame(0, ChannelRecoveryLog::count());
        $this->assertSame($status === 200 && ($body['success'] ?? null) === false ? 'failed' : 'error', ChannelMonitorLog::sole()->result);
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
        $this->assertSame('NewAPI 请求超时或连接失败。', ChannelMonitorLog::where('channel_id', 1)->sole()->message);
        $this->assertSame('error', ChannelMonitorLog::where('channel_id', 1)->sole()->result);
    }

    public function test_dry_run_tests_health_without_enabling(): void
    {
        $this->seedChannel(1);

        $stats = app(ChannelRecoveryChecker::class)->run(true);

        $this->assertSame(1, $stats['healthy']);
        $this->assertSame(0, $stats['recovered']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertSame(0, ChannelRecoveryLog::count());
        $this->assertTrue(ChannelMonitorLog::sole()->dry_run);
        $this->assertSame('healthy', ChannelMonitorLog::sole()->result);
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
        $this->assertSame('failed', ChannelRecoveryLog::sole()->result);
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

    public function test_schedule_polls_settings_every_minute_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'channels:recover'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertStringContainsString('--scheduled', $events->first()->command);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_schedule_remains_registered_when_env_is_disabled_so_page_can_enable_it(): void
    {
        config(['channels.recovery_enabled' => false]);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'channels:recover'));

        $this->assertCount(1, $events);
    }

    public function test_saved_settings_and_schedule_are_reloaded_between_runs(): void
    {
        $this->seedChannel(1);
        $settings = ChannelRecoverySetting::current();
        $settings->schedule_cron = '*/10 * * * *';
        $settings->access_token = 'saved-admin-token';
        $settings->user_id = 99;
        $settings->save();
        config(['channels.recovery_enabled' => false]);
        Carbon::setTestNow(Carbon::parse('2026-09-26 12:07:00', config('app.timezone')));
        $checker = app(ChannelRecoveryChecker::class);

        $this->assertSame(0, $checker->run(false, null, true)['checked']);
        Http::assertNothingSent();

        $settings->update(['schedule_cron' => '*/7 * * * *']);
        $this->assertSame(1, $checker->run(false, null, true)['recovered']);
        $this->assertSame('scheduled', ChannelRecoveryLog::sole()->source);
        $this->assertSame('scheduled', ChannelMonitorLog::sole()->source);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer saved-admin-token')
            && $request->hasHeader('New-Api-User', '99'));
    }

    public function test_saved_disabled_setting_overrides_enabled_environment(): void
    {
        $this->seedChannel(1);
        $settings = ChannelRecoverySetting::current();
        $settings->enabled = false;
        $settings->save();

        $this->assertSame(0, app(ChannelRecoveryChecker::class)->run()['checked']);
        $this->artisan('channels:recover')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_recovery_intent_is_persisted_before_status_update(): void
    {
        $this->seedChannel(1);
        $this->onEnable = function ($id) {
            $action = ChannelRecoveryLog::sole();
            $this->assertSame($id, $action->channel_id);
            $this->assertSame('pending', $action->result);
            $this->assertNull($action->completed_at);
            $this->apiChannels[$id]['status'] = 1;

            return Http::response(['success' => true]);
        };

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['recovered']);
        $this->assertSame('recovered', ChannelRecoveryLog::sole()->result);
        $this->assertSame(0, app(ChannelRecoveryChecker::class)->run()['recovered']);
        $this->assertSame(1, ChannelRecoveryLog::count());
    }

    public function test_no_status_update_when_local_audit_storage_is_unavailable(): void
    {
        $this->seedChannel(1);
        Schema::connection('alerts')->drop('channel_recovery_logs');

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['healthy']);
        $this->assertSame(0, $stats['recovered']);
        $this->assertSame(1, $stats['failed']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_completion_write_failure_leaves_pending_record_for_followup(): void
    {
        $this->seedChannel(1);
        DB::connection('alerts')->statement("CREATE TRIGGER deny_log_completion BEFORE UPDATE ON channel_recovery_logs BEGIN SELECT RAISE(FAIL, 'test audit failure'); END");

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['recovered']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame('pending', ChannelRecoveryLog::sole()->result);
        $this->assertNull(ChannelRecoveryLog::sole()->completed_at);
    }

    public function test_disabled_snapshot_is_saved_before_testing_and_survives_recovery(): void
    {
        $this->seedChannel(1);
        $disabledAt = Carbon::parse('2026-10-02 10:00:00', config('app.timezone'));
        $this->apiChannels[1]['other_info'] = json_encode([
            'status_reason' => '上游余额不足，请充值后重试。',
            'status_time' => $disabledAt->timestamp,
        ]);
        $this->onTest = function ($id) use ($disabledAt) {
            $monitor = ChannelMonitorLog::sole();
            $this->assertSame('pending', $monitor->result);
            $this->assertSame('上游余额不足，请充值后重试。', $monitor->disabled_reason);
            $this->assertTrue($monitor->disabled_at->equalTo($disabledAt));
            $this->assertNull($monitor->completed_at);
            $this->apiChannels[$id]['other_info'] = json_encode(['status_reason' => '状态已变化']);

            return Http::response(['success' => true]);
        };

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['recovered']);
        $this->assertSame('上游余额不足，请充值后重试。', ChannelMonitorLog::sole()->disabled_reason);
        $this->assertSame('healthy', ChannelMonitorLog::sole()->result);
        $this->assertNotNull(ChannelMonitorLog::sole()->completed_at);
    }

    public function test_every_failed_check_preserves_its_own_upstream_message(): void
    {
        $this->seedChannel(1);
        $this->apiChannels[1]['other_info'] = ['status_reason' => '额度耗尽', 'status_time' => '1790899200'];
        $this->onTest = fn () => Http::response(['success' => false, 'message' => '请充值账户余额']);
        $checker = app(ChannelRecoveryChecker::class);
        $this->assertSame(1, $checker->run()['failed']);
        $this->onTest = fn () => Http::response(['success' => false, 'message' => '上游仍在限流，请稍后重试']);
        $this->assertSame(1, $checker->run()['failed']);

        $logs = ChannelMonitorLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['请充值账户余额', '上游仍在限流，请稍后重试'], $logs->pluck('message')->all());
        $this->assertSame(['额度耗尽', '额度耗尽'], $logs->pluck('disabled_reason')->all());
        $this->assertSame(['failed', 'failed'], $logs->pluck('result')->all());
        $this->assertSame(1790899200, $logs->first()->disabled_at->timestamp);
        $this->assertSame(0, ChannelRecoveryLog::count());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_http_errors_include_structured_upstream_failure_details(): void
    {
        $this->seedChannel(1);
        $this->onTest = fn () => Http::response(['message' => '请求失败', 'error' => ['message' => '模型暂不可用']], 503);

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['failed']);

        $monitor = ChannelMonitorLog::sole();
        $this->assertSame('error', $monitor->result);
        $this->assertStringContainsString('HTTP 503', $monitor->message);
        $this->assertStringContainsString('请求失败', $monitor->message);
        $this->assertStringContainsString('模型暂不可用', $monitor->message);
    }

    public function missingDisabledDetails(): array
    {
        return [
            [null], [''], ['invalid-json'], ['null'], ['123'],
            [['status_reason' => ['unexpected'], 'status_time' => -1]],
            [['status_reason' => '', 'status_time' => 'not-a-time']],
            [['status_time' => '99999999999999999999999999']],
            [['status_time' => ['unexpected']]],
        ];
    }

    /** @dataProvider missingDisabledDetails */
    public function test_missing_or_malformed_disable_details_do_not_prevent_monitoring($details): void
    {
        $this->seedChannel(1);
        $this->apiChannels[1]['other_info'] = $details;
        $this->onTest = fn () => Http::response(['success' => false, 'message' => ['unexpected']]);

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['failed']);

        $monitor = ChannelMonitorLog::sole();
        $this->assertSame('failed', $monitor->result);
        $this->assertNull($monitor->disabled_reason);
        $this->assertNull($monitor->disabled_at);
        $this->assertSame('上游未提供失败原因。', $monitor->message);
    }

    public function test_monitor_messages_redact_credentials_without_discarding_failure_reason(): void
    {
        $this->seedChannel(1);
        $this->apiChannels[1]['key'] = "opaque-channel-secret\nsecond-channel-secret";
        $message = '余额不足 private-admin-token opaque-channel-secret second-channel-secret '
            . 'sk-upstream-secret Bearer bearer-secret api_key=labelled-secret '
            . 'https://user:password@upstream.example/api?token=query-secret';
        $this->apiChannels[1]['other_info'] = ['status_reason' => $message];
        $this->onTest = fn () => Http::response(['success' => false, 'error' => ['message' => $message]]);

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['failed']);

        $monitor = ChannelMonitorLog::sole();
        foreach ([$monitor->disabled_reason, $monitor->message] as $text) {
            $this->assertStringContainsString('余额不足', $text);
            $this->assertStringContainsString('[已隐藏]', $text);
            foreach (['private-admin-token', 'opaque-channel-secret', 'second-channel-secret', 'sk-upstream-secret', 'bearer-secret', 'labelled-secret', 'user:password', 'query-secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
        }
    }

    public function test_long_upstream_messages_are_bounded_and_raw_response_fields_are_not_saved(): void
    {
        $this->seedChannel(1);
        $this->apiChannels[1]['other_info'] = ['status_reason' => str_repeat('禁用原因', 2000)];
        $this->onTest = fn () => Http::response([
            'success' => false, 'message' => str_repeat('请求失败', 2000), 'key' => 'do-not-save-response',
        ]);

        $this->assertSame(1, app(ChannelRecoveryChecker::class)->run()['failed']);

        $monitor = ChannelMonitorLog::sole();
        $this->assertLessThanOrEqual(4000, mb_strwidth($monitor->disabled_reason));
        $this->assertLessThanOrEqual(4000, mb_strwidth($monitor->message));
        $this->assertStringNotContainsString('do-not-save-response', $monitor->toJson());
    }

    public function test_monitor_storage_failure_prevents_unlogged_testing_or_recovery(): void
    {
        $this->seedChannel(1);
        Schema::connection('alerts')->drop('channel_monitor_logs');

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['checked']);
        $this->assertSame(0, $stats['recovered']);
        Http::assertSentCount(1);
    }

    public function test_monitor_completion_failure_keeps_snapshot_pending_and_does_not_enable(): void
    {
        $this->seedChannel(1);
        $this->apiChannels[1]['other_info'] = ['status_reason' => '上游服务不可用'];
        DB::connection('alerts')->statement("CREATE TRIGGER deny_monitor_completion BEFORE UPDATE ON channel_monitor_logs BEGIN SELECT RAISE(FAIL, 'test monitor write failure'); END");

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['recovered']);
        $this->assertSame('pending', ChannelMonitorLog::sole()->result);
        $this->assertSame('上游服务不可用', ChannelMonitorLog::sole()->disabled_reason);
        $this->assertNull(ChannelMonitorLog::sole()->completed_at);
        $this->assertSame(0, ChannelRecoveryLog::count());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_last_run_records_read_failures_that_produce_no_monitor_log(): void
    {
        $this->seedChannel(1);
        $this->seedChannel(2);
        unset($this->apiChannels[2]);
        $this->onTest = fn () => Http::response(['success' => false, 'message' => 'quota exhausted']);

        app(ChannelRecoveryChecker::class)->run(false, null, false);

        $run = ChannelRecoveryRun::current();
        $this->assertSame('manual', $run->source);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error);
        $this->assertSame([1, 0, 0, 0, 2], [$run->checked, $run->healthy, $run->recovered, $run->skipped, $run->failed]);
        $this->assertSame([
            ['channel_id' => 1, 'stage' => 'test', 'message' => 'quota exhausted'],
            ['channel_id' => 2, 'stage' => 'read', 'message' => 'NewAPI 渠道详情格式无效。'],
        ], $run->failures);
        $this->assertTrue($run->hasProblems());
        $this->assertSame([1], ChannelMonitorLog::pluck('channel_id')->all());
    }

    public function test_last_run_records_configuration_error_and_is_replaced_by_next_run(): void
    {
        $this->seedChannel(1);
        config(['channels.base_url' => '']);

        $this->artisan('channels:recover')->assertExitCode(1);

        $run = ChannelRecoveryRun::current();
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('NEW_API_BASE_URL', $run->error);
        Http::assertNothingSent();

        config(['channels.base_url' => 'https://newapi.example']);
        Carbon::setTestNow('2026-10-02 10:07:00');
        app(ChannelRecoveryChecker::class)->run(false, null, true);

        $run = ChannelRecoveryRun::current();
        $this->assertSame(1, ChannelRecoveryRun::count());
        $this->assertSame('scheduled', $run->source);
        $this->assertNull($run->error);
        $this->assertNull($run->failures);
        $this->assertSame(1, $run->recovered);
        $this->assertFalse($run->hasProblems());
    }

    public function test_last_run_is_not_touched_by_disabled_undue_or_overlapping_calls(): void
    {
        $this->seedChannel(1);
        Carbon::setTestNow('2026-10-02 10:01:00');
        app(ChannelRecoveryChecker::class)->run(false, null, true);
        $this->assertNull(ChannelRecoveryRun::current());

        $lock = Cache::lock('channels:recover', 86400);
        $this->assertTrue($lock->get());
        $this->artisan('channels:recover')->assertExitCode(1);
        $lock->release();
        $this->assertNull(ChannelRecoveryRun::current());

        config(['channels.recovery_enabled' => false]);
        app(ChannelRecoveryChecker::class)->run();
        $this->assertNull(ChannelRecoveryRun::current());
    }

    public function test_run_status_write_failure_does_not_block_recovery(): void
    {
        $this->seedChannel(1);
        Schema::connection('alerts')->drop('channel_recovery_runs');

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['recovered']);
        $this->assertSame(1, $this->apiChannels[1]['status']);
    }

    public function test_timeline_sample_failure_is_visible_and_does_not_block_recovery(): void
    {
        $this->seedChannel(1);
        Schema::connection('alerts')->drop('channel_status_samples');

        $stats = app(ChannelRecoveryChecker::class)->run();

        $this->assertSame(1, $stats['recovered']);
        $this->assertSame('recovered', ChannelRecoveryLog::sole()->result);
        $this->assertStringContainsString('渠道状态时间轴采集失败', ChannelRecoveryRun::current()->error);
    }

    private function seedChannel(int $id, int $status = 3, ?int $autoBan = 1): void
    {
        $channel = ['id' => $id, 'status' => $status, 'auto_ban' => $autoBan];
        DB::connection('channel_recovery_test')->table('channels')->insert($channel);
        $this->apiChannels[$id] = array_merge($channel, ['name' => '测试渠道 ' . $id]);
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
