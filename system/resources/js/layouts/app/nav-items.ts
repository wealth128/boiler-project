import type { InertiaLinkProps } from '@inertiajs/react';
import type { Permission } from '@/lib/permissions';
import audit from '@/routes/audit';
import backups from '@/routes/backups';
import encode from '@/routes/encode';
import lists from '@/routes/lists';
import records from '@/routes/records';
import reports from '@/routes/reports';
import trash from '@/routes/trash';
import users from '@/routes/users';

export type AppNavGroup = 'work' | 'admin';

export type AppNavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /** Who sees the item (lib/permissions). */
    permission: Permission;
    /** Highlight the item on this path and anything under it. */
    activePath: string;
    group: AppNavGroup;
};

/** Section headings in the sidebar. `null` = no heading. */
export const APP_NAV_GROUPS: { key: AppNavGroup; label: string | null }[] = [
    { key: 'work', label: null },
    { key: 'admin', label: 'Administration' },
];

/**
 * Left sidebar menu (planning/rbac.md). Each role sees only its items:
 * - Encoder: Encode Patient
 * - Admin: everything
 * - System Admin: User Accounts
 * - Viewer (CO): Reports
 */
export const APP_NAV_ITEMS: AppNavItem[] = [
    {
        title: 'Encode Patient',
        href: encode.index(),
        permission: 'encode-visits',
        activePath: '/encode',
        group: 'work',
    },
    {
        title: 'Patient Records',
        href: records.visits(),
        permission: 'view-records',
        activePath: '/records',
        group: 'work',
    },
    {
        title: 'Reports',
        href: reports.index(),
        permission: 'view-reports',
        activePath: '/reports',
        group: 'work',
    },
    {
        title: 'User Accounts',
        href: users.index(),
        permission: 'manage-users',
        activePath: '/users',
        group: 'admin',
    },
    {
        title: 'Dropdown Lists',
        href: lists.index(),
        permission: 'manage-lists',
        activePath: '/settings/lists',
        group: 'admin',
    },
    {
        title: 'Audit Log',
        href: audit.index(),
        permission: 'view-audit',
        activePath: '/audit-log',
        group: 'admin',
    },
    {
        title: 'Trash',
        href: trash.index(),
        permission: 'manage-trash',
        activePath: '/trash',
        group: 'admin',
    },
    {
        title: 'Backups',
        href: backups.index(),
        permission: 'view-backups',
        activePath: '/backups',
        group: 'admin',
    },
];
