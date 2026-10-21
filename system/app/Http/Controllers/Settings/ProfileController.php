<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My profile": the signed-in user can change their own name only.
 * Username, role and office are managed by Admin / System Admin.
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/profile');
    }

    /**
     * Update the user's name.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // Only the name: never a role, a flag or a password, whatever else the form sends.
        $request->user()->update($request->safe()->only(['name']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
