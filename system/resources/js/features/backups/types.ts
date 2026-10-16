import type { Paged } from '@/types';

/** What the status panel shows (BackupController::index). */
export type BackupStatusInfo = {
    last_success: {
        finished_at: string;
        file_name: string | null;
        size_mb: number | null;
        zip_name: string | null;
        zip_size_mb: number | null;
    } | null;
    next_run: string;
    keep_days: number;
    /** BACKUP_PATH. */
    folder: string;
    /** Red warning in full, or null when backups are fine. */
    problem: string | null;
};

/** The weekly USB copy or the monthly test restore. */
export type BackupMarkInfo = {
    /** Part of the URL: "usb-copy" or "test-restore". */
    type: string;
    label: string;
    last_at: string | null;
    by: string | null;
    overdue: boolean;
};

export type BackupRunRow = {
    id: number;
    started_at: string;
    finished_at: string;
    status: 'success' | 'failed';
    file_name: string | null;
    size_mb: number | null;
    /** The picture files zip next to the dump (null for runs made before it existed). */
    zip_name: string | null;
    zip_size_mb: number | null;
    /** Failed runs only. Plain text: React escapes it. */
    error: string | null;
};

export type BackupRuns = Paged<BackupRunRow>;

/** One line of the banner on every Admin page. */
export type BackupAlert = {
    level: 'error' | 'warning';
    message: string;
};
