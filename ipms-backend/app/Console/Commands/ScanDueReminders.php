<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScanDueReminders extends Command
{
    protected $signature = 'reminders:scan-due';
    protected $description = 'Scan due tasks and requirements, dispatch reminder emails';

    public function handle(): int
    {
        $this->info('Scanning due tasks...');

        // TODO: Implement TaskService::scanAndSendDueReminders()

        $this->info('Scan complete.');
        return self::SUCCESS;
    }
}
