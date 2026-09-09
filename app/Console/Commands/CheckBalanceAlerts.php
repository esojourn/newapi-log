<?php

namespace App\Console\Commands;

use App\Services\AlertChecker;
use Illuminate\Console\Command;

/**
 * 余额预警检查，由 Console\Kernel 的 schedule 定时调用。
 *
 *   php artisan alerts:check              正常跑一轮
 *   php artisan alerts:check --dry-run    只判定不发送，也不写去重记录
 *   php artisan alerts:check --token-id=7 只检查某个 Key，排障用
 */
class CheckBalanceAlerts extends Command
{
    protected $signature = 'alerts:check
                            {--dry-run : 只判定不发送}
                            {--token-id= : 只检查指定的 tokens.id}';

    protected $description = '检查订阅 Key 的余额，低于阈值时推送飞书预警';

    public function handle(AlertChecker $checker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tokenId = $this->option('token-id');

        if ($dryRun) {
            $this->warn('干跑模式：只判定，不发送、不写去重记录');
        }

        $stats = $checker->run($dryRun, $tokenId === null ? null : (int) $tokenId);

        $this->table(
            ['检查', '用户推送', '管理员推送', '跳过', '失败'],
            [[$stats['checked'], $stats['user_sent'], $stats['admin_sent'], $stats['skipped'], $stats['failed']]]
        );

        // 有推送失败时给 cron / 调用方一个非零退出码
        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
