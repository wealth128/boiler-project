<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Change my own password". The form asks for the current password, so the
 * page needs no extra password-confirmation step.
 */
class SecurityController extends Controller
{
    /**
     * Show the change-password page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/security', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request, AuditLogger $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user, $request, $audit): void {
            // One clear entry instead of a generic "user.updated".
            AuditObserver::muted(fn () => $user->update([
                'password' => $request->password,
            ]));

            $audit->log('user.password_changed', $user, ['was_temporary' => false], by: $user);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
