<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChoosePasswordRequest;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\AuditLogger;
use App\Services\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Choose your new password" (planning/rbac.md, temporary passwords). The
 * only page, with Log out, that a user with a temporary password can open
 * (Http/Middleware/RequirePasswordChange).
 */
class ChoosePasswordController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route($user->role->landingRoute());
        }

        return Inertia::render('auth/choose-password', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(ChoosePasswordRequest $request, AuditLogger $audit, UserSessions $sessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route($user->role->landingRoute());
        }

        DB::transaction(function () use ($user, $request, $audit): void {
            // One clear entry instead of a generic "user.updated".
            AuditObserver::muted(fn () => $user->forceFill([
                'password' => $request->string('password')->value(), // hashed by the cast
                'must_change_password' => false,
                'remember_token' => Str::random(60), // an old "remember me" cookie stops working
            ])->save());

            $audit->log('user.password_changed', $user, ['was_temporary' => true], by: $user);
        });

        // Other PCs signed in as this user are signed out; this one stays.
        $sessions->endOthers($user, $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password changed.']);

        return redirect()->route($user->role->landingRoute());
    }
}
