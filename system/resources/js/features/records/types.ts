import type { Category, DateTimeString, Sex } from '@/types';

/*
 * Data the Patient Records screen receives (VisitRecordController,
 * PatientRecordController).
 */

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
    /** Username of the encoder (for an imported visit, the Admin who imported it). */
    encoded_by: string;
    /** Who wrote the row on paper, for a visit from a downtime import. */
    written_by: string | null;
    /** From a downtime import (Records > Import). */
    imported: boolean;
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
