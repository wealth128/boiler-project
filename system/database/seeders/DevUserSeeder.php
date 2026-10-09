<?php

namespace Database\Seeders;

use App\Enums\Office;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ONLY: one test account for each role that AccountSeeder does
 * not create, so every role can be tried on a dev PC:
 *
 * - "encoder" (ER Encoder), password SEED_ENCODER_PASSWORD
 * - "viewer"  (Viewer / CO), password SEED_VIEWER_PASSWORD
 *
 * Admin and System Admin are the "admin" and "sysadmin" accounts from
 * AccountSeeder. DatabaseSeeder runs this only when APP_ENV=local, so it
 * never runs on the hospital server. To run it by hand:
 *   php artisan db:seed --class=DevUserSeeder
 *
 * An account whose password is not set in .env is skipped with a warning.
 * Existing accounts are left untouched.
 */
class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DevUserSeeder does not run in production.');

            return;
        }

        $this->seedAccount('encoder', 'Test Encoder (ER)', Role::Encoder, Office::ER, config('census.seed.encoder_password'), 'SEED_ENCODER_PASSWORD');
        $this->seedAccount('viewer', 'Test Viewer (CO)', Role::Viewer, Office::Command, config('census.seed.viewer_password'), 'SEED_VIEWER_PASSWORD');
    }

    private function seedAccount(string $username, string $name, Role $role, Office $office, mixed $password, string $envKey): void
    {
        if (User::withTrashed()->where('username', $username)->exists()) {
            $this->command?->info("Account \"{$username}\" already exists; left unchanged.");

            return;
        }

        if (! is_string($password) || trim($password) === '') {
            $this->command?->warn("{$envKey} is empty in .env; test account \"{$username}\" was not created.");

            return;
        }

        User::create([
            'name' => $name,
            'username' => $username,
            'password' => $password, // hashed by the model cast
            'role' => $role,
            'office' => $office,
            'is_active' => true,
        ]);
    }
}
