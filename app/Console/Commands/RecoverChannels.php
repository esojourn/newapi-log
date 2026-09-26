<?php

namespace App\Console\Commands;

use App\Services\ChannelRecoveryChecker;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class RecoverChannels extends Command
{
    protected $signature = 'channels:recover
                            {--dry-run : 实际测试渠道，但不启用}
                            {--channel-id= : 只检查指定渠道，仍须满足自动恢复条件}';

    protected $description = '检测开启自动封禁且已自动禁用的渠道，测试成功后恢复启用';

    public function handle(ChannelRecoveryChecker $checker): int
    {
        $channelId = $this->option('channel-id');
        if ($channelId !== null && (!ctype_digit($channelId) || (int) $channelId < 1)) {
            $this->error('--channel-id 必须是正整数。');

            return self::FAILURE;
        }

        if (!config('channels.recovery_enabled')) {
            $this->info('渠道自动恢复未开启，请配置 CHANNEL_RECOVERY_ENABLED=true。');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('试运行：实际调用渠道测试接口（可能产生用量并更新测试时间），但不启用渠道。');
        }

        try {
            $stats = $checker->run($dryRun, $channelId === null ? null : (int) $channelId);
        } catch (QueryException $e) {
            $this->error('读取渠道失败，请检查 NewAPI 数据库连接和 channels 表的 SELECT 权限。');

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['检查', '测试正常', '已恢复', '状态变化跳过', '失败'],
            [[$stats['checked'], $stats['healthy'], $stats['recovered'], $stats['skipped'], $stats['failed']]]
        );

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
