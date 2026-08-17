<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Daily at 9:00 AM - Scan due tasks and send reminders
        $schedule->call(function () {
            // app(\App\Services\TaskService::class)->scanAndSendDueReminders();
        })->dailyAt('09:00')->name('reminders:scan-due');

        // Daily at 3:00 AM - Clean up recycle bin
        $schedule->command('recycle:cleanup --days=30')
            ->dailyAt('03:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
