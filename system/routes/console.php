<?php

use App\Services\BackupMonitor;
use App\Services\Import\ImportFileStore;
use App\Services\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. On the server, Windows Task Scheduler runs
| "php artisan schedule:run" every minute (planning/architecture.md).
*/

// Daily database backup at 2:00 AM (Asia/Manila).
Schedule::command('backup:run')
    ->dailyAt(BackupMonitor::DAILY_AT)
    ->withoutOverlapping();

// Automatic test restore on the 1st of each month at 3:00 AM, after the 2:00 AM
// backup. Loads the latest backup into a scratch database and compares the counts.
Schedule::command('backup:test-restore')
    ->monthlyOn(1, BackupMonitor::TEST_RESTORE_AT)
    ->withoutOverlapping();

// Heartbeat: proves the scheduler itself is running. Admin sees a red bar
// when it is more than 5 minutes old (App\Services\SchedulerHeartbeat).
Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())
    ->name('scheduler-heartbeat')
    ->everyMinute();

// Uploaded import files wait at most an hour (Records > Import). Every 15 minutes anything
// older is deleted, also when nobody opens the import page again (so no file stays more than about 75 minutes).
Schedule::call(fn () => app(ImportFileStore::class)->purgeExpired())
    ->name('import-files-purge')
    ->everyFifteenMinutes();
