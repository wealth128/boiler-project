<?php

use App\Services\BackupMonitor;
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

// Heartbeat: proves the scheduler itself is running. Admin sees a red bar
// when it is more than 5 minutes old (App\Services\SchedulerHeartbeat).
Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())
    ->name('scheduler-heartbeat')
    ->everyMinute();
