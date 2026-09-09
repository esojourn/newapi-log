<?php

namespace App\Services;

use App\Models\AlertAdminWatch;
use App\Models\AlertSetting;
use App\Models\AlertSubscription;
use App\Models\Token;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 余额预警的检查与推送。
 *
 * 由 alerts:check 命令（cron）驱动，也被后台的「立即检查」按钮直接调用。
 * 读外部 newapi 库只做一次 whereIn 批量查询，写只写本地 alerts 库。
 */
class AlertChecker
{
    /** 判定结果 */
    private const SEND = 'send';
    private const RESET = 'reset';
    private const SKIP = 'skip';
    private const MISSING = 'missing';
    private const UNLIMITED = 'unlimited';

    private FeishuNotifier $feishu;

    public function __construct(FeishuNotifier $feishu)
    {
        $this->feishu = $feishu;
    }

    /**
     * 跑一轮检查。
     *
     * @param  bool      $dryRun       只判定不发送，也不写去重记录
     * @param  int|null  $onlyTokenId  只检查某个 token_id，用于排障
     * @return array{checked: int, user_sent: int, admin_sent: int, skipped: int, failed: int}
     */
    public function run(bool $dryRun = false, ?int $onlyTokenId = null): array
    {
        $stats = ['checked' => 0, 'user_sent' => 0, 'admin_sent' => 0, 'skipped' => 0, 'failed' => 0];

        $subscriptions = AlertSubscription::query()
            ->where('enabled', true)
            ->whereNotNull('threshold_quota')
            ->whereNotNull('webhook_url')
            ->when($onlyTokenId !== null, fn ($q) => $q->where('token_id', $onlyTokenId))
            ->get();

        // 管理员那套要三样齐全才生效：总开关、白名单内的 webhook、以及监控名单
        $adminUrl = AlertSetting::adminWebhookUrl();
        $adminReady = AlertSetting::adminEnabled() && FeishuNotifier::isAllowedWebhook($adminUrl);

        $watches = $adminReady
            ? AlertAdminWatch::query()
                ->when($onlyTokenId !== null, fn ($q) => $q->where('token_id', $onlyTokenId))
                ->get()
            : new Collection();

        $tokenIds = $subscriptions->pluck('token_id')
            ->merge($watches->pluck('token_id'))
            ->unique()
            ->values();

        if ($tokenIds->isEmpty()) {
            return $stats;
        }

        $tokens = $this->loadTokens($tokenIds->all());

        $this->runUserSide($subscriptions, $tokens, $dryRun, $stats);
        $this->runAdminSide($watches, $tokens, $adminUrl, $dryRun, $stats);

        return $stats;
    }

    /**
     * 读外部 newapi 库里这批 Key 的当前余额，一次批量查完，避免逐行 N+1。
     * 已软删除的 Key 视为不存在。
     *
     * 单独抽成 protected 方法是为了让测试能替换掉：tokens 在外部生产库里，
     * 测试不可能往里造数据。
     *
     * @param  array<int, int>  $tokenIds
     */
    protected function loadTokens(array $tokenIds): Collection
    {
        return Token::query()
            ->whereIn('id', $tokenIds)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'remain_quota', 'unlimited_quota'])
            ->keyBy('id');
    }

    /** 用户侧：每个订阅推到各自的 webhook */
    private function runUserSide(Collection $subscriptions, Collection $tokens, bool $dryRun, array &$stats): void
    {
        foreach ($subscriptions as $subscription) {
            $stats['checked']++;

            // webhook 是密文落盘的，APP_KEY 变过之后解密会降级成 null，而上面的
            // whereNotNull 只看得到密文列。放它过去 send() 会收到 null 直接抛
            // TypeError，一条坏记录就能把整轮检查带崩——在这里拦住，并把原因写回
            // 设置页，用户才知道要重填。
            if (!$subscription->isActionable()) {
                $stats['skipped']++;
                $this->markFailed($subscription, 'Webhook 地址无法读取（APP_KEY 可能已变更），请到设置页重新填写');
                continue;
            }

            $token = $tokens->get($subscription->token_id);
            $threshold = (int) $subscription->threshold_quota;
            $decision = $this->decide($subscription, $token, $threshold);

            if ($decision !== self::SEND) {
                $this->applyNonSend($subscription, $decision, $dryRun, $stats);
                continue;
            }

            $remain = (int) $token->remain_quota;

            if ($dryRun) {
                $stats['user_sent']++;
                continue;
            }

            $result = $this->feishu->send(
                $subscription->webhook_url,
                $subscription->webhook_secret,
                $this->feishu->balanceCard($token->name, $remain, $threshold, $this->link('/usage'))
            );

            if ($result['ok']) {
                $this->markNotified($subscription, $remain, $token->name);
                $stats['user_sent']++;
            } else {
                $this->markFailed($subscription, $result['error']);
                $stats['failed']++;
                Log::warning('用户余额预警推送失败', [
                    'token_name' => $token->name,
                    'error' => $result['error'],
                ]);
            }
        }
    }

    /** 管理员侧：本轮命中的 Key 合并成一条卡片，只发一次 */
    private function runAdminSide(Collection $watches, Collection $tokens, ?string $adminUrl, bool $dryRun, array &$stats): void
    {
        if ($watches->isEmpty()) {
            return;
        }

        $defaultThreshold = AlertSetting::adminDefaultThresholdQuota();

        /** @var array<int, array{name: string, remain: int, threshold: int}> $rows */
        $rows = [];
        /** @var AlertAdminWatch[] $hits */
        $hits = [];

        foreach ($watches as $watch) {
            $stats['checked']++;

            $token = $tokens->get($watch->token_id);
            // 逐 Key 阈值留空就用全局默认；全局默认也没配就没法判断
            $threshold = $watch->threshold_quota ?? $defaultThreshold;

            if ($threshold === null) {
                $stats['skipped']++;
                continue;
            }

            $decision = $this->decide($watch, $token, (int) $threshold);

            if ($decision !== self::SEND) {
                $this->applyNonSend($watch, $decision, $dryRun, $stats);
                continue;
            }

            $rows[] = [
                'name' => $token->name,
                'remain' => (int) $token->remain_quota,
                'threshold' => (int) $threshold,
            ];
            $hits[] = $watch;
        }

        if ($rows === []) {
            return;
        }

        if ($dryRun) {
            $stats['admin_sent'] = count($rows);

            return;
        }

        $result = $this->feishu->send(
            $adminUrl,
            AlertSetting::adminWebhookSecret(),
            $this->feishu->adminSummaryCard($rows, $this->link('/admin/alerts'))
        );

        foreach ($hits as $index => $watch) {
            if ($result['ok']) {
                $this->markNotified($watch, $rows[$index]['remain'], $rows[$index]['name']);
            } else {
                $this->markFailed($watch, $result['error']);
            }
        }

        if ($result['ok']) {
            $stats['admin_sent'] = count($rows);
        } else {
            $stats['failed'] += count($rows);
            Log::warning('管理员余额预警推送失败', [
                'count' => count($rows),
                'error' => $result['error'],
            ]);
        }
    }

    /**
     * 单行的判定。
     *
     * 余额回到阈值以上就清空推送记录（RESET），下次跌破时能立刻再提醒一次；
     * 持续低于阈值则按 alerts.remind_hours 复发，避免每半小时轰炸一条。
     *
     * @param  AlertSubscription|AlertAdminWatch  $row
     * @param  object|null  $token  tokens 表的一行（测试里是等价的假对象）
     */
    private function decide($row, $token, int $threshold): string
    {
        if ($token === null) {
            return self::MISSING;
        }

        if ($token->unlimited_quota) {
            return self::UNLIMITED;
        }

        if ((int) $token->remain_quota >= $threshold) {
            return self::RESET;
        }

        if ($row->last_notified_at === null) {
            return self::SEND;
        }

        $due = Carbon::now()->subHours((int) config('alerts.remind_hours'));

        return $row->last_notified_at->lessThanOrEqualTo($due) ? self::SEND : self::SKIP;
    }

    /** @param  AlertSubscription|AlertAdminWatch  $row */
    private function applyNonSend($row, string $decision, bool $dryRun, array &$stats): void
    {
        $stats['skipped']++;

        if ($dryRun) {
            return;
        }

        if ($decision === self::RESET) {
            // 只在确实有记录要清时才写库
            if ($row->last_notified_at !== null || $row->last_error !== null) {
                $row->forceFill([
                    'last_notified_at' => null,
                    'last_notified_quota' => null,
                    'last_error' => null,
                    'last_error_at' => null,
                ])->save();
            }

            return;
        }

        if ($decision === self::MISSING) {
            $this->markFailed($row, 'Token 已不存在或已被删除');
        }
    }

    /** @param  AlertSubscription|AlertAdminWatch  $row */
    private function markNotified($row, int $remainQuota, string $tokenName): void
    {
        $row->forceFill([
            'token_name' => $tokenName,
            'last_notified_at' => Carbon::now(),
            'last_notified_quota' => $remainQuota,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
    }

    /** @param  AlertSubscription|AlertAdminWatch  $row */
    private function markFailed($row, ?string $error): void
    {
        if ($row->last_error === $error) {
            return;
        }

        $row->forceFill([
            'last_error' => $error,
            'last_error_at' => Carbon::now(),
        ])->save();
    }

    /** 卡片按钮的落地页，命令行里没有请求上下文，只能用 app.url */
    private function link(string $path): ?string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base === '' ? null : $base . $path;
    }
}
