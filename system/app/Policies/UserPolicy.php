<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * User account actions (planning/rbac.md). Admin and System Admin manage
 * accounts, with these rules:
 *
 * - Nobody can delete their own account or the Admin account.
 * - Nobody can change their own role.
 * - The Admin account is protected from the System Admin: no edit, no role
 *   change, no deactivate, no password reset. (Unlock is allowed.) If the
 *   Admin forgets the password, IT runs `php artisan
 *   census:reset-admin-password` on the server.
 * - Only one active Admin: making an account Admin, or activating an Admin
 *   account, is refused while another active Admin exists.
 * - Nobody can deactivate their own account (it would lock them out).
 * - Nobody resets their own password here; they use Settings > Password.
 *
 * Restoring a user from Trash and permanent delete are in TrashPolicy.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return self::manages($actor);
    }

    public function create(User $actor): bool
    {
        return self::manages($actor);
    }

    public function update(User $actor, User $target): Response
    {
        if (! self::manages($actor)) {
            return Response::deny();
        }

        return self::adminProtected($actor, $target) ?? Response::allow();
    }

    /**
     * Change an account's role. Check this when an edit form changes it.
     */
    public function changeRole(User $actor, User $target): Response
    {
        if (! self::manages($actor)) {
            return Response::deny();
        }

        if ($actor->is($target)) {
            return Response::deny("You can't change your own role.");
        }

        return self::adminProtected($actor, $target) ?? Response::allow();
    }

    public function delete(User $actor, User $target): Response
    {
        if (! self::manages($actor)) {
            return Response::deny();
        }

        if ($actor->is($target)) {
            return Response::deny("You can't delete your own account.");
        }

        if ($target->hasRole(Role::Admin)) {
            return Response::deny("The Admin account can't be deleted.");
        }

        return Response::allow();
    }

    /**
     * Allowed on every account, the Admin's too: a locked-out Admin needs
     * the System Admin to unlock them.
     */
    public function unlock(User $actor, User $target): bool
    {
        return self::manages($actor);
    }

    public function resetPassword(User $actor, User $target): Response
    {
        if (! self::manages($actor)) {
            return Response::deny();
        }

        if ($actor->is($target)) {
            return Response::deny('To change your own password, use Settings > Password.');
        }

        if (self::adminProtected($actor, $target)) {
            return Response::deny("The System Admin can't reset the Admin's password. If the Admin forgot it, IT resets it on the server.");
        }

        return Response::allow();
    }

    /**
     * Activate or deactivate the account (the route is a toggle).
     */
    public function toggleActive(User $actor, User $target): Response
    {
        if (! self::manages($actor)) {
            return Response::deny();
        }

        if ($actor->is($target)) {
            return Response::deny("You can't deactivate your own account.");
        }

        if ($protected = self::adminProtected($actor, $target)) {
            return $protected;
        }

        // Activating a deactivated Admin would make a second active Admin.
        if (! $target->is_active && $target->hasRole(Role::Admin) && User::activeAdminExists(except: $target)) {
            return self::oneAdminOnly();
        }

        return Response::allow();
    }

    /**
     * Give an account the Admin role: a new account ($target null) or an
     * existing one. Check this when a create or edit form picks "Admin".
     */
    public function makeAdmin(User $actor, ?User $target = null): Response
    {
        if (! self::manages($actor)) {
            return Response::deny();
        }

        if (User::activeAdminExists(except: $target)) {
            return self::oneAdminOnly();
        }

        return Response::allow();
    }

    public static function oneAdminOnly(): Response
    {
        return Response::deny('There is already an active Admin account. Only one is allowed.');
    }

    /**
     * The System Admin may not change the Admin account.
     */
    private static function adminProtected(User $actor, User $target): ?Response
    {
        if ($actor->hasRole(Role::SystemAdmin) && $target->hasRole(Role::Admin)) {
            return Response::deny("The System Admin can't change the Admin account.");
        }

        return null;
    }

    private static function manages(User $actor): bool
    {
        return Permission::ManageUsers->allows($actor->role);
    }
}
