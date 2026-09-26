<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function run(bool $dryRun = false, ?int $onlyChannelId = null): array
    {
        $stats = ['checked' => 0, 'healthy' => 0, 'recovered' => 0, 'skipped' => 0, 'failed' => 0];
        if (!config('channels.recovery_enabled')) {
            return $stats;
        }

        $this->client->validateConfiguration();

        // 覆盖定时任务与手动命令，避免同一个渠道被重复探测/启用。
        $lock = Cache::lock('channels:recover', 86400);
        if (!$lock->get()) {
            throw new RuntimeException('已有渠道恢复检查正在运行，请稍后重试。');
        }

        try {
            // 只读 id，不读取渠道密钥；按 id 分批，恢复导致结果集缩小时也不会漏渠道。
            $channels = DB::table('channels')->select('id')
                ->where('auto_ban', 1)
                ->where('status', NewApiChannelClient::STATUS_AUTO_DISABLED)
                ->when($onlyChannelId !== null, fn ($query) => $query->where('id', $onlyChannelId))
                ->lazyById(100);

            foreach ($channels as $channel) {
                $id = (int) $channel->id;
                $stage = 'read';

                try {
                    // API 再核对一次，数据库副本延迟或排队期间的人工修改都不能扩大检查范围。
                    if (!$this->client->isEligible($this->client->channel($id))) {
                        $stats['skipped']++;
                        continue;
                    }

                    $stage = 'test';
                    $stats['checked']++;
                    if (!$this->client->test($id)) {
                        $stats['failed']++;
                        Log::info('Channel recovery test failed', ['channel_id' => $id]);
                        continue;
                    }

                    $stats['healthy']++;
                    if ($dryRun) {
                        continue;
                    }

                    $stage = 'enable';
                    if ($this->client->enableIfEligible($id)) {
                        $stats['recovered']++;
                        Log::info('Channel recovered', ['channel_id' => $id]);
                    } else {
                        $stats['skipped']++;
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    Log::warning('Channel recovery failed', [
                        'channel_id' => $id,
                        'stage' => $stage,
                        'error' => $e instanceof RuntimeException ? $e->getMessage() : get_class($e),
                    ]);
                }
            }
        } finally {
            $lock->release();
        }

        Log::info('Channel recovery completed', array_merge(['dry_run' => $dryRun], $stats));

        return $stats;
    }
}
