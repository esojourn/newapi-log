<?php

namespace Tests\Feature;

use App\Models\ChannelMonitorLog;
use App\Models\ChannelRecoveryLog;
use App\Models\ChannelRecoveryRun;
use App\Models\ChannelRecoverySetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelRecoverySettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'channels.recovery_enabled' => false,
            'channels.schedule_cron' => '*/5 * * * *',
            'channels.base_url' => 'https://newapi.example',
            'channels.access_token' => 'secret-env-token',
            'channels.user_id' => 42,
            'channels.http_timeout' => 30,
        ]);
        Artisan::call('migrate', [
            '--database' => 'alerts',
            '--path' => 'database/migrations/alerts',
            '--force' => true,
        ]);
        Http::fake();
    }

    public function test_guests_cannot_read_settings_or_logs_or_save(): void
    {
        $this->get('/admin/channel-recovery')->assertRedirect('/admin/login');
        $this->post('/admin/channel-recovery', $this->form())->assertRedirect('/admin/login');

        $this->assertSame(0, ChannelRecoverySetting::count());
        Http::assertNothingSent();
    }

    public function test_normal_api_key_login_does_not_grant_admin_access(): void
    {
        $this->withSession(['user_api_key' => 'sk-user-token', 'user_token_name' => 'normal-user']);

        $this->get('/admin/channel-recovery?result=recovered')->assertRedirect('/admin/login');
        $this->post('/admin/channel-recovery', $this->form())->assertRedirect('/admin/login');

        $this->assertSame(0, ChannelRecoverySetting::count());
    }

    public function test_admin_page_uses_env_defaults_without_exposing_secret(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery');

        $response->assertOk()->assertSee('恢复设置')->assertSee('恢复动作日志')->assertSee('监控日志')
            ->assertSee('https://newapi.example')->assertSee('*/5 * * * *')
            ->assertSee('已配置，留空保留原令牌')->assertDontSee('secret-env-token', false);
        $this->assertArrayNotHasKey('access_token', $response->viewData('settings'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(0, ChannelRecoverySetting::count());
        Http::assertNothingSent();
    }

    public function test_admin_can_save_settings_with_encrypted_token(): void
    {
        $this->withSession(['admin_authenticated' => true])
            ->post('/admin/channel-recovery', $this->form(['access_token' => 'new-private-token']))
            ->assertRedirect('/admin/channel-recovery')->assertSessionHasNoErrors();

        $settings = ChannelRecoverySetting::current();
        $this->assertTrue($settings->enabled);
        $this->assertSame('*/10 * * * *', $settings->schedule_cron);
        $this->assertSame(60, $settings->http_timeout);
        $this->assertSame(99, $settings->user_id);
        $this->assertSame('https://newapi.example', $settings->base_url);
        $this->assertSame('new-private-token', $settings->access_token);
        $ciphertext = DB::connection('alerts')->table('channel_recovery_settings')->value('access_token');
        $this->assertNotSame('new-private-token', $ciphertext);
        $this->get('/admin/channel-recovery')->assertOk()->assertDontSee('new-private-token', false)->assertDontSee($ciphertext, false);
        Http::assertNothingSent();
    }

    public function test_blank_token_retains_saved_token_and_first_save_inherits_env_token(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        $this->post('/admin/channel-recovery', $this->form())->assertSessionHasNoErrors();
        $this->assertSame('secret-env-token', ChannelRecoverySetting::current()->access_token);

        config(['channels.access_token' => 'different-env-token']);
        $this->post('/admin/channel-recovery', $this->form(['enabled' => '0']))->assertSessionHasNoErrors();
        $settings = ChannelRecoverySetting::current();
        $this->assertFalse($settings->enabled);
        $this->assertSame('secret-env-token', $settings->access_token);
        $this->assertSame(1, ChannelRecoverySetting::count());
    }

    public function test_cleared_token_does_not_fall_back_to_environment(): void
    {
        $this->withSession(['admin_authenticated' => true])
            ->post('/admin/channel-recovery', $this->form(['enabled' => '0', 'clear_access_token' => '1']))
            ->assertSessionHasNoErrors();

        $settings = ChannelRecoverySetting::current();
        $this->assertFalse($settings->enabled);
        $this->assertNull($settings->access_token);
        $this->assertNull($settings->configuration()['access_token']);
        $this->post('/admin/channel-recovery', $this->form())->assertSessionHasErrors('enabled');
        $this->assertFalse(ChannelRecoverySetting::current()->enabled);
    }

    public function invalidSettings(): array
    {
        return [
            'cron' => ['schedule_cron', 'not-a-cron'],
            'empty cron' => ['schedule_cron', ''],
            'array cron' => ['schedule_cron', ['*/5 * * * *']],
            'array url' => ['base_url', ['https://example.com']],
            'url protocol' => ['base_url', 'ftp://example.com'],
            'url credentials' => ['base_url', 'https://user:pass@example.com'],
            'url query' => ['base_url', 'https://example.com?token=foo'],
            'url fragment' => ['base_url', 'https://example.com/#foo'],
            'timeout zero' => ['http_timeout', '0'],
            'timeout too long' => ['http_timeout', '301'],
            'user id' => ['user_id', '-1'],
            'token newline' => ['access_token', "abc\r\ndef"],
        ];
    }

    /** @dataProvider invalidSettings */
    public function test_invalid_settings_are_rejected_without_flashing_token(string $field, $value): void
    {
        $this->withSession(['admin_authenticated' => true])->from('/admin/channel-recovery')
            ->post('/admin/channel-recovery', $this->form(array_merge(['access_token' => 'do-not-flash'], [$field => $value])))
            ->assertSessionHasErrors($field)->assertSessionMissing('_old_input.access_token');

        $this->assertSame(0, ChannelRecoverySetting::count());
        Http::assertNothingSent();
    }

    public function test_enabling_requires_complete_credentials_without_flashing_token(): void
    {
        $this->withSession(['admin_authenticated' => true])->from('/admin/channel-recovery')
            ->post('/admin/channel-recovery', $this->form(['base_url' => '', 'access_token' => 'do-not-flash']))
            ->assertSessionHasErrors('enabled')->assertSessionMissing('_old_input.access_token');

        $this->assertSame(0, ChannelRecoverySetting::count());
    }

    public function test_unreadable_saved_token_is_visible_as_error_and_cannot_enable(): void
    {
        $settings = ChannelRecoverySetting::current();
        $settings->save();
        DB::connection('alerts')->table('channel_recovery_settings')->update(['access_token' => 'invalid-ciphertext']);

        $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery')
            ->assertOk()->assertSee('已保存的访问令牌无法读取')->assertDontSee('invalid-ciphertext')->assertDontSee('secret-env-token');
        $this->post('/admin/channel-recovery', $this->form())->assertSessionHasErrors('enabled');
        $this->assertFalse(ChannelRecoverySetting::current()->enabled);

        $this->post('/admin/channel-recovery', $this->form(['access_token' => 'replacement-token']))->assertSessionHasNoErrors();
        $this->assertSame('replacement-token', ChannelRecoverySetting::current()->access_token);
    }

    public function test_logs_are_paginated_newest_first_and_html_is_escaped(): void
    {
        for ($id = 1; $id <= 27; $id++) {
            $this->log(['channel_id' => $id, 'channel_name' => $id === 27 ? '<script>alert(1)</script>' : 'channel-' . $id]);
        }

        $response = $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery');

        $response->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame(27, $response->viewData('logs')->total());
        $this->assertCount(25, $response->viewData('logs'));
        $this->assertSame(27, $response->viewData('logs')->first()->channel_id);

        $second = $this->get('/admin/channel-recovery?page=2')->assertOk();
        $this->assertCount(2, $second->viewData('logs'));
        $this->assertSame(2, $second->viewData('logs')->first()->channel_id);
    }

    public function test_logs_can_be_filtered_by_channel_and_result(): void
    {
        $this->log(['channel_id' => 7]);
        $this->log(['channel_id' => 7, 'result' => 'failed']);
        $this->log(['channel_id' => 8]);

        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin/channel-recovery?channel_id=7&result=recovered')->assertOk();

        $this->assertSame(1, $response->viewData('logs')->total());
        $this->assertSame(7, $response->viewData('logs')->first()->channel_id);
        $this->assertStringContainsString('channel_id=7', $response->viewData('logs')->url(2));
        $this->assertStringContainsString('result=recovered', $response->viewData('logs')->url(2));
    }

    public function test_compact_logs_preserve_full_multiline_text_and_escape_tooltip_attributes(): void
    {
        $message = str_repeat('重复错误信息 ', 80) . "\n最后一行：\"<script>alert(1)</script>";
        $this->monitorLog(['disabled_reason' => $message, 'message' => $message]);
        $this->log(['message' => $message]);

        $response = $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery');

        $response->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $html = $response->getContent();
        $this->assertSame(3, substr_count($html, 'title="' . e($message) . '"'));
        $this->assertSame(3, substr_count($html, '<span class="alz-log-preview">' . e($message) . '</span>'));
    }

    public function test_monitor_logs_are_paginated_independently_and_upstream_text_is_escaped(): void
    {
        for ($id = 1; $id <= 27; $id++) {
            $this->monitorLog(['channel_id' => $id]);
        }
        $this->monitorLog([
            'channel_id' => 28,
            'channel_name' => '<script>name()</script>',
            'disabled_reason' => '<script>reason()</script>',
            'message' => '<img src=x onerror=alert(1)>',
            'dry_run' => true,
        ]);
        $this->log();

        $response = $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery');
        $response->assertOk()->assertSee('自动禁用原因')->assertSee('本次检测提示')->assertSee('试运行')
            ->assertSee('&lt;script&gt;reason()&lt;/script&gt;', false)
            ->assertDontSee('<script>reason()</script>', false)->assertDontSee('<script>name()</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
        $this->assertSame(28, $response->viewData('monitorLogs')->total());
        $this->assertCount(25, $response->viewData('monitorLogs'));
        $this->assertSame(28, $response->viewData('monitorLogs')->first()->channel_id);

        $second = $this->get('/admin/channel-recovery?monitor_page=2')->assertOk();
        $this->assertCount(3, $second->viewData('monitorLogs'));
        $this->assertSame(3, $second->viewData('monitorLogs')->first()->channel_id);
        $this->assertCount(1, $second->viewData('logs'));
    }

    public function test_monitor_logs_can_be_filtered_by_channel_and_detection_result(): void
    {
        $this->monitorLog(['channel_id' => 7]);
        $this->monitorLog(['channel_id' => 7, 'result' => 'healthy']);
        $this->monitorLog(['channel_id' => 8]);

        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin/channel-recovery?monitor_channel_id=7&monitor_result=failed')->assertOk();

        $this->assertSame(1, $response->viewData('monitorLogs')->total());
        $this->assertSame(7, $response->viewData('monitorLogs')->first()->channel_id);
        $this->assertStringContainsString('monitor_channel_id=7', $response->viewData('monitorLogs')->url(2));
        $this->assertStringContainsString('monitor_result=failed', $response->viewData('monitorLogs')->url(2));
        $this->assertStringContainsString('monitor_page=2', $response->viewData('monitorLogs')->url(2));
        $this->get('/admin/channel-recovery?monitor_result=invalid')->assertSessionHasErrors('monitor_result');
        $this->get('/admin/channel-recovery?monitor_channel_id=-1')->assertSessionHasErrors('monitor_channel_id');
    }

    public function test_admin_page_shows_last_run_failures(): void
    {
        $page = fn () => $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery');
        $page()->assertOk()->assertSee('尚无检查记录');

        ChannelRecoveryRun::create([
            'id' => 1, 'source' => 'scheduled', 'started_at' => now(), 'finished_at' => now(), 'failed' => 1,
            'failures' => [['channel_id' => 59, 'stage' => 'read', 'message' => 'NewAPI 管理接口请求失败，HTTP 404 <b>']],
        ]);
        $page()->assertOk()->assertSee('最近一轮检查')->assertSee('失败 1')
            ->assertSee('#59 · 读取渠道详情：NewAPI 管理接口请求失败，HTTP 404 &lt;b&gt;', false);

        ChannelRecoveryRun::current()->update(['error' => '请配置 NEW_API_BASE_URL', 'failures' => null]);
        $page()->assertSee('本轮未能完成：请配置 NEW_API_BASE_URL');

        ChannelRecoveryRun::current()->update(['error' => null, 'finished_at' => null, 'started_at' => now()->subHours(2)]);
        $page()->assertSee('未完成，进程可能已中断');
    }

    public function test_monitor_logs_are_visible_only_to_admins(): void
    {
        $this->monitorLog(['disabled_reason' => '管理员专用禁用原因']);
        $this->get('/admin/channel-recovery?monitor_result=failed')->assertRedirect('/admin/login')
            ->assertDontSee('管理员专用禁用原因');
        $this->withSession(['user_api_key' => 'sk-user-token'])
            ->get('/admin/channel-recovery?monitor_result=failed')->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery')
            ->assertOk()->assertSee('管理员专用禁用原因');
    }

    private function monitorLog(array $overrides = []): ChannelMonitorLog
    {
        return ChannelMonitorLog::create(array_merge([
            'channel_id' => 1,
            'channel_name' => '测试渠道',
            'source' => 'scheduled',
            'disabled_reason' => '上游余额不足',
            'disabled_at' => now()->subMinutes(5),
            'result' => 'failed',
            'message' => '账户额度已耗尽，请充值',
            'completed_at' => now(),
        ], $overrides));
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'enabled' => '1',
            'schedule_cron' => '*/10 * * * *',
            'base_url' => 'https://newapi.example/',
            'access_token' => '',
            'user_id' => '99',
            'http_timeout' => '60',
        ], $overrides);
    }

    private function log(array $overrides = []): ChannelRecoveryLog
    {
        return ChannelRecoveryLog::create(array_merge([
            'channel_id' => 1,
            'channel_name' => '测试渠道',
            'source' => 'scheduled',
            'from_status' => 3,
            'target_status' => 1,
            'result' => 'recovered',
            'message' => '测试正常，已确认从自动禁用恢复为启用。',
            'completed_at' => now(),
        ], $overrides));
    }
}
