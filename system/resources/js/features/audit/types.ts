import type { DateTimeString } from '@/types';

/*
 * Data the Audit log screen receives (AuditLogController / AuditRows).
 */

/** Filters as they are in the URL. "" = All / no limit. */
export type AuditFilters = {
    /** "2026-09-01" */
    from: string;
    to: string;
    /** User id as text. */
    user: string;
    /** Action code, e.g. "visit.created". */
    action: string;
    /** Visit No., Patient ID or username. */
    record: string;
};

export type AuditActionGroup = {
    group: string;
    options: { value: string; label: string }[];
};

export type AuditUserOption = {
    id: number;
    label: string;
};

/** One "Field: old → new" line. `old` is null for a new record or a plain value. */
export type AuditDetail = {
    field: string;
    old: string | null;
    new: string;
};

/** One row of the log. */
export type AuditEntry = {
    id: number;
    created_at: DateTimeString;
    /** null when the system did it (e.g. the scheduled backup). */
    username: string | null;
    user_name: string | null;
    /** The action code, e.g. "visit.created". */
    action: string;
    /** Plain-language name, or the code itself when it is not known. */
    action_label: string;
    /** "V-000123", "P-000012 · Santos, Maria L.", "September 2026"... */
    record: string;
    ip_address: string | null;
    details: AuditDetail[];
};
