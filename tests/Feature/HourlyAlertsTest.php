<?php

namespace Tests\Feature;

use App\Models\AlertAdminWatch;
use App\Models\AlertSetting;
use App\Services\AlertChecker;
use App\Support\Quota;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HourlyAlertsTest extends TestCase
{
    private const HOOK = 'https://open.feishu.cn/open-apis/bot/v2/hook/hourly-test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'app.timezone' => 'Asia/Shanghai',
            'app.url' => 'https://private-admin.example',
            'alerts.remind_hours' => 24,
            'database.connections.alerts.database' => ':memory:',
            'database.connections.hourly_alert_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('alerts');
        DB::setDefaultConnection('hourly_alert_test');
        Carbon::setTestNow(Carbon::parse('2026-10-14 13:30:00', 'Asia/Shanghai'));

        Artisan::call('migrate', [
            '--database' => 'alerts',
            '--path' => 'database/migrations/alerts',
            '--force' => true,
        ]);

        // 仅在独立内存连接造数据，不迁移或写入外部 NewAPI 库。
        Schema::connection('hourly_alert_test')->create('tokens', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
            $table->bigInteger('remain_quota');
            $table->boolean('unlimited_quota')->default(false);
            $table->integer('status')->default(1);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::connection('hourly_alert_test')->create('logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('token_id');
            $table->string('token_name');
            $table->integer('type');
            $table->bigInteger('created_at');
            $table->bigInteger('quota');
        });

        $this->token(1);
        $this->token(2);
        AlertSetting::setValue(AlertSetting::ADMIN_ENABLED, '1');
        AlertSetting::setValue(AlertSetting::ADMIN_WEBHOOK_URL, self::HOOK);
        AlertSetting::setValue(AlertSetting::ADMIN_WEBHOOK_SECRET, 'test-secret');
        $this->fakeFeishu();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('hourly_alert_test');

        parent::tearDown();
    }

    public function test_sums_previous_complete_hour_by_token_id_and_only_consumption_logs(): void
    {
        $watch = $this->watch();
        $this->log('2026-10-14 11:59:59', 100);
        $this->log('2026-10-14 12:00:00', 2.5);
        $this->log('2026-10-14 12:59:59', 3);
        $this->log('2026-10-14 13:00:00', 100);
        $this->log('2026-10-14 12:30:00', 100, 1, 1);
        $this->log('2026-10-14 12:30:00', 100, 1, 5);
        $this->log('2026-10-14 12:30:00', 100, 2);

        $stats = app(AlertChecker::class)->run();

        $this->assertSame(1, $stats['admin_hourly_sent']);
        $this->assertSame(0, $stats['admin_sent']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $request->url() === self::HOOK
                && $request['msg_type'] === 'interactive'
                && isset($request['sign'])
                && strpos($body, '余额预警') !== false
                && strpos($body, '每小时超限') !== false
                && strpos($body, 'demo-1') !== false
                && strpos($body, 'old-name') === false
                && strpos($body, '$5.5000') !== false
                && strpos($body, '$5.0000') !== false
                && strpos($body, '2026-10-14 12:00 至 2026-10-14 13:00') !== false
                && strpos($body, 'Asia/Shanghai') !== false
                && strpos($body, 'private-admin.example') === false;
        });

        $watch = $watch->fresh();
        $this->assertSame(Quota::fromAmount(5.5), $watch->last_hourly_notified_quota);
        $this->assertSame($this->time('2026-10-14 12:00:00')->timestamp, $watch->last_hourly_notified_hour);
        $this->assertNotNull($watch->last_hourly_notified_at);
        $this->assertNull($watch->last_notified_at);
    }

    /** @dataProvider spendingAmounts */
    public function test_sends_only_when_spending_is_strictly_greater_than_limit(?float $amount, float $limit, int $sent): void
    {
        $this->watch(['hourly_threshold_quota' => Quota::fromAmount($limit)]);
        if ($amount !== null) {
            $this->log('2026-10-14 12:30:00', $amount);
        }

        $this->assertSame($sent, app(AlertChecker::class)->run()['admin_hourly_sent']);
        Http::assertSentCount($sent);
    }

    public function spendingAmounts(): array
    {
        return [
            'no usage' => [null, 5, 0],
            'below limit' => [4.99, 5, 0],
            'equal to limit' => [5, 5, 0],
            'one quota over limit' => [5.000002, 5, 1],
            'zero limit without consumption' => [0, 0, 0],
            'zero limit with consumption' => [0.01, 0, 1],
        ];
    }

    public function test_merges_exceeded_keys_and_includes_unlimited_keys(): void
    {
        $this->watch();
        $this->watch(['token_id' => 2, 'token_name' => 'demo-2']);
        DB::table('tokens')->where('id', 2)->update(['unlimited_quota' => true, 'remain_quota' => 0]);
        $this->log('2026-10-14 12:30:00', 6);
        $this->log('2026-10-14 12:30:00', 7, 2);

        $this->assertSame(2, app(AlertChecker::class)->run()['admin_hourly_sent']);
        Http::assertSentCount(1);
        $this->assertNotNull(AlertAdminWatch::where('token_id', 2)->first()->last_hourly_notified_at);
    }

    public function test_dedupes_same_hour_but_sends_again_for_next_hour(): void
    {
        $this->watch();
        $this->log('2026-10-14 12:30:00', 6);
        $this->log('2026-10-14 13:30:00', 7);

        $this->assertSame(1, app(AlertChecker::class)->run()['admin_hourly_sent']);
        Carbon::setTestNow($this->time('2026-10-14 13:59:59'));
        $this->assertSame(0, app(AlertChecker::class)->run()['admin_hourly_sent']);
        Carbon::setTestNow($this->time('2026-10-14 14:00:00'));
        $this->assertSame(1, app(AlertChecker::class)->run()['admin_hourly_sent']);
        Http::assertSentCount(2);
    }

    public function test_previous_hour_can_cross_midnight(): void
    {
        Carbon::setTestNow($this->time('2026-10-14 00:00:00'));
        $watch = $this->watch();
        $this->log('2026-10-13 23:00:00', 6);
        $this->log('2026-10-14 00:00:00', 100);

        $this->assertSame(1, app(AlertChecker::class)->run()['admin_hourly_sent']);
        $this->assertSame(Quota::fromAmount(6), $watch->fresh()->last_hourly_notified_quota);
        $this->assertSame($this->time('2026-10-13 23:00:00')->timestamp, $watch->fresh()->last_hourly_notified_hour);
    }

    public function test_dry_run_does_not_send_or_modify_hourly_state(): void
    {
        $watch = $this->watch(['last_hourly_error' => 'old failure', 'last_hourly_error_at' => Carbon::now()]);
        $this->log('2026-10-14 12:30:00', 6);
        $before = $watch->fresh()->getAttributes();

        $this->assertSame(1, app(AlertChecker::class)->run(true)['admin_hourly_sent']);
        Http::assertNothingSent();
        $this->assertSame($before, $watch->fresh()->getAttributes());
    }

    public function test_failed_delivery_records_error_and_can_retry_same_hour(): void
    {
        $watch = $this->watch();
        $this->log('2026-10-14 12:30:00', 6);
        Http::swap(new Factory());
        Http::fake([self::HOOK => Http::sequence()
            ->push(['code' => 1, 'msg' => 'retry later'])
            ->push(['code' => 0, 'msg' => 'success'])]);

        $stats = app(AlertChecker::class)->run();
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['admin_hourly_sent']);
        $this->assertNull($watch->fresh()->last_hourly_notified_hour);
        $this->assertStringContainsString('retry later', $watch->fresh()->last_hourly_error);
        $this->assertNotNull($watch->fresh()->last_hourly_error_at);
        $this->assertNull($watch->fresh()->last_error);

        $this->assertSame(1, app(AlertChecker::class)->run()['admin_hourly_sent']);
        $this->assertNull($watch->fresh()->last_hourly_error);
        $this->assertNull($watch->fresh()->last_hourly_error_at);
        Http::assertSentCount(2);
    }

    public function test_hourly_alerts_are_independent_of_balance_reminder_state(): void
    {
        $watch = $this->watch(['threshold_quota' => Quota::fromAmount(5)]);
        DB::table('tokens')->where('id', 1)->update(['remain_quota' => Quota::fromAmount(1)]);
        $this->log('2026-10-14 12:30:00', 6);
        $this->log('2026-10-14 13:30:00', 7);

        $stats = app(AlertChecker::class)->run();
        $this->assertSame(1, $stats['admin_sent']);
        $this->assertSame(1, $stats['admin_hourly_sent']);
        $balanceNotifiedAt = $watch->fresh()->last_notified_at->timestamp;

        Carbon::setTestNow($this->time('2026-10-14 14:00:00'));
        $stats = app(AlertChecker::class)->run();
        $this->assertSame(0, $stats['admin_sent']);
        $this->assertSame(1, $stats['admin_hourly_sent']);
        $this->assertSame($balanceNotifiedAt, $watch->fresh()->last_notified_at->timestamp);
        Http::assertSentCount(3);
    }

    public function test_hourly_query_failure_does_not_prevent_balance_delivery(): void
    {
        $watch = $this->watch(['threshold_quota' => Quota::fromAmount(200)]);
        Schema::connection('hourly_alert_test')->drop('logs');

        $stats = app(AlertChecker::class)->run();
        $this->assertSame(1, $stats['admin_sent']);
        $this->assertSame(0, $stats['admin_hourly_sent']);
        $this->assertSame(1, $stats['failed']);
        $this->assertNotNull($watch->fresh()->last_hourly_error);
        $this->assertNull($watch->fresh()->last_hourly_notified_hour);
        Http::assertSentCount(1);
    }

    public function test_disabled_hourly_alert_does_not_query_logs(): void
    {
        $this->watch(['hourly_alert_enabled' => false]);
        Schema::connection('hourly_alert_test')->drop('logs');

        $stats = app(AlertChecker::class)->run();
        $this->assertSame(0, $stats['admin_hourly_sent']);
        $this->assertSame(0, $stats['failed']);
        Http::assertNothingSent();
    }

    public function test_hourly_alert_requires_admin_enabled_and_webhook(): void
    {
        $this->watch();
        $this->log('2026-10-14 12:30:00', 6);
        AlertSetting::setValue(AlertSetting::ADMIN_ENABLED, '');
        $this->assertSame(0, app(AlertChecker::class)->run()['admin_hourly_sent']);
        AlertSetting::setValue(AlertSetting::ADMIN_ENABLED, '1');
        AlertSetting::setValue(AlertSetting::ADMIN_WEBHOOK_URL, null);
        $this->assertSame(0, app(AlertChecker::class)->run()['admin_hourly_sent']);
        Http::assertNothingSent();
    }

    public function test_deleted_token_does_not_send_and_dry_run_does_not_write_error(): void
    {
        $watch = $this->watch();
        $this->log('2026-10-14 12:30:00', 6);
        DB::table('tokens')->where('id', 1)->update(['deleted_at' => Carbon::now()]);

        $this->assertSame(0, app(AlertChecker::class)->run(true)['admin_hourly_sent']);
        $this->assertNull($watch->fresh()->last_hourly_error);
        $this->assertSame(0, app(AlertChecker::class)->run()['admin_hourly_sent']);
        $this->assertNotNull($watch->fresh()->last_hourly_error);
        Http::assertNothingSent();
    }

    public function test_token_filter_only_checks_requested_key(): void
    {
        $first = $this->watch();
        $this->watch(['token_id' => 2, 'token_name' => 'demo-2']);
        $this->log('2026-10-14 12:30:00', 6);
        $this->log('2026-10-14 12:30:00', 7, 2);

        $this->assertSame(1, app(AlertChecker::class)->run(false, 2)['admin_hourly_sent']);
        $this->assertNull($first->fresh()->last_hourly_notified_at);
        Http::assertSentCount(1);
    }

    public function test_guest_cannot_save_hourly_settings(): void
    {
        $this->post('/admin/alerts/watches', $this->form())->assertRedirect(route('admin.login'));
        $this->assertSame(0, AlertAdminWatch::count());
    }

    public function test_saves_hourly_settings_and_preserves_notification_history_and_other_pages(): void
    {
        $hour = $this->time('2026-10-14 12:00:00')->timestamp;
        $watch = $this->watch([
            'last_hourly_notified_hour' => $hour,
            'last_hourly_notified_at' => Carbon::now(),
            'last_hourly_notified_quota' => Quota::fromAmount(6),
        ]);
        $this->watch(['token_id' => 3, 'token_name' => 'other-page']);

        $this->withSession(['admin_authenticated' => true])->post('/admin/alerts/watches', $this->form([
            'hourly_threshold' => [1 => '12.50'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $watch = $watch->fresh();
        $this->assertTrue($watch->hourly_alert_enabled);
        $this->assertSame(Quota::fromAmount(12.5), $watch->hourly_threshold_quota);
        $this->assertSame($hour, $watch->last_hourly_notified_hour);
        $this->assertSame(Quota::fromAmount(6), $watch->last_hourly_notified_quota);
        $this->assertSame(2, AlertAdminWatch::count());
        $this->assertSame(Quota::fromAmount(5), AlertAdminWatch::where('token_id', 3)->first()->hourly_threshold_quota);
    }

    public function test_can_disable_hourly_alert_while_retaining_entered_limit(): void
    {
        $watch = $this->watch();
        $this->withSession(['admin_authenticated' => true])->post('/admin/alerts/watches', $this->form([
            'hourly_enabled' => [],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($watch->fresh()->hourly_alert_enabled);
        $this->assertSame(Quota::fromAmount(5), $watch->fresh()->hourly_threshold_quota);
    }

    public function test_page_selection_save_preserves_existing_hourly_settings_and_deduplication(): void
    {
        $watch = $this->watch();
        $this->log('2026-10-14 12:30:00', 6);
        app(AlertChecker::class)->run();

        $this->withSession(['admin_authenticated' => true])->post('/admin/alerts/watches', [
            'page_token_ids' => [1, 2],
            'watch' => [1, 2],
            'threshold' => [1 => '10'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($watch->fresh()->hourly_alert_enabled);
        $this->assertSame(Quota::fromAmount(5), $watch->fresh()->hourly_threshold_quota);
        $this->assertFalse(AlertAdminWatch::where('token_id', 2)->first()->hourly_alert_enabled);
        $this->assertSame(0, app(AlertChecker::class)->run()['admin_hourly_sent']);
        Http::assertSentCount(1);
    }

    /** @dataProvider invalidLimits */
    public function test_rejects_invalid_hourly_limit_before_changing_any_watches($limit): void
    {
        $watch = $this->watch();
        $this->watch(['token_id' => 2, 'token_name' => 'demo-2']);

        $this->withSession(['admin_authenticated' => true])->post('/admin/alerts/watches', $this->form([
            'page_token_ids' => [1, 2],
            'hourly_threshold' => [1 => $limit],
        ]))->assertSessionHasErrors('hourly_threshold.1');

        $this->assertSame(Quota::fromAmount(5), $watch->fresh()->hourly_threshold_quota);
        $this->assertSame(2, AlertAdminWatch::count());
    }

    public function invalidLimits(): array
    {
        return [
            'missing' => [null],
            'blank' => [''],
            'negative' => ['-1'],
            'too large' => ['100001'],
            'not a number' => ['abc'],
        ];
    }

    public function test_hourly_settings_render_and_validation_preserves_unchecked_input(): void
    {
        $this->watch(['hourly_threshold_quota' => Quota::fromAmount(12.5)]);
        $this->watch(['token_id' => 2, 'token_name' => 'demo-2']);
        $this->withSession(['admin_authenticated' => true])->get('/admin/alerts')
            ->assertOk()->assertSee('每小时超限预警')->assertSee('value="12.5"', false);

        $this->from('/admin/alerts')->post('/admin/alerts/watches', $this->form([
            'page_token_ids' => [1, 2],
            'watch' => [1, 2],
            'hourly_enabled' => [2],
            'hourly_threshold' => [1 => '7.5', 2 => ''],
        ]))->assertSessionHasErrors('hourly_threshold.2');

        $response = $this->get('/admin/alerts')->assertOk();
        $document = new DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $first = $xpath->query('//input[@name="hourly_enabled[]" and @value="1"]')->item(0);
        $second = $xpath->query('//input[@name="hourly_enabled[]" and @value="2"]')->item(0);
        $this->assertFalse($first->hasAttribute('checked'));
        $this->assertTrue($second->hasAttribute('checked'));
        $this->assertSame('7.5', $xpath->query('//input[@id="hourly_threshold_1"]')->item(0)->getAttribute('value'));
    }

    public function test_command_reports_hourly_alerts_without_sending_in_dry_run(): void
    {
        $watch = $this->watch();
        $this->log('2026-10-14 12:30:00', 6);

        $this->artisan('alerts:check', ['--dry-run' => true, '--token-id' => 1])
            ->expectsTable(
                ['检查', '用户推送', '管理员余额推送', '每小时超限推送', '跳过', '失败'],
                [[2, 0, 0, 1, 1, 0]]
            )->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertNull($watch->fresh()->last_hourly_notified_hour);
    }

    public function test_hourly_migration_can_rollback_and_reapply_without_losing_balance_settings(): void
    {
        $watch = $this->watch([
            'threshold_quota' => Quota::fromAmount(10),
            'last_notified_at' => Carbon::now(),
            'last_notified_quota' => Quota::fromAmount(1),
        ]);
        $migration = new \AddHourlyAlertsToAdminWatches();
        $migration->down();

        $this->assertFalse(Schema::connection('alerts')->hasColumn('alert_admin_watches', 'hourly_alert_enabled'));
        $balance = DB::connection('alerts')->table('alert_admin_watches')->where('id', $watch->id)->first();
        $this->assertSame(Quota::fromAmount(10), $balance->threshold_quota);
        $this->assertSame(Quota::fromAmount(1), $balance->last_notified_quota);
        $this->assertSame(Carbon::now()->format('Y-m-d H:i:s'), $balance->last_notified_at);

        $migration->up();
        $this->assertFalse($watch->fresh()->hourly_alert_enabled);
        $this->assertNull($watch->fresh()->hourly_threshold_quota);
        $this->assertSame(Quota::fromAmount(10), $watch->fresh()->threshold_quota);
    }

    private function fakeFeishu(): void
    {
        Http::fake([self::HOOK => Http::response(['code' => 0, 'msg' => 'success'])]);
    }

    private function token(int $id): void
    {
        DB::connection('hourly_alert_test')->table('tokens')->insert([
            'id' => $id,
            'name' => 'demo-' . $id,
            'remain_quota' => Quota::fromAmount(100),
        ]);
    }

    private function watch(array $overrides = []): AlertAdminWatch
    {
        return AlertAdminWatch::create(array_merge([
            'token_id' => 1,
            'token_name' => 'demo-1',
            'hourly_alert_enabled' => true,
            'hourly_threshold_quota' => Quota::fromAmount(5),
        ], $overrides));
    }

    private function log(string $time, float $amount, int $tokenId = 1, int $type = 2): void
    {
        DB::connection('hourly_alert_test')->table('logs')->insert([
            'token_id' => $tokenId,
            'token_name' => 'old-name',
            'type' => $type,
            'created_at' => $this->time($time)->timestamp,
            'quota' => Quota::fromAmount($amount),
        ]);
    }

    private function time(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Shanghai');
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'page_token_ids' => [1],
            'watch' => [1],
            'hourly_settings_present' => '1',
            'hourly_enabled' => [1],
            'hourly_threshold' => [1 => '5'],
        ], $overrides);
    }
}
