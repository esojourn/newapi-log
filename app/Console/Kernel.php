<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 余额预警。频率由 config('alerts.schedule_cron') 决定（ALERT_CHECK_CRON）。
        // 需要服务器上有一条 * * * * * php artisan schedule:run 才会真正跑起来。
        $schedule->command('alerts:check')
            ->cron(config('alerts.schedule_cron'))
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
