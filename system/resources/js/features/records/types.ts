import type { Category, DateTimeString, Sex } from '@/types';

/*
 * Data the Patient Records screen receives (VisitRecordController,
 * PatientRecordController).
 */

/** Page numbers for a table (RecordSearch::pageMeta). */
export type PageMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    /** Row number of the first row on this page, or null when empty. */
    from: number | null;
    to: number | null;
};

export type Paged<T> = {
    data: T[];
    meta: PageMeta;
};

/** Filters on the Visits tab, as they are in the URL. "" = All / no limit. */
export type VisitFilters = {
    /** "2026-09-01" */
    from: string;
    to: string;
    category: Category | '';
    /** Branch id as text. */
    branch: string;
    q: string;
};

export type PatientFilters = {
    q: string;
};

export type BranchFilterOption = {
    id: number;
    name: string;
};

/** One row of the Visits tab. */
export type VisitRecord = {
    id: number;
    visit_no: string;
    visited_at: DateTimeString;
    patient_no: string;
    patient_name: string;
    sex: Sex;
    age: number;
    branch: string;
    /** The rank, or the typed text for "Other (specify)". */
    rank: string;
    /** The diagnosis, or "Other: <typed text>". */
    diagnosis: string;
    remarks: string | null;
    category: Category;
    /** Username of the encoder. */
    encoded_by: string;
    /** No Edit or Delete until Admin reopens the month. */
    month_closed: boolean;
};

/** One row of the Patients tab. */
export type PatientRecord = {
    id: number;
    patient_no: string;
    patient_name: string;
    sex: Sex;
    /** "1990-05-01" */
    birthdate: string;
    /** Visits not in Trash. */
    visit_count: number;
};
