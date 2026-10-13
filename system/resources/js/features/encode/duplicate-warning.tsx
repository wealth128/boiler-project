import { TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
import { searchPatients } from './search-patients';
import type { PatientMatch } from './types';

type DuplicateWarningProps = {
    /** From the server: "A patient with this name and birthdate already exists." */
    message: string;
    lastName: string;
    firstName: string;
    birthdate: string;
    onUse: (patient: PatientMatch) => void;
    onSaveAnyway: () => void;
    processing: boolean;
};

const same = (a: string, b: string) =>
    a.trim().toLocaleLowerCase() === b.trim().toLocaleLowerCase();

/**
 * Shown when "Save & New" would create a patient whose last name, first
 * name and birthdate match a patient already on file. The encoder either
 * uses that patient (so they keep one Patient ID and are counted once), or
 * confirms this is a different person.
 */
export function DuplicateWarning({
    message,
    lastName,
    firstName,
    birthdate,
    onUse,
    onSaveAnyway,
    processing,
}: DuplicateWarningProps) {
    const [matches, setMatches] = useState<PatientMatch[] | null>(null);

    // Look up the matching patients to offer them by name and Patient ID.
    useEffect(() => {
        const controller = new AbortController();

        searchPatients(`${lastName} ${firstName}`, controller.signal)
            .then((results) =>
                setMatches(
                    (results ?? []).filter(
                        (p) =>
                            same(p.last_name, lastName) &&
                            same(p.first_name, firstName) &&
                            p.birthdate === birthdate,
                    ),
                ),
            )
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    console.error(error);
                    setMatches([]);
                }
            });

        return () => controller.abort();
    }, [lastName, firstName, birthdate]);

    return (
        <div
            id="encode-duplicate"
            role="alert"
            tabIndex={-1}
            className="grid gap-3 rounded-md border border-warn bg-warn-soft p-3 text-sm outline-none"
        >
            <p className="flex items-start gap-2 font-semibold text-warn">
                <TriangleAlert
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {message}
            </p>
            <p className="text-foreground">
                If it's the same person, use their record so they keep one
                Patient ID and are counted once.
            </p>

            {matches && matches.length > 0 && (
                <ul className="grid gap-1.5">
                    {matches.map((patient) => (
                        <li
                            key={patient.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-md bg-card px-3 py-2"
                        >
                            <span className="font-mono text-xs">
                                {patient.patient_no}
                            </span>
                            <span className="font-semibold">
                                {patient.full_name}
                            </span>
                            <span className="text-muted-foreground">
                                {patient.sex} · {formatDate(patient.birthdate)}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                className="ml-auto"
                                onClick={() => onUse(patient)}
                                disabled={processing}
                                data-use-duplicate
                            >
                                Use this patient
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onSaveAnyway}
                    disabled={processing}
                >
                    No, save as a new patient
                </Button>
            </div>
        </div>
    );
}
