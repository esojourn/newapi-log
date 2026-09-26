<?php

namespace App\Console\Commands;

use App\Models\ChannelRecoverySetting;
use App\Services\ChannelRecoveryChecker;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class RecoverChannels extends Command
{
    protected $signature = 'channels:recover
                            {--dry-run : 实际测试渠道，但不启用}
                            {--scheduled : 由调度器调用，仅在已保存的检查计划到期时执行}
                            {--channel-id= : 只检查指定渠道，仍须满足自动恢复条件}';

    protected $description = '检测开启自动封禁且已自动禁用的渠道，测试成功后恢复启用';

    public function handle(ChannelRecoveryChecker $checker): int
    {
        $channelId = $this->option('channel-id');
        if ($channelId !== null && (!ctype_digit($channelId) || (int) $channelId < 1)) {
            $this->error('--channel-id 必须是正整数。');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('试运行：实际调用渠道测试接口（可能产生用量并更新测试时间），但不启用渠道。');
        }

        try {
            if (!ChannelRecoverySetting::current()->enabled) {
                $this->info('渠道自动恢复未开启，请在管理员渠道恢复设置中开启。');

                return self::SUCCESS;
            }

            $stats = $checker->run($dryRun, $channelId === null ? null : (int) $channelId, (bool) $this->option('scheduled'));
        } catch (QueryException $e) {
            $this->error('数据库访问失败，请检查本地 alerts 库的迁移与写入权限，以及 NewAPI channels 表的只读连接。');

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
