<?php

namespace App\Enums;

/**
 * Page-level permissions (planning/rbac.md, permission matrix). Each case is
 * registered as a Gate with the same name in AppServiceProvider, e.g.
 * Gate::allows('manage-users').
 *
 * resources/js/lib/permissions.ts must list the same names and roles: the
 * menu uses it to hide pages. tests/Feature/Rbac/GateTest.php fails when the
 * two drift apart.
 *
 * Rules that depend on one record (edit own visit on the same day, closed
 * month, can't delete the Admin...) are not here. They live in the Policies.
 */
enum Permission: string
{
    case EncodeVisits = 'encode-visits';
    case ViewRecords = 'view-records';
    case ViewReports = 'view-reports';
    case CloseMonth = 'close-month';
    case ManageLists = 'manage-lists';
    case ManageUsers = 'manage-users';
    case ViewAudit = 'view-audit';
    case ManageTrash = 'manage-trash';
    case ViewBackups = 'view-backups';

    /**
     * Roles that have this permission.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        return match ($this) {
            self::EncodeVisits => [Role::Encoder, Role::Admin],
            self::ViewReports => [Role::Admin, Role::Viewer],
            self::ManageUsers => [Role::Admin, Role::SystemAdmin],
            self::ViewRecords,
            self::CloseMonth,
            self::ManageLists,
            self::ViewAudit,
            self::ManageTrash,
            self::ViewBackups => [Role::Admin],
        };
    }

    public function allows(Role $role): bool
    {
        return in_array($role, $this->roles(), true);
    }
}
