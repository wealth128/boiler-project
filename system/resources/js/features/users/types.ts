import type { Office, Role } from '@/types';

/**
 * One row of the User Accounts table (Users\UserController@index). Only
 * what the screen needs: no password, lock counter or delete details.
 */
export type UserRow = {
    id: number;
    name: string;
    username: string;
    role: Role;
    office: Office;
    is_active: boolean;
    is_locked: boolean;
    /** Still has a temporary password set by Admin, System Admin or IT. */
    must_change_password: boolean;
    /** The signed-in user's own account. */
    is_self: boolean;
    /** What the signed-in user may do to this account (UserPolicy). */
    can: {
        update: boolean;
        change_role: boolean;
        delete: boolean;
        unlock: boolean;
        reset_password: boolean;
        toggle_active: boolean;
    };
};

export type RoleOption = {
    value: Role;
    label: string;
    /** Fixed office for this role; null for encoders, who pick one. */
    office: Office | null;
};

export type OfficeOption = {
    value: Office;
    label: string;
    /** False for offices whose encoding is off in v1.0 (OPD, Admission). */
    available: boolean;
};
