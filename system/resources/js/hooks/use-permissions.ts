import { usePage } from '@inertiajs/react';
import { roleCan } from '@/lib/permissions';
import type { Permission } from '@/lib/permissions';
import type { Role } from '@/types';

export type UsePermissionsReturn = {
    /** The signed-in user's role, or null on guest pages. */
    role: Role | null;
    /** True when the user has one of the given roles. */
    is: (...roles: Role[]) => boolean;
    /** True when the user's role allows the action (lib/permissions). */
    can: (permission: Permission) => boolean;
};

/**
 * Role checks for showing or hiding parts of a screen, e.g.
 * `const { can } = usePermissions(); can('trash.manage')`.
 */
export function usePermissions(): UsePermissionsReturn {
    const { auth } = usePage().props;
    // Guest pages (login) have no user even though the type says otherwise.
    const role = (auth.user as typeof auth.user | null)?.role ?? null;

    return {
        role,
        is: (...roles) => role !== null && roles.includes(role),
        can: (permission) => roleCan(role, permission),
    };
}
