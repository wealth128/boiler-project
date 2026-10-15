/** The {list} part of /settings/{list}. */
export type ListKey = 'diagnoses' | 'ranks' | 'age-brackets';

export type NameItem = {
    id: number;
    name: string;
    /** The fixed "Other (specify)" entry: no Edit, Delete or Move. */
    is_other: boolean;
};

export type BranchWithRanks = {
    id: number;
    name: string;
    /** false for "Others" (patient types). */
    is_afp: boolean;
    /** Active ranks in seniority order, "Other (specify)" last. */
    ranks: NameItem[];
};

export type BracketItem = {
    id: number;
    min_age: number;
    /** null means "and above". */
    max_age: number | null;
    /** "18–25" or "60 & above". */
    label: string;
};

/** Same numbers as App\Services\ListItemRules. */
export type ListLimits = {
    name_min: number;
    diagnosis_name_max: number;
    rank_name_max: number;
    age_min: number;
    age_max: number;
};

/** The one row being edited or deleted, if any. */
export type ActiveRow = {
    list: ListKey;
    id: number;
    mode: 'edit' | 'delete';
} | null;

/** Errors the server may send back for a list item. */
export type ListItemErrors = Partial<
    Record<'name' | 'branch_id' | 'min_age' | 'max_age' | 'item', string>
>;
