import type { Category, DateTimeString } from '@/types';

/*
 * Data the Records > Import screen receives (ImportController).
 */

export type ImportRowStatus = 'ok' | 'warning' | 'error';

/** Warning codes that change what the screen offers (ImportRowResult). */
export type ImportWarningCode =
    | 'link'
    | 'duplicate_visit'
    | 'file_duplicate'
    | 'counts_as'
    | 'differs';

export type ImportRowWarning = {
    code: ImportWarningCode;
    text: string;
};

/** One row of the preview: what the file says, and what the check found. */
export type ImportRow = {
    /** The row number in the file (the header is row 1). */
    number: number;
    status: ImportRowStatus;
    /** Why the row can't be imported. */
    errors: string[];
    warnings: ImportRowWarning[];
    display: {
        date: string;
        time: string;
        patient_no: string;
        name: string;
        branch_rank: string;
        diagnosis: string;
        category: Category | string;
        written_by: string;
    };
    /** "Will be linked to P-…": Admin may save a new patient instead. */
    can_save_as_new: boolean;
    /** Ticked when the preview opens. */
    selected: boolean;
};

export type ImportPreview = {
    /** Names the waiting file. */
    token: string;
    file_name: string;
    summary: {
        total: number;
        ok: number;
        warning: number;
        error: number;
    };
    rows: ImportRow[];
};

/** One line of "Past imports". */
export type ImportBatchRow = {
    id: number;
    file_name: string;
    imported_at: DateTimeString;
    imported_by: string;
    original_rows: number;
    imported_count: number;
    skipped_count: number;
    undone_at: DateTimeString | null;
    undone_by: string | null;
    can_undo: boolean;
    /** "September 2026": a closed month that stops the undo. */
    blocked_month: string | null;
};

export type ImportLimits = {
    max_rows: number;
    max_mb: number;
    /** "January 2026" */
    earliest: string;
};
