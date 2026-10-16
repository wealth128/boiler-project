import { router } from '@inertiajs/react';
import PatientSearchController from '@/actions/App/Http/Controllers/Encode/PatientSearchController';
import type { PatientMatch } from './types';

/** The server said "too many requests" (429): wait a minute. */
export class SearchRateLimited extends Error {
    constructor() {
        super('Too many patient searches');
    }
}

/**
 * Ask the server for patients matching a last name or Patient ID
 * (GET /patients/search). Patients in Trash are never returned.
 *
 * Returns null when the user was signed out (e.g. the 8:00 PM sign-out);
 * the page is then reloaded, which leads to the login page.
 */
export async function searchPatients(
    query: string,
    signal?: AbortSignal,
): Promise<PatientMatch[] | null> {
    const response = await fetch(
        PatientSearchController.url({ query: { q: query } }),
        {
            signal,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    );

    if (response.status === 401 || response.status === 419) {
        router.reload();

        return null;
    }

    if (response.status === 429) {
        throw new SearchRateLimited();
    }

    if (!response.ok) {
        throw new Error(`Patient search failed (${response.status})`);
    }

    const body = (await response.json()) as { data: PatientMatch[] };

    return body.data;
}
