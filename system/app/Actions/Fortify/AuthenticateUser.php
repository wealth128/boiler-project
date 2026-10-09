<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks a login (username + password) for Fortify. Rules from CLAUDE.md
 * and planning/rbac.md:
 *
 * - Unknown and soft-deleted (Trash) accounts get "wrong username or
 *   password" with no attempt count, since there is no counter to show.
 * - A locked account is refused before the password is checked.
 * - Each wrong password adds 1 to failed_attempts. At 5 the account locks
 *   (locked_at is set) and "user.locked" goes to the audit log.
 * - A deactivated account is refused even with the right password.
 * - A successful login resets failed_attempts to 0 and writes "user.login".
 *
 * The counter is changed with plain queries, not Eloquent saves, so that
 * the audit observers added later do not log every wrong password as a
 * user edit, and updated_at keeps meaning "account details changed".
 */
class AuthenticateUser
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private AuditLogger $audit) {}

    public function __invoke(Request $request): User
    {
        $username = Str::lower(trim((string) $request->input('username')));
        $password = (string) $request->input('password');

        // The default query leaves out soft-deleted users.
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->fail('Wrong username or password.');
        }

        if ($user->locked_at !== null) {
            $this->fail(self::lockedMessage());
        }

        if (! Hash::check($password, $user->password)) {
            $this->recordWrongPassword($user);
        }

        if (! $user->is_active) {
            $this->fail('This account is deactivated. Ask the Admin or System Admin.');
        }

        if ($user->failed_attempts > 0) {
            DB::table('users')->where('id', $user->id)->update(['failed_attempts' => 0]);
            $user->failed_attempts = 0;
            $user->syncOriginalAttribute('failed_attempts');
        }

        $this->audit->log('user.login', $user, by: $user);

        return $user;
    }

    public static function lockedMessage(): string
    {
        return 'This account is locked after '.self::MAX_ATTEMPTS.' wrong passwords. Ask the Admin or System Admin to unlock it.';
    }

    /**
     * Count the wrong password, lock the account at the limit, then refuse.
     */
    private function recordWrongPassword(User $user): never
    {
        // One atomic update, so two wrong tries at the same moment both count.
        DB::table('users')->where('id', $user->id)->increment('failed_attempts');
        $attempts = (int) DB::table('users')->where('id', $user->id)->value('failed_attempts');

        if ($attempts < self::MAX_ATTEMPTS) {
            $left = self::MAX_ATTEMPTS - $attempts;

            $this->fail('Wrong username or password. '.$left.' '.Str::plural('attempt', $left).' left before the account locks.');
        }

        // "whereNull" makes sure only one request locks it and writes the log.
        $locked = DB::table('users')
            ->where('id', $user->id)
            ->whereNull('locked_at')
            ->update(['locked_at' => now()]);

        if ($locked === 1) {
            $this->audit->log('user.locked', $user, ['failed_attempts' => $attempts], by: $user);
        }

        $this->fail(self::lockedMessage());
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['username' => $message]);
    }
}
