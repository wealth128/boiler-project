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

export type AgeSexLine = { label: string; male: number; female: number };

export type CategoryReport = {
    patients: number;
    visits: number;
    sex: CountLine[];
    age: CountLine[];
    age_sex: AgeSexLine[];
    diagnoses: CountLine[];
    branches: BranchLine[];
    afp: { afp: number; others: number };
    returning: { new: number; returning: number };
};

/** How a column of a report table is shown. */
export type ColumnType = 'text' | 'int' | 'pct' | 'delta' | 'deltapct';

/** A figure of a table: text, a number, or null for "no figure". */
export type ReportCell = string | number | null;

/**
 * A table of a report page (ReportSections). The screen, Print, the PDF and
 * the Excel file all draw these same tables, in this order.
 */
export type ReportTable = {
    key: string;
    title: string;
    note: string | null;
    columns: { label: string; type: ColumnType }[];
    rows: {
        cells: ReportCell[];
        style: 'body' | 'child' | 'group' | 'total';
    }[];
};

/** The tables of each page: "summary", and each category by its name. */
export type ReportSections = Record<string, ReportTable[]>;

/**
 * A chart above a table (ReportCharts). Its key is the key of its table. The
 * server draws it as SVG (a wide one, and a narrow one for phones); the
 * rows are the numbers it shows, the same as the table's.
 */
export type ReportChart = {
    key: string;
    kind: 'columns' | 'share' | 'pyramid' | 'bars' | 'paired';
    title: string;
    subtitle: string;
    /** What a screen reader says for the picture. */
    aria: string;
    /** True when there is nothing to draw: the SVG then holds the message. */
    empty: boolean;
    message: string;
    series: { label: string; color: string }[];
    rows: { label: string; values: number[]; tip: string }[];
    svg: string;
    svg_narrow: string;
};

/** The charts of each page ("summary" or a category), by table key. */
export type ReportCharts = Record<string, Record<string, ReportChart>>;

export type Report = {
    /** First and last day of the period, "2026-09-01". */
    from: string;
    to: string;
    generated_at: DateTimeString;
    totals: { patients: number; visits: number };
    categories: Partial<Record<Category, CategoryReport>>;
    /** Every category together, each patient once. */
    summary: {
        sex: CountLine[];
        age: CountLine[];
        age_sex: AgeSexLine[];
        diagnoses: CountLine[];
        branches: BranchLine[];
        afp: { afp: number; others: number };
        returning: { new: number; returning: number };
    };
    daily: {
        by: 'day' | 'month';
        rows: { label: string; counts: Partial<Record<Category, number>> }[];
    };
    /** The period of the same length before, or null. */
    previous: { label: string; patients: number; visits: number } | null;
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
