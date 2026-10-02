<?php

namespace App\Services;

use App\Models\ChannelMonitorLog;
use App\Models\ChannelRecoveryLog;
use App\Models\ChannelRecoveryRun;
use App\Models\ChannelRecoverySetting;
use Cron\CronExpression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ChannelRecoveryChecker
{
    private NewApiChannelClient $client;

    public function __construct(NewApiChannelClient $client)
    {
        $this->client = $client;
    }

    /** @return array{checked: int, healthy: int, recovered: int, skipped: int, failed: int} */
    public function run(bool $dryRun = false, ?int $onlyChannelId = null, bool $scheduled = false): array
    {
        $stats = ['checked' => 0, 'healthy' => 0, 'recovered' => 0, 'skipped' => 0, 'failed' => 0];
        $settings = ChannelRecoverySetting::current();
        if (!$settings->enabled) {
            return $stats;
        }

        if ($scheduled && !(new CronExpression($settings->schedule_cron))->isDue(now(), config('app.timezone'))) {
            return $stats;
        }

        // 覆盖定时任务与手动命令，避免同一个渠道被重复探测/启用。
        // 先取锁再记录本轮：重叠的调用不能覆盖正在运行那一轮的状态。
        $lock = Cache::lock('channels:recover', 86400);
        if (!$lock->get()) {
            throw new RuntimeException('已有渠道恢复检查正在运行，请稍后重试。');
        }

        $run = $this->startRun($dryRun, $scheduled);
        $failures = [];
        $runError = null;

        try {
            $this->client->configure($settings->configuration());
            $this->client->validateConfiguration();

            // 只读 id，不读取渠道密钥；按 id 分批，恢复导致结果集缩小时也不会漏渠道。
            $channels = DB::table('channels')->select('id')
                ->where('auto_ban', 1)
                ->where('status', NewApiChannelClient::STATUS_AUTO_DISABLED)
                ->when($onlyChannelId !== null, fn ($query) => $query->where('id', $onlyChannelId))
                ->lazyById(100);

            foreach ($channels as $channel) {
                $id = (int) $channel->id;
                $stage = 'read';
                $action = null;
                $monitor = null;

                try {
                    // API 再核对一次，数据库副本延迟或排队期间的人工修改都不能扩大检查范围。
                    $details = $this->client->channel($id);
                    if (!$this->client->isEligible($details)) {
                        $stats['skipped']++;
                        continue;
                    }

                    // 测试前保存禁用快照，成功恢复后上游可能覆盖原始禁用原因。
                    $stage = 'monitor';
                    $monitor = ChannelMonitorLog::create(array_merge([
                        'channel_id' => $id,
                        'channel_name' => Str::limit((string) ($details['name'] ?? ''), 255, ''),
                        'source' => $scheduled ? 'scheduled' : 'manual',
                        'dry_run' => $dryRun,
                        'result' => 'pending',
                    ], $this->client->disabledDetails($details)));

                    $stage = 'test';
                    $stats['checked']++;
                    $probe = $this->client->test($id, $details);
                    $monitor->update([
                        'result' => $probe['success'] ? 'healthy' : 'failed',
                        'message' => $probe['message'] ?? ($probe['success'] ? '渠道测试正常。' : '上游未提供失败原因。'),
                        'completed_at' => now(),
                    ]);
                    if (!$probe['success']) {
                        $stats['failed']++;
                        $this->addFailure($failures, $id, $stage, $probe['message'] ?? '上游未提供失败原因。');
                        Log::info('Channel recovery test failed', ['channel_id' => $id]);
                        continue;
                    }

                    $stats['healthy']++;
                    if ($dryRun) {
                        continue;
                    }

                    // 先持久化恢复意图，再调用有副作用的 API。日志不可写时不发起恢复；
                    // 进程中断会留下 pending 记录，不能把未确认的动作当作恢复成功。
                    $stage = 'audit';
                    $action = ChannelRecoveryLog::create([
                        'channel_id' => $id,
                        'channel_name' => Str::limit((string) ($details['name'] ?? ''), 255, ''),
                        'source' => $scheduled ? 'scheduled' : 'manual',
                        'from_status' => NewApiChannelClient::STATUS_AUTO_DISABLED,
                        'target_status' => NewApiChannelClient::STATUS_ENABLED,
                        'result' => 'pending',
                        'message' => '测试正常，准备恢复。',
                    ]);

                    $stage = 'enable';
                    if ($this->client->enableIfEligible($id)) {
                        $stats['recovered']++;
                        Log::info('Channel recovered', ['channel_id' => $id]);
                        $action->update([
                            'result' => 'recovered',
                            'message' => '测试正常，已确认从自动禁用恢复为启用。',
                            'completed_at' => now(),
                        ]);
                    } else {
                        $stats['skipped']++;
                        $action->update([
                            'result' => 'skipped',
                            'message' => '恢复前状态或自动封禁设置已变化，未更新渠道。',
                            'completed_at' => now(),
                        ]);
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $this->addFailure($failures, $id, $stage, $this->safeMessage($e) ?? '内部错误（' . class_basename($e) . '），请检查服务日志及本地日志存储。');
                    if ($monitor !== null && $monitor->result === 'pending') {
                        try {
                            $monitor->update([
                                'result' => 'error',
                                'message' => $this->safeMessage($e) ?? '检测未完成，请检查服务日志及本地日志存储。',
                                'completed_at' => now(),
                            ]);
                        } catch (Throwable $monitorError) {
                            Log::error('Channel monitor log could not be completed', ['log_id' => $monitor->id]);
                        }
                    }
                    if ($action !== null && $action->result === 'pending') {
                        try {
                            $action->update([
                                'result' => 'failed',
                                'message' => '恢复未确认，请在 NewAPI 后台核对渠道实际状态和管理接口权限。',
                                'completed_at' => now(),
                            ]);
                        } catch (Throwable $auditError) {
                            // 写前记录仍然保留为 pending，不掩盖上游调用结果。
                            Log::error('Channel recovery audit could not be completed', ['log_id' => $action->id]);
                        }
                    }
                    Log::warning('Channel recovery failed', [
                        'channel_id' => $id,
                        'stage' => $stage,
                        'error' => $this->safeMessage($e) ?? get_class($e),
                    ]);
                }
            }
        } catch (Throwable $e) {
            $runError = $this->safeMessage($e) ?? '数据库访问失败（' . class_basename($e) . '），请检查本地 alerts 库与 NewAPI channels 表的只读连接。';
            throw $e;
        } finally {
            $this->finishRun($run, $stats, $failures, $runError);
            $lock->release();
        }

        Log::info('Channel recovery completed', array_merge(['dry_run' => $dryRun], $stats));

        return $stats;
    }

    /** 接口异常文字已脱敏，可直接展示；数据库等其他异常可能带连接信息，只给类名。 */
    private function safeMessage(Throwable $e): ?string
    {
        return $e instanceof RuntimeException && !($e instanceof QueryException) ? $e->getMessage() : null;
    }

    private function addFailure(array &$failures, int $id, string $stage, string $message): void
    {
        if (count($failures) < ChannelRecoveryRun::MAX_FAILURES) {
            $failures[] = ['channel_id' => $id, 'stage' => $stage, 'message' => Str::limit($message, 500)];
        }
    }

    /** 运行状态只用于展示：写入失败不影响检查与恢复本身。 */
    private function startRun(bool $dryRun, bool $scheduled): ?ChannelRecoveryRun
    {
        try {
            $run = ChannelRecoveryRun::query()->findOrNew(1);
            $run->forceFill([
                'id' => 1,
                'source' => $scheduled ? 'scheduled' : 'manual',
                'dry_run' => $dryRun,
                'started_at' => now(),
                'finished_at' => null,
                'checked' => 0, 'healthy' => 0, 'recovered' => 0, 'skipped' => 0, 'failed' => 0,
                'error' => null,
                'failures' => null,
            ])->save();

            return $run;
        } catch (Throwable $e) {
            Log::error('Channel recovery run status could not be saved', ['error' => get_class($e)]);

            return null;
        }
    }

    private function finishRun(?ChannelRecoveryRun $run, array $stats, array $failures, ?string $error): void
    {
        if ($run === null) {
            return;
        }

        try {
            $run->update(array_merge($stats, [
                'finished_at' => now(),
                'error' => $error,
                'failures' => $failures ?: null,
            ]));
        } catch (Throwable $e) {
            Log::error('Channel recovery run status could not be completed', ['error' => get_class($e)]);
        }
    }
}
