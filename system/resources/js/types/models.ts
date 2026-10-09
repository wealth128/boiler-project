/*
 * Types for every database model (planning/database-schema.md).
 *
 * They describe the JSON Laravel sends to the pages:
 * - Dates and datetimes arrive as ISO strings. Format them with lib/dates.
 * - Relations are optional (`?`) because they are only present when the
 *   controller loads them.
 * - Keep the value lists in step with app/Enums.
 */

// --- Fixed value lists (app/Enums) ---------------------------------------

export type Role = 'encoder' | 'admin' | 'system_admin' | 'viewer';

export type Office = 'ER' | 'OPD' | 'Admission' | 'Admin' | 'IT' | 'Command';

export type Category = 'OPD' | 'ER' | 'Admission';

export type Sex = 'Male' | 'Female';

export type BackupStatus = 'success' | 'failed';

// --- Shared column groups -------------------------------------------------

/** ISO 8601 string, e.g. "2026-10-07T02:30:00.000000Z". */
export type DateTimeString = string;

/** Columns on every model that can be moved to Trash. */
export type Trashable = {
    deleted_at: DateTimeString | null;
    deleted_by: number | null;
    delete_reason: string | null;
    deleted_by_user?: User | null;
};

type Timestamps = {
    created_at: DateTimeString | null;
    updated_at: DateTimeString | null;
};

// --- People ---------------------------------------------------------------

export type User = Timestamps &
    Trashable & {
        id: number;
        name: string;
        /** Login name, lowercase. There is no email. */
        username: string;
        role: Role;
        office: Office;
        is_active: boolean;
        failed_attempts: number;
        locked_at: DateTimeString | null;
    };

export type Patient = Timestamps &
    Trashable & {
        id: number;
        /** e.g. "P-000001" */
        patient_no: string;
        last_name: string;
        first_name: string;
        middle_initial: string | null;
        sex: Sex;
        birthdate: DateTimeString;
        visits?: Visit[];
    };

// --- Encoding -------------------------------------------------------------

export type Visit = Timestamps &
    Trashable & {
        id: number;
        /** e.g. "V-000001" */
        visit_no: string;
        patient_id: number;
        visited_at: DateTimeString;
        /** Auto from birthdate, editable. Reports use this value. */
        age: number;
        branch_id: number;
        rank_id: number;
        rank_other: string | null;
        diagnosis_id: number;
        diagnosis_other: string | null;
        category: Category;
        remarks: string | null;
        encoded_by: number;
        updated_by: number | null;
        /** Set when the visit went to Trash through "Delete month report". */
        month_deletion_id: number | null;
        patient?: Patient;
        branch?: Branch;
        rank?: Rank;
        diagnosis?: Diagnosis;
        encoder?: User;
        updater?: User | null;
    };

// --- Dropdown lists -------------------------------------------------------

export type Branch = {
    id: number;
    name: string;
    /** false for "Others": the form shows "Type" instead of "Rank". */
    is_afp: boolean;
    sort_order: number;
    is_active: boolean;
    ranks?: Rank[];
};

export type Rank = Trashable & {
    id: number;
    branch_id: number;
    name: string;
    /** Marks the fixed "Other (specify)" entry. */
    is_other: boolean;
    sort_order: number;
    branch?: Branch;
};

export type Diagnosis = Trashable & {
    id: number;
    name: string;
    /** Marks the fixed "Other (specify)" entry. */
    is_other: boolean;
    sort_order: number;
};

export type AgeBracket = Trashable & {
    id: number;
    min_age: number;
    /** null means "and above". */
    max_age: number | null;
    sort_order: number;
};

// --- Control --------------------------------------------------------------

/** A month as "YYYY-MM", e.g. "2026-09". */
export type Period = string;

export type MonthClosure = {
    id: number;
    period: Period;
    is_closed: boolean;
    closed_by: number;
    closed_at: DateTimeString;
    reopened_by: number | null;
    reopened_at: DateTimeString | null;
    closed_by_user?: User | null;
    reopened_by_user?: User | null;
};

export type ReportSnapshot = {
    id: number;
    period: Period;
    /** All counts as they were when the month was closed. Shape is set by the Reports feature. */
    data: Record<string, unknown>;
    created_by: number;
    created_at: DateTimeString;
    created_by_user?: User;
};

/** One Trash entry for "Delete month report". */
export type MonthDeletion = {
    id: number;
    period: Period;
    visit_count: number;
    deleted_by: number;
    delete_reason: string;
    deleted_at: DateTimeString;
    deleted_by_user?: User;
};

export type AuditLog = {
    id: number;
    user_id: number | null;
    /** e.g. "visit.created", "month.closed", "user.unlocked" */
    action: string;
    /** Short morph name, e.g. "visits" */
    subject_type: string;
    subject_id: number;
    /** Old and new values for edits. */
    changes: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: DateTimeString;
    user?: User | null;
};

export type BackupRun = {
    id: number;
    started_at: DateTimeString;
    finished_at: DateTimeString;
    status: BackupStatus;
    file_name: string | null;
    size_bytes: number | null;
    message: string | null;
};

export type Setting = {
    /** e.g. "hospital_name", "prepared_by" */
    key: string;
    value: string;
};
