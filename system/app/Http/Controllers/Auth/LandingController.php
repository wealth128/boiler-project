<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "/": signed-out users go to the login page, signed-in users to their
 * role's landing page (Role::landingRoute()). Encoder and Admin: Encode,
 * Viewer (CO): Reports, System Admin: User Accounts.
 */
class LandingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        return redirect()->route($user->role->landingRoute());
    }
}
