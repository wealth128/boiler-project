<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Account actions: unlock, reset password, activate / deactivate.
 */
class UserAccountController extends Controller
{
    use StubResponses;

    public function unlock(User $user): RedirectResponse
    {
        return $this->notBuiltYet("Unlock {$user->username}");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        return $this->notBuiltYet("Reset password of {$user->username}");
    }

    public function toggleActive(User $user): RedirectResponse
    {
        return $this->notBuiltYet("Activate / deactivate {$user->username}");
    }
}
