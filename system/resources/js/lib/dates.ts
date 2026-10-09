/*
 * Date helpers. Every date on screen uses the hospital's time zone and the
 * DD-Mon-YYYY format, e.g. 07-Oct-2026 or 07-Oct-2026 10:30.
 *
 * Accepted inputs:
 * - ISO datetimes from Laravel, e.g. "2026-10-07T02:30:00.000000Z"
 * - plain dates from <input type="date">, e.g. "2026-10-07" (no time zone)
 * - Date objects
 * Empty or invalid values format as "".
 */

export const APP_TIME_ZONE = 'Asia/Manila';

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
] as const;

const MONTHS_LONG = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
] as const;

export type DateInput = string | Date | null | undefined;

/** A calendar date and clock time in the hospital's time zone. Month is 1–12. */
export type DateParts = {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
};

const PLAIN_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

const partsFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: APP_TIME_ZONE,
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    hourCycle: 'h23',
});

const pad = (n: number): string => String(n).padStart(2, '0');

function isRealDate(year: number, month: number, day: number): boolean {
    const d = new Date(Date.UTC(year, month - 1, day));

    return (
        d.getUTCFullYear() === year &&
        d.getUTCMonth() === month - 1 &&
        d.getUTCDate() === day
    );
}

/**
 * Split a value into calendar parts in Manila time, or null when it is
 * empty or not a real date.
 */
export function toDateParts(value: DateInput): DateParts | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'string') {
        const plain = PLAIN_DATE.exec(value);

        // A plain date (birthdate, date filter) is a calendar day, not a
        // moment in time, so it is used as is.
        if (plain) {
            const [year, month, day] = [
                Number(plain[1]),
                Number(plain[2]),
                Number(plain[3]),
            ];

            return isRealDate(year, month, day)
                ? { year, month, day, hour: 0, minute: 0 }
                : null;
        }
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts: Record<string, number> = {};

    for (const part of partsFormatter.formatToParts(date)) {
        if (part.type !== 'literal') {
            parts[part.type] = Number(part.value);
        }
    }

    return {
        year: parts.year,
        month: parts.month,
        day: parts.day,
        hour: parts.hour,
        minute: parts.minute,
    };
}

/** "07-Oct-2026" */
export function formatDate(value: DateInput): string {
    const p = toDateParts(value);

    return p ? `${pad(p.day)}-${MONTHS[p.month - 1]}-${p.year}` : '';
}

/** "07-Oct-2026 10:30" (24-hour clock) */
export function formatDateTime(value: DateInput): string {
    const p = toDateParts(value);

    return p
        ? `${pad(p.day)}-${MONTHS[p.month - 1]}-${p.year} ${pad(p.hour)}:${pad(p.minute)}`
        : '';
}

/** "10:30" (24-hour clock) */
export function formatTime(value: DateInput): string {
    const p = toDateParts(value);

    return p ? `${pad(p.hour)}:${pad(p.minute)}` : '';
}

/** "2026-10-07": the value <input type="date"> expects. */
export function toDateInputValue(value: DateInput): string {
    const p = toDateParts(value);

    return p ? `${p.year}-${pad(p.month)}-${pad(p.day)}` : '';
}

/** Today in Manila as "2026-10-07". */
export function today(): string {
    return toDateInputValue(new Date());
}

/** A report month: "2026-09" becomes "September 2026". */
export function formatPeriod(period: string | null | undefined): string {
    const match = /^(\d{4})-(\d{2})$/.exec(period ?? '');
    const month = match ? Number(match[2]) : 0;

    return match && month >= 1 && month <= 12
        ? `${MONTHS_LONG[month - 1]} ${match[1]}`
        : '';
}
