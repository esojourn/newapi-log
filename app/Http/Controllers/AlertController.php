<?php

namespace App\Http\Controllers;

use App\Models\AlertAdminWatch;
use App\Models\AlertSetting;
use App\Models\AlertSubscription;
use App\Models\Token;
use App\Services\AlertChecker;
use App\Services\FeishuNotifier;
use App\Support\Quota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 额度预警通知的设置界面。
 *
 * 用户侧（/usage/alerts）管当前登录 Key 自己的一套；管理员侧（/admin/alerts）管
 * 监控名单和另一套独立阈值，推送到管理员自己的飞书。两套互不影响。
 */
class AlertController extends Controller
{
    /** 阈值输入的上限（美元），纯粹为了挡住手滑输入的天文数字 */
    private const MAX_THRESHOLD_AMOUNT = 100000;

    private FeishuNotifier $feishu;

    public function __construct(FeishuNotifier $feishu)
    {
        $this->feishu = $feishu;
    }

    // ------------------------------------------------------------------
    // 用户侧
    // ------------------------------------------------------------------

    public function userSettings(Request $request)
    {
        $token = $this->sessionToken($request);

        if ($token === null) {
            return redirect('/');
        }

        $subscription = AlertSubscription::query()->where('token_id', $token->id)->first();

        return view('alerts.user', [
            'tokenName' => $token->name,
            'balance' => $token->unlimited_quota ? '无限' : Quota::format((int) $token->remain_quota),
            'unlimited' => (bool) $token->unlimited_quota,
            'subscription' => $subscription,
            'thresholdAmount' => $subscription === null ? null : $this->quotaToInputAmount($subscription->threshold_quota),
            'defaultAmount' => config('alerts.default_threshold_amount'),
            'remindHours' => config('alerts.remind_hours'),
        ]);
    }

    public function userSave(Request $request)
    {
        $token = $this->sessionToken($request);

        if ($token === null) {
            return redirect('/');
        }

        $data = $request->validate([
            'threshold_amount' => $this->amountRules(),
            'webhook_url' => $this->webhookRules(),
            'webhook_secret' => 'nullable|string|max:200',
        ]);

        $enabled = $request->boolean('enabled');
        $amount = $this->nullableValue($data, 'threshold_amount');
        $webhookUrl = $this->nullableValue($data, 'webhook_url');

        if ($enabled && ($amount === null || $webhookUrl === null)) {
            return back()
                ->withErrors(['enabled' => '启用预警前，请先填写预警金额和飞书 Webhook 地址'])
                ->withInput();
        }

        // token_id 只从 session 反查得到，绝不接受表单传入——否则任何登录用户
        // 都能改别人 Key 的通知设置
        AlertSubscription::updateOrCreate(
            ['token_id' => $token->id],
            [
                'token_name' => $token->name,
                'enabled' => $enabled,
                'threshold_quota' => $amount === null ? null : Quota::fromAmount((float) $amount),
                'webhook_url' => $webhookUrl,
                'webhook_secret' => $this->nullableValue($data, 'webhook_secret'),
                // 设置一改就清空去重记录，让下一轮按新配置重新判定并立即提醒
                'last_notified_at' => null,
                'last_notified_quota' => null,
                'last_error' => null,
                'last_error_at' => null,
            ]
        );

        return redirect()->route('user.alerts')->with('status', '通知设置已保存');
    }

    public function userTest(Request $request)
    {
        $token = $this->sessionToken($request);

        if ($token === null) {
            return redirect('/');
        }

        $subscription = AlertSubscription::query()->where('token_id', $token->id)->first();

        if ($subscription === null || $subscription->webhook_url === null) {
            return back()->withErrors(['webhook_url' => '请先填写并保存飞书 Webhook 地址，再发送测试消息']);
        }

        $result = $this->feishu->send(
            $subscription->webhook_url,
            $subscription->webhook_secret,
            $this->feishu->testCard('你的 Key「' . $token->name . '」')
        );

        return $result['ok']
            ? back()->with('status', '测试消息已发送，请查看飞书')
            : back()->withErrors(['webhook_url' => '发送失败：' . $result['error']]);
    }

    // ------------------------------------------------------------------
    // 管理员侧
    // ------------------------------------------------------------------

    public function adminIndex(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));

        $query = DB::table('tokens')->whereNull('deleted_at');

        if ($keyword !== '') {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $tokens = $query->orderBy('name')
            ->paginate(50, ['id', 'name', 'remain_quota', 'unlimited_quota', 'status'])
            ->withQueryString();

        $watches = AlertAdminWatch::query()->orderBy('token_name')->get();

        // 已监控 Key 的当前余额：一次批量查，不逐行回表
        $watchedTokens = DB::table('tokens')
            ->whereIn('id', $watches->pluck('token_id')->all())
            ->get(['id', 'name', 'remain_quota', 'unlimited_quota'])
            ->keyBy('id');

        return view('admin.alerts', [
            'keyword' => $keyword,
            'tokens' => $tokens,
            'watches' => $watches,
            'watchedTokens' => $watchedTokens,
            'watchedIds' => $watches->pluck('token_id')->flip(),
            // token_id => 表单里显示的美元阈值，留空表示沿用全局默认
            'watchAmounts' => $watches->mapWithKeys(fn ($w) => [$w->token_id => $this->quotaToInputAmount($w->threshold_quota)]),
            'subscribedIds' => AlertSubscription::query()->where('enabled', true)->pluck('token_id')->flip(),
            'adminEnabled' => AlertSetting::adminEnabled(),
            'adminWebhookUrl' => AlertSetting::adminWebhookUrl(),
            'adminWebhookSecret' => AlertSetting::adminWebhookSecret(),
            'adminDefaultAmount' => $this->quotaToInputAmount(AlertSetting::adminDefaultThresholdQuota()),
            'defaultAmount' => config('alerts.default_threshold_amount'),
            'remindHours' => config('alerts.remind_hours'),
        ]);
    }

    public function adminSaveSettings(Request $request)
    {
        $data = $request->validate([
            'admin_webhook_url' => $this->webhookRules(),
            'admin_webhook_secret' => 'nullable|string|max:200',
            'admin_default_threshold_amount' => $this->amountRules(),
        ]);

        $enabled = $request->boolean('admin_enabled');
        $webhookUrl = $this->nullableValue($data, 'admin_webhook_url');
        $amount = $this->nullableValue($data, 'admin_default_threshold_amount');

        if ($enabled && $webhookUrl === null) {
            return back()
                ->withErrors(['admin_webhook_url' => '启用管理员预警前，请先填写飞书 Webhook 地址'])
                ->withInput();
        }

        AlertSetting::setValue(AlertSetting::ADMIN_ENABLED, $enabled ? '1' : '');
        AlertSetting::setValue(AlertSetting::ADMIN_WEBHOOK_URL, $webhookUrl);
        AlertSetting::setValue(AlertSetting::ADMIN_WEBHOOK_SECRET, $this->nullableValue($data, 'admin_webhook_secret'));
        AlertSetting::setValue(
            AlertSetting::ADMIN_DEFAULT_THRESHOLD_QUOTA,
            $amount === null ? null : (string) Quota::fromAmount((float) $amount)
        );

        return redirect()->route('admin.alerts')->with('status', '管理员通知设置已保存');
    }

    public function adminSaveWatches(Request $request)
    {
        $request->validate([
            'page_token_ids' => 'required|array|max:2000',
            'page_token_ids.*' => 'integer',
            'watch' => 'nullable|array',
            'watch.*' => 'integer',
            'threshold' => 'nullable|array',
            'threshold.*' => 'nullable|numeric|min:0|max:' . self::MAX_THRESHOLD_AMOUNT,
        ]);

        // 列表是分页的，只处理本次提交的这批 id，其他页的勾选不受影响
        $pageIds = array_map('intval', $request->input('page_token_ids', []));
        $checked = array_values(array_intersect(array_map('intval', $request->input('watch', [])), $pageIds));
        $thresholds = (array) $request->input('threshold', []);

        $names = DB::table('tokens')->whereIn('id', $pageIds)->pluck('name', 'id');

        $removed = array_values(array_diff($pageIds, $checked));

        if ($removed !== []) {
            AlertAdminWatch::query()->whereIn('token_id', $removed)->delete();
        }

        foreach ($checked as $tokenId) {
            $raw = $thresholds[$tokenId] ?? null;
            $raw = ($raw === null || $raw === '') ? null : $raw;

            AlertAdminWatch::updateOrCreate(
                ['token_id' => $tokenId],
                [
                    'token_name' => $names[$tokenId] ?? ('#' . $tokenId),
                    'threshold_quota' => $raw === null ? null : Quota::fromAmount((float) $raw),
                    'last_notified_at' => null,
                    'last_notified_quota' => null,
                    'last_error' => null,
                    'last_error_at' => null,
                ]
            );
        }

        return back()->with('status', sprintf('已更新监控名单：本页监控 %d 个，移除 %d 个', count($checked), count($removed)));
    }

    public function adminTest(Request $request)
    {
        $webhookUrl = AlertSetting::adminWebhookUrl();

        if ($webhookUrl === null) {
            return back()->withErrors(['admin_webhook_url' => '请先填写并保存飞书 Webhook 地址，再发送测试消息']);
        }

        $result = $this->feishu->send(
            $webhookUrl,
            AlertSetting::adminWebhookSecret(),
            $this->feishu->testCard('管理员')
        );

        return $result['ok']
            ? back()->with('status', '测试消息已发送，请查看飞书')
            : back()->withErrors(['admin_webhook_url' => '发送失败：' . $result['error']]);
    }

    public function adminRunNow(Request $request, AlertChecker $checker)
    {
        $stats = $checker->run();

        return back()->with('status', sprintf(
            '检查完成：检查 %d，用户推送 %d，管理员推送 %d，跳过 %d，失败 %d',
            $stats['checked'],
            $stats['user_sent'],
            $stats['admin_sent'],
            $stats['skipped'],
            $stats['failed']
        ));
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * 从 session 反查当前登录的 Key，逻辑与 StatsController::usage() 一致：
     * key 被删掉之后 session 自动失效。
     */
    private function sessionToken(Request $request): ?Token
    {
        $apikey = session('user_api_key');

        if (!$apikey) {
            return null;
        }

        $token = Token::where('key', substr($apikey, 3))->first();

        if (!$token) {
            $request->session()->forget(['user_api_key', 'user_token_name']);

            return null;
        }

        return $token;
    }

    /** webhook 地址的白名单校验，与 FeishuNotifier 发送前的那道用同一份配置 */
    private function webhookRules(): array
    {
        return [
            'nullable',
            'string',
            'max:500',
            function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && !FeishuNotifier::isAllowedWebhook($value)) {
                    $fail('只支持飞书自定义机器人的 Webhook 地址（open.feishu.cn 或 open.larksuite.com）');
                }
            },
        ];
    }

    private function amountRules(): string
    {
        return 'nullable|numeric|min:0|max:' . self::MAX_THRESHOLD_AMOUNT;
    }

    /** 表单里的空串一律当没填 */
    private function nullableValue(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** quota → 表单里显示的美元数（去掉多余的尾零） */
    private function quotaToInputAmount(?int $quota): ?string
    {
        return $quota === null ? null : rtrim(rtrim(number_format(Quota::toAmount($quota), 4, '.', ''), '0'), '.');
    }
}
