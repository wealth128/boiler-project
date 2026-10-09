import { toDateParts } from '@/lib/dates';
import type { DateInput } from '@/lib/dates';

/**
 * Age in whole years on a given day (default: today in Manila).
 * Matches Patient::ageAt() on the server. Returns null when the birthdate is
 * empty, invalid, or after the given day.
 *
 * The encoding form fills Age with this value. The encoder may still change
 * it, and reports use the saved age.
 */
export function ageFromBirthdate(
    birthdate: DateInput,
    on: DateInput = new Date(),
): number | null {
    const born = toDateParts(birthdate);
    const day = toDateParts(on);

    if (!born || !day) {
        return null;
    }

    let age = day.year - born.year;

    // Birthday not reached yet this year.
    if (
        day.month < born.month ||
        (day.month === born.month && day.day < born.day)
    ) {
        age--;
    }

    return age >= 0 ? age : null;
}
