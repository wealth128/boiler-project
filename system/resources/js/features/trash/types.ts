import type { DateTimeString } from '@/types';

/*
 * Data the Trash screen receives (TrashController / TrashRows).
 */

/** The {type} in /trash/{type}/{id}. */
export type TrashType =
    | 'visits'
    | 'patients'
    | 'users'
    | 'diagnoses'
    | 'ranks'
    | 'age-brackets'
    | 'months';

/** One row of Trash. */
export type TrashItem = {
    /** Unique in the table, e.g. "visits-12". */
    key: string;
    type: TrashType;
    id: number;
    /** "Visit", "Patient", "Rank / type", "Month report"... */
    type_label: string;
    /** "V-000123", "P-000012 · Santos, Maria L.", "September 2026 · 40 visits"... */
    item: string;
    /** The reason chosen when it went to Trash. */
    reason: string;
    /** Username. */
    deleted_by: string;
    deleted_at: DateTimeString;
    /** What must be typed to delete it permanently. */
    confirm_text: string;
    /** Why it can't be deleted permanently, or null when it can. */
    blocked: string | null;
};
