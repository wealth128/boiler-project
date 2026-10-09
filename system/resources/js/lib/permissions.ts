import type { Role } from '@/types';

/*
 * Which roles may do what (planning/rbac.md, permission matrix).
 *
 * The screens use this only to show or hide menu items and buttons.
 * The server is what actually blocks access (role middleware and
 * Policies), so keep both in step when a rule changes.
 */
export const PERMISSIONS = {
    'visits.encode': ['encoder', 'admin'],
    'patients.search': ['encoder', 'admin'],
    'visits.edit-any': ['admin'],
    'records.view': ['admin'],
    'records.delete': ['admin'],
    'reports.view': ['admin', 'viewer'],
    'months.manage': ['admin'],
    'lists.manage': ['admin'],
    'users.manage': ['admin', 'system_admin'],
    'audit.view': ['admin'],
    'trash.manage': ['admin'],
    'backups.view': ['admin'],
} as const satisfies Record<string, readonly Role[]>;

export type Permission = keyof typeof PERMISSIONS;

export function roleCan(
    role: Role | null | undefined,
    permission: Permission,
): boolean {
    if (!role) {
        return false;
    }

    return (PERMISSIONS[permission] as readonly Role[]).includes(role);
}
