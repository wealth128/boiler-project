<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

class UserController extends Controller
{
    use StubResponses;

    public function index(): Response
    {
        return $this->placeholder('Users', 'User accounts: add, edit, deactivate, reset password, unlock.');
    }

    public function store(): RedirectResponse
    {
        return $this->notBuiltYet('Add user');
    }

    public function update(User $user): RedirectResponse
    {
        return $this->notBuiltYet("Edit user {$user->username}");
    }

    /**
     * Move a user account to Trash (reason required).
     */
    public function destroy(User $user): RedirectResponse
    {
        return $this->notBuiltYet("Delete user {$user->username}");
    }
}
