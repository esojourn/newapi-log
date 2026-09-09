<?php

namespace Tests\Feature;

use App\Models\AlertAdminWatch;
use App\Models\AlertSetting;
use App\Models\AlertSubscription;
use App\Services\AlertChecker;
use App\Services\FeishuNotifier;
use App\Support\Quota;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 额度预警通知的测试。
 *
 * ⚠️ 绝对不能用 RefreshDatabase：默认连接指向外部 newapi 生产库，会被清空。
 * 这里只迁移 alerts 连接（phpunit.xml 里指到 :memory:），tokens 一律用假数据
 * 或从真实库里只读地取一行。
 */
class AlertsTest extends TestCase
{
    private const HOOK = 'https://open.feishu.cn/open-apis/bot/v2/hook/test-hook';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--database' => 'alerts',
            '--path' => 'database/migrations/alerts',
            '--force' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // 判定与推送
    // ------------------------------------------------------------------

    public function test_sends_when_balance_below_threshold(): void
    {
        $this->fakeFeishu();

        $subscription = $this->subscription();

        $stats = $this->checker([$this->token(1, 'demo', Quota::fromAmount(1))])->run();

        $this->assertSame(1, $stats['user_sent']);
        $this->assertSame(0, $stats['failed']);

        Http::assertSent(function ($request) {
            return $request->url() === self::HOOK
                && $request['msg_type'] === 'interactive';
        });

        $this->assertNotNull($subscription->fresh()->last_notified_at);
    }

    public function test_no_send_when_balance_above_threshold_and_state_resets(): void
    {
        Http::fake();

        $subscription = $this->subscription([
            'last_notified_at' => Carbon::now()->subHour(),
            'last_notified_quota' => Quota::fromAmount(1),
        ]);

        $stats = $this->checker([$this->token(1, 'demo', Quota::fromAmount(50))])->run();

        $this->assertSame(0, $stats['user_sent']);
        $this->assertSame(1, $stats['skipped']);
        Http::assertNothingSent();

        // 余额回到阈值以上要把推送记录清掉，下次跌破时才能立即再提醒
        $this->assertNull($subscription->fresh()->last_notified_at);
    }

    public function test_dedupes_within_remind_window_then_resends_after_it(): void
    {
        // 固定复发间隔，别跟着部署环境的 ALERT_REMIND_HOURS 变：
        // 生产上可以配成 0（不去重、由 cron 决定节奏），那样这个用例就没窗口可测了
        config(['alerts.remind_hours' => 24]);

        $this->fakeFeishu();

        $subscription = $this->subscription();
        $tokens = [$this->token(1, 'demo', Quota::fromAmount(1))];

        $this->assertSame(1, $this->checker($tokens)->run()['user_sent']);

        // 紧接着再跑一次不应该重复轰炸
        $second = $this->checker($tokens)->run();
        $this->assertSame(0, $second['user_sent']);
        $this->assertSame(1, $second['skipped']);

        // 过了复发间隔要再提醒一次
        $subscription->forceFill([
            'last_notified_at' => Carbon::now()->subHours((int) config('alerts.remind_hours') + 1),
        ])->save();

        $this->assertSame(1, $this->checker($tokens)->run()['user_sent']);
    }

    public function test_skips_unlimited_quota_key(): void
    {
        Http::fake();

        $this->subscription();

        $stats = $this->checker([$this->token(1, 'demo', 0, true)])->run();

        $this->assertSame(0, $stats['user_sent']);
        $this->assertSame(1, $stats['skipped']);
        Http::assertNothingSent();
    }

    public function test_records_error_when_token_no_longer_exists(): void
    {
        Http::fake();

        $subscription = $this->subscription();

        $stats = $this->checker([])->run();

        $this->assertSame(0, $stats['user_sent']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertNotNull($subscription->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_skips_subscription_whose_webhook_cannot_be_decrypted(): void
    {
        Http::fake();

        $subscription = $this->subscription();

        // 模拟 APP_KEY 换过：密文还躺在库里，但已经解不开了。
        // 解密降级成 null 之后不能让它一路走到 send()，那会抛 TypeError 把整轮带崩。
        DB::connection('alerts')->table('alert_subscriptions')
            ->where('token_id', 1)
            ->update(['webhook_url' => 'not-a-valid-ciphertext']);

        $stats = $this->checker([$this->token(1, 'demo', Quota::fromAmount(1))])->run();

        $this->assertSame(0, $stats['user_sent']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(1, $stats['skipped']);
        Http::assertNothingSent();

        // 失败原因要写回去，用户在设置页才知道得重新填一次
        $this->assertNotNull($subscription->fresh()->last_error);
    }

    public function test_dry_run_does_not_send_or_write_state(): void
    {
        Http::fake();

        $subscription = $this->subscription();

        $stats = $this->checker([$this->token(1, 'demo', Quota::fromAmount(1))])->run(true);

        $this->assertSame(1, $stats['user_sent']);
        Http::assertNothingSent();
        $this->assertNull($subscription->fresh()->last_notified_at);
    }

    public function test_admin_watches_merge_into_a_single_card(): void
    {
        $this->fakeFeishu();

        AlertSetting::setValue(AlertSetting::ADMIN_ENABLED, '1');
        AlertSetting::setValue(AlertSetting::ADMIN_WEBHOOK_URL, self::HOOK);
        AlertSetting::setValue(AlertSetting::ADMIN_DEFAULT_THRESHOLD_QUOTA, (string) Quota::fromAmount(5));

        AlertAdminWatch::create(['token_id' => 1, 'token_name' => 'a']);
        AlertAdminWatch::create(['token_id' => 2, 'token_name' => 'b', 'threshold_quota' => Quota::fromAmount(100)]);

        $stats = $this->checker([
            $this->token(1, 'a', Quota::fromAmount(1)),   // 低于全局默认 $5
            $this->token(2, 'b', Quota::fromAmount(50)),  // 低于自己的 $100
        ])->run();

        $this->assertSame(2, $stats['admin_sent']);
        // 两个 Key 合并成一条卡片，只发一次
        Http::assertSentCount(1);
    }

    public function test_admin_side_inactive_without_webhook(): void
    {
        Http::fake();

        AlertSetting::setValue(AlertSetting::ADMIN_ENABLED, '1');
        AlertAdminWatch::create(['token_id' => 1, 'token_name' => 'a', 'threshold_quota' => Quota::fromAmount(5)]);

        $stats = $this->checker([$this->token(1, 'a', Quota::fromAmount(1))])->run();

        $this->assertSame(0, $stats['admin_sent']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // 飞书协议
    // ------------------------------------------------------------------

    public function test_sign_matches_feishu_algorithm(): void
    {
        $timestamp = '1700000000';
        $secret = 'my-secret';
        $stringToSign = $timestamp . "\n" . $secret;

        // 以 "{timestamp}\n{secret}" 为密钥、对空串取 HMAC
        $this->assertSame(
            base64_encode(hash_hmac('sha256', '', $stringToSign, true)),
            FeishuNotifier::sign($timestamp, $secret)
        );

        // key / data 写反了就永远验不过，这里显式钉住方向
        $this->assertNotSame(
            base64_encode(hash_hmac('sha256', $stringToSign, '', true)),
            FeishuNotifier::sign($timestamp, $secret)
        );
    }

    public function test_signature_is_attached_only_when_secret_present(): void
    {
        $this->fakeFeishu();

        $notifier = new FeishuNotifier();
        $notifier->send(self::HOOK, 'my-secret', ['msg_type' => 'text', 'content' => ['text' => 'x']]);
        $notifier->send(self::HOOK, null, ['msg_type' => 'text', 'content' => ['text' => 'x']]);

        $requests = Http::recorded();
        $this->assertNotNull($requests[0][0]['sign']);
        $this->assertNull($requests[1][0]['sign'] ?? null);
    }

    public function test_webhook_whitelist_rejects_everything_but_feishu(): void
    {
        $this->assertTrue(FeishuNotifier::isAllowedWebhook(self::HOOK));
        $this->assertTrue(FeishuNotifier::isAllowedWebhook('https://open.larksuite.com/open-apis/bot/v2/hook/x'));

        $this->assertFalse(FeishuNotifier::isAllowedWebhook('http://127.0.0.1/open-apis/bot/v2/hook/x'));
        $this->assertFalse(FeishuNotifier::isAllowedWebhook('https://evil.com/open-apis/bot/v2/hook/x'));
        $this->assertFalse(FeishuNotifier::isAllowedWebhook('http://open.feishu.cn/open-apis/bot/v2/hook/x'));
        $this->assertFalse(FeishuNotifier::isAllowedWebhook(null));
        $this->assertFalse(FeishuNotifier::isAllowedWebhook(''));
    }

    public function test_notifier_refuses_to_call_non_whitelisted_url(): void
    {
        Http::fake();

        $result = (new FeishuNotifier())->send('https://evil.com/hook', null, ['msg_type' => 'text']);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // 用户侧页面与越权
    // ------------------------------------------------------------------

    public function test_user_alerts_requires_session(): void
    {
        $this->get('/usage/alerts')->assertRedirect('/');
        $this->post('/usage/alerts', ['threshold_amount' => '5'])->assertRedirect('/');
    }

    public function test_user_alerts_page_renders(): void
    {
        $token = $this->anyRealToken();

        $this->withUserSession($token)->get('/usage/alerts')->assertOk();
    }

    public function test_user_save_takes_token_id_from_session_not_from_form(): void
    {
        $token = $this->anyRealToken();

        $this->withUserSession($token)->post('/usage/alerts', [
            'token_id' => 999999,          // 伪造的，必须被完全忽略
            'enabled' => '1',
            'threshold_amount' => '5',
            'webhook_url' => self::HOOK,
        ])->assertRedirect(route('user.alerts'));

        $this->assertSame(1, AlertSubscription::query()->count());
        $this->assertSame((int) $token->id, AlertSubscription::query()->first()->token_id);
    }

    public function test_saved_settings_round_trip_through_encryption(): void
    {
        $token = $this->anyRealToken();

        $this->withUserSession($token)->post('/usage/alerts', [
            'enabled' => '1',
            'threshold_amount' => '12.5',
            'webhook_url' => self::HOOK,
            'webhook_secret' => 'my-secret',
        ])->assertRedirect(route('user.alerts'));

        $subscription = AlertSubscription::query()->first();
        $this->assertSame(self::HOOK, $subscription->webhook_url);
        $this->assertSame('my-secret', $subscription->webhook_secret);
        $this->assertSame(Quota::fromAmount(12.5), $subscription->threshold_quota);

        // 落盘的是密文，不是明文
        $raw = DB::connection('alerts')->table('alert_subscriptions')->value('webhook_url');
        $this->assertNotSame(self::HOOK, $raw);

        // 设置页要能把已保存的值回填进表单
        $this->withUserSession($token)->get('/usage/alerts')
            ->assertOk()
            ->assertSee(self::HOOK, false)
            ->assertSee('12.5', false);
    }

    public function test_user_save_rejects_non_feishu_webhook(): void
    {
        $token = $this->anyRealToken();

        $this->withUserSession($token)->post('/usage/alerts', [
            'enabled' => '1',
            'threshold_amount' => '5',
            'webhook_url' => 'https://evil.com/hook',
        ])->assertSessionHasErrors('webhook_url');

        $this->assertSame(0, AlertSubscription::query()->count());
    }

    public function test_user_cannot_enable_without_webhook(): void
    {
        $token = $this->anyRealToken();

        $this->withUserSession($token)->post('/usage/alerts', [
            'enabled' => '1',
            'threshold_amount' => '5',
        ])->assertSessionHasErrors('enabled');

        $this->assertSame(0, AlertSubscription::query()->count());
    }

    // ------------------------------------------------------------------
    // 管理员页面
    // ------------------------------------------------------------------

    public function test_admin_alerts_page_requires_auth(): void
    {
        $this->get('/admin/alerts')->assertRedirect(route('admin.login'));
    }

    public function test_admin_alerts_page_renders(): void
    {
        $this->withSession(['admin_authenticated' => true])->get('/admin/alerts')->assertOk();
    }

    public function test_admin_save_watches_only_touches_submitted_page(): void
    {
        // 第 3 个 Key 不在本次提交的 page_token_ids 里，必须原样保留
        AlertAdminWatch::create(['token_id' => 3, 'token_name' => 'other-page']);

        $this->withSession(['admin_authenticated' => true])->post('/admin/alerts/watches', [
            'page_token_ids' => [1, 2],
            'watch' => [1],
            'threshold' => [1 => '7.5'],
        ])->assertRedirect();

        $this->assertTrue(AlertAdminWatch::query()->where('token_id', 1)->exists());
        $this->assertFalse(AlertAdminWatch::query()->where('token_id', 2)->exists());
        $this->assertTrue(AlertAdminWatch::query()->where('token_id', 3)->exists());

        $this->assertSame(
            Quota::fromAmount(7.5),
            AlertAdminWatch::query()->where('token_id', 1)->first()->threshold_quota
        );
    }

    public function test_admin_settings_round_trip(): void
    {
        $this->withSession(['admin_authenticated' => true])->post('/admin/alerts/settings', [
            'admin_enabled' => '1',
            'admin_webhook_url' => self::HOOK,
            'admin_webhook_secret' => 'admin-secret',
            'admin_default_threshold_amount' => '20',
        ])->assertRedirect(route('admin.alerts'));

        $this->assertTrue(AlertSetting::adminEnabled());
        $this->assertSame(self::HOOK, AlertSetting::adminWebhookUrl());
        $this->assertSame('admin-secret', AlertSetting::adminWebhookSecret());
        $this->assertSame(Quota::fromAmount(20), AlertSetting::adminDefaultThresholdQuota());
    }

    public function test_admin_settings_reject_non_feishu_webhook(): void
    {
        $this->withSession(['admin_authenticated' => true])->post('/admin/alerts/settings', [
            'admin_enabled' => '1',
            'admin_webhook_url' => 'http://127.0.0.1/hook',
        ])->assertSessionHasErrors('admin_webhook_url');

        $this->assertNull(AlertSetting::adminWebhookUrl());
    }

    // ------------------------------------------------------------------
    // 换算
    // ------------------------------------------------------------------

    public function test_quota_amount_round_trip(): void
    {
        $this->assertSame(500000, Quota::fromAmount(1));
        $this->assertSame(1.0, Quota::toAmount(500000));
        $this->assertSame(2500000, Quota::fromAmount(5));
        $this->assertSame('$0.0000', Quota::format(0));
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function fakeFeishu(): void
    {
        Http::fake([
            'open.feishu.cn/*' => Http::response(['code' => 0, 'msg' => 'success']),
            'open.larksuite.com/*' => Http::response(['code' => 0, 'msg' => 'success']),
        ]);
    }

    /** 默认建一条 $5 阈值、已启用的订阅 */
    private function subscription(array $overrides = []): AlertSubscription
    {
        return AlertSubscription::create(array_merge([
            'token_id' => 1,
            'token_name' => 'demo',
            'enabled' => true,
            'threshold_quota' => Quota::fromAmount(5),
            'webhook_url' => self::HOOK,
        ], $overrides));
    }

    /** @param  array<int, object>  $tokens */
    private function checker(array $tokens): AlertChecker
    {
        return new FakeTokenAlertChecker(new FeishuNotifier(), $tokens);
    }

    private function token(int $id, string $name, int $remainQuota, bool $unlimited = false): object
    {
        return (object) [
            'id' => $id,
            'name' => $name,
            'remain_quota' => $remainQuota,
            'unlimited_quota' => $unlimited,
        ];
    }

    /** 从真实库里只读地取一行 token，用于走 session 认证的那几条路径 */
    private function anyRealToken(): object
    {
        $token = DB::table('tokens')->whereNull('deleted_at')->first(['id', 'name', 'key']);

        if ($token === null) {
            $this->markTestSkipped('tokens 表为空，跳过依赖真实 Key 的用例');
        }

        return $token;
    }

    private function withUserSession(object $token): self
    {
        // 与 StatsController::authenticate() 一致：session 里存的是带前缀的完整 key
        $this->withSession([
            'user_api_key' => 'sk-' . $token->key,
            'user_token_name' => $token->name,
        ]);

        return $this;
    }
}

/**
 * 把「读 tokens 表」这一步换成内存里的假数据。
 *
 * tokens 在外部 newapi 生产库里，测试既不能写也不能控制余额，所以只能从这里注入。
 */
class FakeTokenAlertChecker extends AlertChecker
{
    /** @var array<int, object> */
    private array $fakeTokens;

    public function __construct(FeishuNotifier $feishu, array $fakeTokens)
    {
        parent::__construct($feishu);

        $this->fakeTokens = $fakeTokens;
    }

    protected function loadTokens(array $tokenIds): Collection
    {
        return collect($this->fakeTokens)
            ->filter(fn ($token) => in_array((int) $token->id, $tokenIds, true))
            ->keyBy('id');
    }
}
