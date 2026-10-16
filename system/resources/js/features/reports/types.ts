import type { Category, DateTimeString } from '@/types';

/*
 * Data the Reports screen receives (ReportController, ReportBuilder).
 * Labels are included, so a saved copy of a closed month shows as it was.
 */

export type PeriodType = 'monthly' | 'quarterly' | 'annual' | 'custom';

/** The tab of the Reports screen, as it is in the URL (?category=er). */
export type ReportTab = 'opd' | 'er' | 'admission' | 'summary';

/** The choices, as they are in the URL. */
export type ReportFilters = {
    type: PeriodType;
    /** "2026-09" */
    month: string;
    /** "1" to "4" */
    quarter: string;
    year: string;
    /** "2026-09-01" */
    from: string;
    to: string;
    /** The tab shown. */
    category: ReportTab;
};

export type ReportOptions = {
    months: { value: string; label: string }[];
    years: string[];
    categories: Category[];
};

export type CountLine = { label: string; count: number };

export type BranchLine = {
    name: string;
    total: number;
    ranks: CountLine[];
};

export type CategoryReport = {
    patients: number;
    visits: number;
    sex: CountLine[];
    age: CountLine[];
    diagnoses: CountLine[];
    branches: BranchLine[];
};

export type Report = {
    /** First and last day of the period, "2026-09-01". */
    from: string;
    to: string;
    generated_at: DateTimeString;
    totals: { patients: number; visits: number };
    categories: Partial<Record<Category, CategoryReport>>;
};

/** Header and signatures of the report sheet. */
export type Sheet = {
    title: string;
    period_label: string;
    category: Category | null;
    hospital_name: string;
    office: string;
    prepared_by: string;
    prepared_by_title: string;
    noted_by: string;
    noted_by_title: string;
    /** Address of the hospital logo, or null when none is set. */
    logo_url: string | null;
    prepared_signature_url: string | null;
    noted_signature_url: string | null;
    /** True when every month in the report is closed: signature pictures show. */
    signatures_final: boolean;
    /** Why the report is not final ("Not final – month still open"), or null. */
    signature_note: string | null;
};

export type Snapshot = {
    month_label: string;
    taken_at: DateTimeString;
};

/** Status of a monthly report (ReportController). */
export type MonthStatus = {
    /** "2026-09" */
    period: string;
    closed: boolean;
    /** Visits "Delete month report" would move to Trash. */
    visit_count: number;
    /** Admin only: shows the Close, Reopen and Delete buttons. */
    can_manage: boolean;
};
