import type { BracketItem, ListLimits, NameItem } from './types';

/**
 * Browser-side copies of the list rules (App\Services\ListItemRules and
 * ListItemRequest). The server checks everything again, including names
 * that are in Trash, which the browser doesn't know about.
 */

/** " Acute   fever " becomes "Acute fever". */
export function normalizeName(name: string): string {
    return name.replace(/\s+/g, ' ').trim();
}

/**
 * The problem with a name, or null when it looks fine.
 *
 * @param others The other active items in the same list (ranks: same branch)
 * @param listName "the list" or "the Army list", for the duplicate message
 */
export function checkName(
    raw: string,
    others: NameItem[],
    options: {
        limits: ListLimits;
        max: number;
        empty: string;
        listName: string;
    },
): string | null {
    const name = normalizeName(raw);

    if (name === '') {
        return options.empty;
    }

    if (name.length < options.limits.name_min || name.length > options.max) {
        return `Use ${options.limits.name_min} to ${options.max} characters.`;
    }

    const lower = name.toLowerCase();
    const match = others.find(
        (item) => normalizeName(item.name).toLowerCase() === lower,
    );

    return match ? `“${match.name}” is already in ${options.listName}.` : null;
}

/** "18–25", or "60 & above" with no upper limit. */
export function bracketLabel(min: number, max: number | null): string {
    return max === null ? `${min} & above` : `${min}–${max}`;
}

export type BracketCheck =
    | { ok: true; min: number; max: number | null }
    | { ok: false; field: 'min_age' | 'max_age'; message: string };

/**
 * Check From / To typed in the form. Blank To means "and above".
 *
 * @param others The other active brackets
 */
export function checkBracket(
    fromRaw: string,
    toRaw: string,
    others: BracketItem[],
    limits: ListLimits,
): BracketCheck {
    const isAge = (value: string) => {
        const n = Number(value);

        return (
            /^\d+$/.test(value) && n >= limits.age_min && n <= limits.age_max
        );
    };

    const from = fromRaw.trim();
    const to = toRaw.trim();

    if (!isAge(from)) {
        return {
            ok: false,
            field: 'min_age',
            message: `Enter a starting age from ${limits.age_min} to ${limits.age_max}.`,
        };
    }

    if (to !== '' && !isAge(to)) {
        return {
            ok: false,
            field: 'max_age',
            message: `Enter an ending age from ${limits.age_min} to ${limits.age_max}, or leave it blank for "and above".`,
        };
    }

    const min = Number(from);
    const max = to === '' ? null : Number(to);

    if (max !== null && max < min) {
        return {
            ok: false,
            field: 'max_age',
            message:
                'The ending age must be the same as or higher than the starting age.',
        };
    }

    const clash = others.find(
        (other) =>
            (other.max_age === null || min <= other.max_age) &&
            (max === null || max >= other.min_age),
    );

    if (clash) {
        return {
            ok: false,
            field: 'min_age',
            message: `Overlaps ${clash.label}. Adjust the ages.`,
        };
    }

    return { ok: true, min, max };
}

/** The first message among the errors, in a fixed order. */
export function firstError(
    errors: Partial<Record<string, string>>,
): string | undefined {
    return (
        errors.name ??
        errors.branch_id ??
        errors.min_age ??
        errors.max_age ??
        errors.item
    );
}
