<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ends a user's other logins by deleting their rows from the sessions table
 * (the app uses the database session driver). Used when a password is reset
 * by Admin or System Admin or by IT on the server, so a person who held the
 * old password is signed out at once, and when someone changes their own
 * password, so other PCs that were signed in are signed out while the
 * current one stays.
 *
 * With any other session driver nothing can be listed here, so nothing is
 * deleted (the deployment uses the database driver, see .env).
 */
class UserSessions
{
    /** Sign the user out everywhere. Returns how many sessions ended. */
    public function endAll(User $user): int
    {
        return $this->query($user)->delete();
    }

    /** Sign the user out everywhere except the session with this id. */
    public function endOthers(User $user, string $currentSessionId): int
    {
        return $this->query($user)->where('id', '!=', $currentSessionId)->delete();
    }

    private function query(User $user): Builder
    {
        if (config('session.driver') !== 'database') {
            // Nothing to list: an impossible match deletes nothing.
            return DB::table(config('session.table', 'sessions'))->whereRaw('1 = 0');
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id);
    }
}
