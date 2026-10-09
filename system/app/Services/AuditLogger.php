<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes one row to audit_logs. Every create, update, delete, restore,
 * permanent delete, close/reopen, unlock, list change and login event goes
 * through here (CLAUDE.md). The model observers in Step 7 will use it too.
 *
 * Action names are "<subject>.<verb>", e.g. "user.login", "visit.created".
 */
class AuditLogger
{
    /**
     * @param  User|null  $by  Who did it. Defaults to the signed-in user. Pass it
     *                         when nobody is signed in yet (login, lock).
     * @param  array<string, mixed>|null  $changes  Old and new values, or other detail.
     */
    public function log(string $action, Model $subject, ?array $changes = null, ?User $by = null): AuditLog
    {
        $by ??= Auth::user();

        return AuditLog::create([
            'user_id' => $by?->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
            // No request when run from the scheduler or the command line.
            'ip_address' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
        ]);
    }
}
