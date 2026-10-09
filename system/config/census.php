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

    // Passwords for the first Admin and System Admin accounts. Only the
    // seeder reads them. There is no fallback: seeding stops if they are empty.
    'seed' => [
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
        'sysadmin_password' => env('SEED_SYSADMIN_PASSWORD'),
    ],

];
