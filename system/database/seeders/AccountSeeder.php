<?php

namespace Database\Seeders;

use App\Enums\Office;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The first Admin (username "admin") and System Admin (username "sysadmin").
 * Passwords come from SEED_ADMIN_PASSWORD and SEED_SYSADMIN_PASSWORD in .env.
 * Existing accounts are left untouched, so re-seeding never resets a password.
 */
class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAccount(
            username: 'admin',
            name: 'Administrator',
            role: Role::Admin,
            office: Office::Admin,
            password: config('census.seed.admin_password'),
            envKey: 'SEED_ADMIN_PASSWORD',
        );

        $this->seedAccount(
            username: 'sysadmin',
            name: 'System Administrator',
            role: Role::SystemAdmin,
            office: Office::IT,
            password: config('census.seed.sysadmin_password'),
            envKey: 'SEED_SYSADMIN_PASSWORD',
        );
    }

    private function seedAccount(string $username, string $name, Role $role, Office $office, mixed $password, string $envKey): void
    {
        if (User::withTrashed()->where('username', $username)->exists()) {
            $this->command->info("Account \"{$username}\" already exists; left unchanged.");

            return;
        }

        // Only one active Admin may exist.
        if ($role === Role::Admin && User::where('role', Role::Admin)->where('is_active', true)->exists()) {
            $this->command->warn('An active Admin account already exists; "admin" was not created.');

            return;
        }

        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException("Set {$envKey} in .env before seeding (the password for the \"{$username}\" account).");
        }

        User::create([
            'name' => $name,
            'username' => $username,
            'email' => null,
            'password' => $password, // hashed by the model cast
            'role' => $role,
            'office' => $office,
            'is_active' => true,
        ]);
    }
}
