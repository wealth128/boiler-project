<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Account actions: unlock, reset password, activate / deactivate.
 */
class UserAccountController extends Controller
{
    use StubResponses;

    public function unlock(User $user): RedirectResponse
    {
        Gate::authorize('unlock', $user);

        return $this->notBuiltYet("Unlock {$user->username}");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        Gate::authorize('resetPassword', $user);

        return $this->notBuiltYet("Reset password of {$user->username}");
    }

    public function toggleActive(User $user): RedirectResponse
    {
        Gate::authorize('toggleActive', $user);

        return $this->notBuiltYet("Activate / deactivate {$user->username}");
    }
}
