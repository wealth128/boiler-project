<?php

use App\Services\BackupMonitor;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. On the server, Windows Task Scheduler runs
| "php artisan schedule:run" every minute (planning/architecture.md).
*/

// Daily database backup at 2:00 AM (Asia/Manila).
Schedule::command('backup:run')
    ->dailyAt(BackupMonitor::DAILY_AT)
    ->withoutOverlapping();
