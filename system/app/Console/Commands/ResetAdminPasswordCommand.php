<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * php artisan census:reset-admin-password
 *
 * For IT, on the server, when the Admin forgot their password. The System
 * Admin can't reset the Admin's password in the app (planning/rbac.md).
 *
 * Asks for the new password twice (it is never shown or saved anywhere
 * else), also clears a login lock, and writes "user.password_reset" to the
 * audit log. Nobody is signed in on the server, so the entry has no user;
 * "via" says it came from this command.
 */
class ResetAdminPasswordCommand extends Command
{
    protected $signature = 'census:reset-admin-password
                            {--username= : The Admin account, if there is more than one}';

    protected $description = "Reset the Admin account's password (for IT on the server)";

    public function handle(AuditLogger $audit): int
    {
        $admin = $this->findAdmin();

        if ($admin === null) {
            return self::FAILURE;
        }

        $this->line("Admin account: {$admin->name} ({$admin->username})".($admin->is_active ? '' : ', deactivated'));

        if (! $this->confirm('Set a new password for this account?', true)) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $password = (string) $this->secret('New password');
        $confirmation = (string) $this->secret('Type the new password again');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'confirmed', Password::defaults()]],
            ['password.confirmed' => 'The two passwords do not match.'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $wasLocked = $admin->locked_at !== null;

        DB::transaction(function () use ($admin, $password, $wasLocked, $audit): void {
            // One clear entry instead of a generic "user.updated".
            AuditObserver::muted(fn () => $admin->forceFill([
                'password' => $password, // hashed by the model cast
                'failed_attempts' => 0,
                'locked_at' => null,
            ])->save());

            $audit->log('user.password_reset', $admin, [
                'via' => 'census:reset-admin-password',
                'unlocked' => $wasLocked,
            ]);
        });

        $this->info('Password changed'.($wasLocked ? ' and account unlocked' : '').'. Give the new password to the Admin privately.');

        return self::SUCCESS;
    }

    private function findAdmin(): ?User
    {
        $username = $this->option('username');

        $query = User::query()->where('role', Role::Admin);

        if (is_string($username) && $username !== '') {
            $admin = $query->where('username', $username)->first();

            if ($admin === null) {
                $this->error("No Admin account with the username \"{$username}\".");
            }

            return $admin;
        }

        $admins = (clone $query)->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $admins = $query->get();
        }

        if ($admins->count() === 1) {
            return $admins->first();
        }

        $this->error($admins->isEmpty()
            ? 'There is no Admin account.'
            : 'There is more than one Admin account. Run it again with --username=<username>.');

        return null;
    }
}
