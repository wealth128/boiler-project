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
