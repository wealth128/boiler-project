import type { Role } from '@/types';

/*
 * Which roles may open which pages (planning/rbac.md, permission matrix).
 *
 * Same names and roles as the server's Gates (app/Enums/Permission.php).
 * The screens use this only to show or hide menu items and buttons; the
 * server is what actually blocks access (role: middleware and Policies).
 * tests/Feature/Rbac/GateTest.php fails if this list and the Gates differ,
 * so change both together.
 *
 * Rules about one record (edit own visit on the same day, closed month,
 * can't delete the Admin...) are not here: the server decides those.
 */
export const PERMISSIONS = {
    'encode-visits': ['encoder', 'admin'],
    'view-records': ['admin'],
    'view-reports': ['admin', 'viewer'],
    'close-month': ['admin'],
    'manage-lists': ['admin'],
    'manage-users': ['admin', 'system_admin'],
    'view-audit': ['admin'],
    'manage-trash': ['admin'],
    'view-backups': ['admin'],
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
