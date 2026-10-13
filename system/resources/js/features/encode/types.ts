import type { Category, DateTimeString, Sex } from '@/types';

/*
 * Data the Encode screen receives (EncodeController, PatientSearchController).
 */

export type BranchOption = {
    id: number;
    name: string;
    /** false for "Others": the form says "Type" instead of "Rank". */
    is_afp: boolean;
};

export type RankOption = {
    id: number;
    branch_id: number;
    name: string;
    /** The "Other (specify)" entry: the encoder types the value. */
    is_other: boolean;
};

export type DiagnosisOption = {
    id: number;
    name: string;
    is_other: boolean;
};

/** One "Find returning patient" result. */
export type PatientMatch = {
    id: number;
    patient_no: string;
    last_name: string;
    first_name: string;
    middle_initial: string | null;
    sex: Sex;
    /** "1990-05-01" */
    birthdate: string;
    full_name: string;
    /** Branch and rank of the latest visit not in Trash. */
    last_visit: {
        branch_id: number;
        rank_id: number;
        rank_other: string | null;
    } | null;
};

/** One row of "My entries today". */
export type TodayEntry = {
    id: number;
    visit_no: string;
    visited_at: DateTimeString;
    patient_name: string;
    category: Category;
    /** The diagnosis name, or "Other: <typed text>". */
    diagnosis: string;
    /** VisitPolicy: false once the month is closed. */
    can_edit: boolean;
};

/** The visit loaded into the form by "Edit". */
export type EditingVisit = {
    id: number;
    visit_no: string;
    visited_at: DateTimeString;
    patient: Omit<PatientMatch, 'last_visit'>;
    age: number;
    branch_id: number;
    rank_id: number;
    rank_other: string | null;
    diagnosis_id: number;
    diagnosis_other: string | null;
    category: Category;
    remarks: string | null;
};

/** What the form sends. Select and number values are kept as strings. */
export type VisitFormData = {
    patient_id: number | null;
    last_name: string;
    first_name: string;
    middle_initial: string;
    sex: Sex | '';
    birthdate: string;
    age: string;
    branch_id: string;
    rank_id: string;
    rank_other: string;
    diagnosis_id: string;
    diagnosis_other: string;
    category: Category;
    remarks: string;
};

export type VisitFormField = keyof VisitFormData;
