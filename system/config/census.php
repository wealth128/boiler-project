<?php

/*
|--------------------------------------------------------------------------
| Patient Census settings
|--------------------------------------------------------------------------
|
| Project-specific values read from .env. Code reads them with
| config('census.*') so they keep working after `php artisan config:cache`.
|
*/

return [

    // Every signed-in user is signed out at this time each day (24-hour
    // "HH:MM", Asia/Manila). Leave empty to turn it off. Apart from this,
    // a session only ends when the user clicks Log out (no idle timeout).
    // See App\Http\Middleware\EndSessionAtDailyCutoff.
    'session_cutoff' => env('SESSION_DAILY_CUTOFF', '20:00'),

    // Daily database backup (php artisan backup:run, scheduled at 02:00).
    // On the hospital server, BACKUP_PATH is the shared folder on the second
    // PC, e.g. //BACKUP-PC/census-backups (planning/architecture.md). In .env,
    // write Windows paths with forward slashes, or in single quotes.
    'backup' => [
        'mysqldump_path' => env('MYSQLDUMP_PATH') ?: 'mysqldump',
        'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),
        'keep_days' => 30,
        // The picture files (logo, signatures, frozen signature folders) that
        // every backup zips next to the database dump.
        'files_path' => storage_path('app/private'),
        // The mysql program (to load a backup). Empty = the one next to mysqldump.
        // census:restore can set it for one run when that one is not found.
        'mysql_path' => env('MYSQL_PATH') ?: null,
    ],

    // Shown on the System health page and saved in the audit log by
    // census:update. Change it with each release.
    'version' => '1.0.0',

    // Files the setup commands (census:install, census:update,
    // census:restore) read and write. Only tests change these.
    'paths' => [
        'env' => base_path('.env'),
        'env_example' => base_path('.env.example'),
        'install_log' => storage_path('logs/install.log'),
    ],

    // How long close / reopen month wait for the year lock (another close of the
    // same year is running) before saying "wait a moment".
    'lock_wait_seconds' => 5,

    // The scheduler saves a "last ran" time every minute. Older than this
    // many minutes, Admin sees a red bar (planning/deployment-handover.md).
    'scheduler_stale_minutes' => 5,

    // Passwords for the first Admin and System Admin accounts. Only the
    // seeder reads them. There is no fallback: seeding stops if they are empty.
    'seed' => [
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
        'sysadmin_password' => env('SEED_SYSADMIN_PASSWORD'),

        // Development only (DevUserSeeder, APP_ENV=local). Leave empty on
        // the hospital server.
        'encoder_password' => env('SEED_ENCODER_PASSWORD'),
        'viewer_password' => env('SEED_VIEWER_PASSWORD'),
    ],

];
