import type { User } from '@/types/models';

/**
 * The signed-in account as every page receives it (HandleInertiaRequests).
 * Only these five fields are shared; the rest of the user record stays on
 * the server.
 */
export type AuthUser = Pick<
    User,
    'id' | 'name' | 'username' | 'role' | 'office'
>;

export type Auth = {
    /** The signed-in account. Pages behind login always have one. */
    user: AuthUser;
    /**
     * New on every sign-in (null when signed out). A closed red warning bar
     * stays hidden only while this value is the same.
     */
    loginAt: string | null;
};

/** Hospital header text from the settings table, shared with every page. */
export type Hospital = {
    name: string;
    subtitle: string;
};
