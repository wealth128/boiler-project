import { today } from '@/lib/dates';
import type {
    RankOption,
    DiagnosisOption,
    VisitFormData,
    VisitFormField,
} from './types';

/**
 * Order of the fields on screen. The first one with an error gets focus.
 * Rank and diagnosis "specify" errors show under their dropdowns.
 */
export const FIELD_ORDER: VisitFormField[] = [
    'patient_id',
    'last_name',
    'first_name',
    'middle_initial',
    'sex',
    'birthdate',
    'age',
    'branch_id',
    'rank_id',
    'rank_other',
    'diagnosis_id',
    'diagnosis_other',
    'category',
    'remarks',
];

/** M.I. as saved: uppercase, without periods or spaces. "j." becomes "J". */
export function normalizeMiddleInitial(value: string): string {
    return value.replace(/[.\s]/g, '').toUpperCase();
}

/**
 * Quick checks before sending, with the prototype's messages. The server
 * (StoreVisitRequest / UpdateVisitRequest) repeats every one of them, plus
 * the list checks (rank belongs to the branch, item still active).
 */
export function validateVisit(
    data: VisitFormData,
    context: {
        rank: RankOption | undefined;
        diagnosis: DiagnosisOption | undefined;
        rankIsType: boolean;
    },
): Partial<Record<VisitFormField, string>> {
    const errors: Partial<Record<VisitFormField, string>> = {};
    const age = data.age.trim();

    if (!data.last_name.trim()) {
        errors.last_name = 'Enter the last name.';
    }

    if (!data.first_name.trim()) {
        errors.first_name = 'Enter the first name.';
    }

    if (
        data.middle_initial &&
        !/^\p{L}{1,2}$/u.test(normalizeMiddleInitial(data.middle_initial))
    ) {
        errors.middle_initial = 'Enter up to 2 letters.';
    }

    if (!data.sex) {
        errors.sex = 'Choose Male or Female.';
    }

    if (!data.birthdate) {
        errors.birthdate = 'Enter the birthdate.';
    } else if (data.birthdate > today()) {
        errors.birthdate = "The birthdate can't be in the future.";
    }

    if (age === '' || !/^\d+$/.test(age) || Number(age) > 120) {
        errors.age = 'Enter an age from 0 to 120.';
    }

    if (!data.branch_id) {
        errors.branch_id = 'Choose the branch of service.';
    }

    if (!data.rank_id) {
        errors.rank_id = context.rankIsType
            ? 'Choose the patient type.'
            : 'Choose the rank.';
    } else if (context.rank?.is_other && !data.rank_other.trim()) {
        errors.rank_other = context.rankIsType
            ? 'Type the patient type.'
            : 'Type the rank.';
    }

    if (!data.diagnosis_id) {
        errors.diagnosis_id = 'Choose a diagnosis.';
    } else if (context.diagnosis?.is_other && !data.diagnosis_other.trim()) {
        errors.diagnosis_other = 'Type the diagnosis.';
    }

    return errors;
}
