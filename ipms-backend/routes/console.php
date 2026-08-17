<?php

use Illuminate\Support\Facades\Schedule;

// Every day at 9:00 AM - Scan due tasks and send reminders
Schedule::call(function () {
    // TaskService::scanAndSendDueReminders() - to be implemented
})->dailyAt('09:00')->name('reminders:scan-due');

// Every day at 3:00 AM - Clean up recycle bin (documents deleted >30 days ago)
Schedule::command('recycle:cleanup --days=30')
    ->dailyAt('03:00')
    ->withoutOverlapping();

