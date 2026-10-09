import type { AgeBracket, Category, Office, Patient, Role } from '@/types';

/*
 * Display labels and text formatting shared by several features.
 * The labels match the PHP enums in app/Enums.
 */

export const CATEGORIES: readonly Category[] = ['OPD', 'ER', 'Admission'];

export const ROLE_LABELS: Record<Role, string> = {
    encoder: 'Encoder',
    admin: 'Admin',
    system_admin: 'System Admin',
    viewer: 'Viewer (CO)',
};

export const OFFICE_LABELS: Record<Office, string> = {
    ER: 'Emergency Room',
    OPD: 'Out-Patient Department',
    Admission: 'Admission',
    Admin: 'Admin Office',
    IT: 'IT',
    Command: 'Command',
};

/** "Dela Cruz, Juan M." (same as Patient::full_name on the server) */
export function patientName(
    patient: Pick<Patient, 'last_name' | 'first_name' | 'middle_initial'>,
): string {
    const mi = patient.middle_initial?.replace(/\.+$/, '');

    return `${patient.last_name}, ${patient.first_name}${mi ? ` ${mi}.` : ''}`;
}

/** "18–25", or "60 & above" when there is no upper limit. */
export function ageBracketLabel(
    bracket: Pick<AgeBracket, 'min_age' | 'max_age'>,
): string {
    return bracket.max_age === null
        ? `${bracket.min_age} & above`
        : `${bracket.min_age}–${bracket.max_age}`;
}

/** Whole numbers with thousands separators: 1,234 */
export function formatCount(value: number): string {
    return value.toLocaleString('en-US');
}
