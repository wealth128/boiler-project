/**
 * The x on the Admin warning bars only HIDES a bar in this browser. It never
 * changes anything on the server. This file holds the rule for when a hidden
 * bar comes back, and the small helpers that remember it per user.
 *
 * - Yellow (warning) bars come back the next day (Asia/Manila date).
 * - Red (error) bars come back at the user's next sign-in (the shared
 *   `auth.loginAt` value changes on every login).
 * - Any bar comes back at once when its message text changes: the message
 *   is part of the storage key, so a new text has no dismissal.
 *
 * Every storage read and write is wrapped in try/catch. If storage fails,
 * the bar simply shows.
 */

export type AlertLevel = 'error' | 'warning';

/** What is saved when a bar is closed. */
export type Dismissal = {
    /** When it was closed (milliseconds since 1970). */
    at: number;
    /** The Asia/Manila date it was closed on, "2026-10-19". */
    day: string;
    /** `auth.loginAt` at that time ("" when the server sent none). */
    loginAt: string;
};

/** The part of localStorage this file uses. */
export type DismissalStorage = Pick<
    Storage,
    'getItem' | 'setItem' | 'removeItem' | 'key' | 'length'
>;

const PREFIX = 'alert-dismissed';

/** Dismissals older than this are removed when a new one is saved. */
export const STALE_AFTER_MS = 7 * 24 * 60 * 60 * 1000;

/** The calendar date in Manila, "YYYY-MM-DD". */
export function manilaDay(date: Date): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(date);
}

/** A short stable hash of the message text (FNV-1a), for the storage key. */
export function hashMessage(text: string): string {
    let hash = 0x811c9dc5;

    for (let i = 0; i < text.length; i++) {
        hash ^= text.charCodeAt(i);
        hash = Math.imul(hash, 0x01000193);
    }

    return (hash >>> 0).toString(36);
}

export function storageKey(userId: number, message: string): string {
    return `${PREFIX}:${userId}:${hashMessage(message)}`;
}

/**
 * Should this bar show? `dismissal` is what was saved for this user and
 * this exact message (null when nothing was).
 */
export function shouldShow(
    level: AlertLevel,
    dismissal: Dismissal | null,
    now: Date,
    loginAt: string | null,
): boolean {
    if (dismissal === null) {
        return true;
    }

    if (level === 'error') {
        // Hidden until the next sign-in.
        return dismissal.loginAt !== (loginAt ?? '');
    }

    // Hidden until the next day.
    return dismissal.day !== manilaDay(now);
}

function isDismissal(value: unknown): value is Dismissal {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const v = value as Record<string, unknown>;

    return (
        typeof v.at === 'number' &&
        typeof v.day === 'string' &&
        typeof v.loginAt === 'string'
    );
}

function defaultStorage(): DismissalStorage | null {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        return null;
    }
}

/** The saved dismissal, or null (also when storage is blocked or the data is damaged). */
export function readDismissal(
    userId: number,
    message: string,
    storage: DismissalStorage | null = defaultStorage(),
): Dismissal | null {
    try {
        const raw = storage?.getItem(storageKey(userId, message));

        if (!raw) {
            return null;
        }

        const parsed: unknown = JSON.parse(raw);

        return isDismissal(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

/** Remember that the user closed this bar. Removes entries older than 7 days first. */
export function writeDismissal(
    userId: number,
    message: string,
    loginAt: string | null,
    now: Date = new Date(),
    storage: DismissalStorage | null = defaultStorage(),
): void {
    try {
        if (!storage) {
            return;
        }

        removeStale(storage, now);

        const value: Dismissal = {
            at: now.getTime(),
            day: manilaDay(now),
            loginAt: loginAt ?? '',
        };

        storage.setItem(storageKey(userId, message), JSON.stringify(value));
    } catch {
        // Storage failed: the bar just comes back on the next page.
    }
}

/** Delete this app's dismissals that are older than 7 days (or damaged). */
export function removeStale(storage: DismissalStorage, now: Date): void {
    try {
        const stale: string[] = [];

        for (let i = 0; i < storage.length; i++) {
            const key = storage.key(i);

            if (key === null || !key.startsWith(`${PREFIX}:`)) {
                continue;
            }

            let parsed: unknown = null;

            try {
                parsed = JSON.parse(storage.getItem(key) ?? 'null');
            } catch {
                parsed = null;
            }

            if (
                !isDismissal(parsed) ||
                now.getTime() - parsed.at > STALE_AFTER_MS
            ) {
                stale.push(key);
            }
        }

        stale.forEach((key) => storage.removeItem(key));
    } catch {
        // Ignore: cleaning up is optional.
    }
}
