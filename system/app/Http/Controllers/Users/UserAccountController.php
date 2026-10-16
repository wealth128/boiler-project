<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ResetPasswordRequest;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\AuditLogger;
use App\Services\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Account actions: unlock, reset password, activate / deactivate
 * (planning/rbac.md). Each writes one clear audit entry ("user.unlocked",
 * "user.password_reset", "user.activated", "user.deactivated") instead of a
 * generic "user.updated".
 *
 * A user who is locked or deactivated while signed in is signed out on
 * their next click (Http/Middleware/EndSessionWhenAccessRemoved).
 */
class UserAccountController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function unlock(User $user): RedirectResponse
    {
        Gate::authorize('unlock', $user);

        if ($user->locked_at === null) {
            return $this->done('info', "{$user->username} is not locked.");
        }

        DB::transaction(function () use ($user): void {
            $attempts = $user->failed_attempts;

            AuditObserver::muted(fn () => $user->forceFill(['failed_attempts' => 0, 'locked_at' => null])->save());

            $this->audit->log('user.unlocked', $user, ['failed_attempts' => $attempts]);
        });

        return $this->done('success', "{$user->username} unlocked.");
    }

    /**
     * Set a temporary password. It also clears a login lock: a user who
     * forgot their password is often locked out too, and it signs the user
     * out of every session they have.
     */
    public function resetPassword(ResetPasswordRequest $request, User $user, UserSessions $sessions): RedirectResponse
    {
        $wasLocked = $user->locked_at !== null;

        DB::transaction(function () use ($request, $user, $wasLocked): void {
            AuditObserver::muted(fn () => $user->forceFill(['failed_attempts' => 0, 'locked_at' => null])
                ->setTemporaryPassword($request->string('password')->value()));

            $this->audit->log('user.password_reset', $user, ['unlocked' => $wasLocked, 'temporary' => true]);
        });

        // Whoever held the old password is signed out everywhere, at once.
        $sessions->endAll($user);

        return $this->done('success', "Password of {$user->username} reset".($wasLocked ? ' and account unlocked' : '').'. Give it to the user privately. They must choose their own when they log in.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        Gate::authorize('toggleActive', $user);

        $active = ! $user->is_active;

        DB::transaction(function () use ($user, $active): void {
            AuditObserver::muted(fn () => $user->forceFill(['is_active' => $active])->save());

            $this->audit->log($active ? 'user.activated' : 'user.deactivated', $user);
        });

        return $this->done('success', $active ? "{$user->username} activated." : "{$user->username} deactivated. If signed in, they are signed out on their next click.");
    }

    private function done(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('users.index');
    }
}
