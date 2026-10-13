<?php

namespace App\Http\Controllers\Users;

use App\Actions\Fortify\AuthenticateUser;
use App\Enums\Office;
use App\Enums\Role;
use App\Enums\UserDeleteReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use App\Services\TrashService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User Accounts screen (prototype/prototype.html, "User accounts"):
 * list, add, edit and move to Trash. Unlock, reset password and
 * activate / deactivate are in UserAccountController.
 *
 * Rules: UserPolicy (planning/rbac.md). Audit entries "user.created",
 * "user.updated" and "user.deleted" come from AuditObserver.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $gate = Gate::forUser($actor);

        $users = User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role->value,
                'office' => $user->office->value,
                'is_active' => $user->is_active,
                'is_locked' => $user->locked_at !== null,
                'must_change_password' => $user->must_change_password,
                'is_self' => $user->is($actor),
                // What this signed-in user may do to this row (UserPolicy).
                'can' => [
                    'update' => $gate->allows('update', $user),
                    'change_role' => $gate->allows('changeRole', $user),
                    'delete' => $gate->allows('delete', $user),
                    'unlock' => $user->locked_at !== null && $gate->allows('unlock', $user),
                    'reset_password' => $gate->allows('resetPassword', $user),
                    'toggle_active' => $gate->allows('toggleActive', $user),
                ],
            ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'roles' => array_map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'office' => $role->fixedOffice()?->value,
            ], Role::cases()),
            'encoderOffices' => array_map(fn (Office $office) => [
                'value' => $office->value,
                'label' => $office->label(),
                'available' => $office->encodingOn(),
            ], Office::forEncoders()),
            'maxAttempts' => AuthenticateUser::MAX_ATTEMPTS,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'deleteReasons' => UserDeleteReason::options(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only(['name', 'username', 'role', 'office']));
        $user->is_active = true;
        $user->setTemporaryPassword($request->string('password')->value());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Account {$user->username} added. Give the temporary password to the user privately.",
        ]);

        return to_route('users.index');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->safe()->only(['name', 'username', 'role', 'office']));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Account {$user->username} saved."]);

        return to_route('users.index');
    }

    /**
     * Move a user account to Trash with a reason from UserDeleteReason. Restore and permanent
     * delete are on the Trash screen.
     */
    public function destroy(Request $request, User $user, TrashService $trash): RedirectResponse
    {
        Gate::authorize('delete', $user);

        /** @var User $actor */
        $actor = $request->user();
        $trash->moveToTrash($user, $actor, $request->input('reason'), $request->input('reason_other'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Account {$user->username} moved to Trash."]);

        return to_route('users.index');
    }
}
