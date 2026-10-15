import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import PatientRecordController from '@/actions/App/Http/Controllers/Records/PatientRecordController';
import { DataTable } from '@/components/shared/data-table';
import type {
    DeleteReasonOption,
    DeleteReasonValue,
} from '@/components/shared/delete-reason-field';
import type { DataTableColumn } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoveToTrashRow } from '@/components/shared/move-to-trash-row';
import type { MoveToTrashErrors } from '@/components/shared/move-to-trash-row';
import { Pagination } from '@/features/records/pagination';
import { RecordsTabs } from '@/features/records/records-tabs';
import type {
    Paged,
    PatientFilters,
    PatientRecord,
} from '@/features/records/types';
import { formatDate } from '@/lib/dates';
import { formatCount } from '@/lib/format';

type Props = {
    filters: PatientFilters;
    patients: Paged<PatientRecord>;
    /** PatientDeleteReason choices. */
    deleteReasons: DeleteReasonOption[];
};

/** Wait this long after the last key before searching. */
const SEARCH_DELAY_MS = 300;

function patientsUrl(q: string, page = 1): string {
    return PatientRecordController.index.url({
        query: {
            q: q.trim() || undefined,
            page: page > 1 ? page : undefined,
        },
    });
}

/**
 * Patient Records, Patients tab (prototype/prototype.html, "Patient
 * Records"). Admin only. Search by name or Patient ID, sorted by last name.
 * A patient can be moved to Trash only after all their visits are in Trash;
 * the server refuses otherwise and the message shows in the row.
 */
export default function RecordsPatients({
    filters,
    patients,
    deleteReasons,
}: Props) {
    const { url } = usePage();
    const [q, setQ] = useState(filters.q);
    const [trashing, setTrashing] = useState<PatientRecord | null>(null);
    const [processing, setProcessing] = useState(false);
    const [trashErrors, setTrashErrors] = useState<MoveToTrashErrors>({});
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    function search(value: string) {
        setQ(value);
        setTrashing(null);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () =>
                router.visit(patientsUrl(value), {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                }),
            SEARCH_DELAY_MS,
        );
    }

    function startTrash(patient: PatientRecord) {
        setTrashErrors({});
        setTrashing(patient);
    }

    function cancelTrash() {
        const id = trashing?.id;

        setTrashing(null);
        window.setTimeout(() =>
            document.getElementById(`delete-patient-${id}`)?.focus(),
        );
    }

    function moveToTrash(reason: DeleteReasonValue) {
        if (!trashing) {
            return;
        }

        router.delete(PatientRecordController.destroy.url(trashing.id), {
            data: reason,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setTrashing(null),
            onError: (errors) => setTrashErrors(errors),
        });
    }

    const columns: DataTableColumn<PatientRecord>[] = [
        {
            key: 'patient_no',
            header: 'Patient ID',
            cell: (patient) => patient.patient_no,
            className: 'font-mono whitespace-nowrap',
        },
        {
            key: 'name',
            header: 'Name',
            cell: (patient) => patient.patient_name,
        },
        { key: 'sex', header: 'Sex', cell: (patient) => patient.sex },
        {
            key: 'birthdate',
            header: 'Birthdate',
            cell: (patient) => formatDate(patient.birthdate),
            className: 'font-mono whitespace-nowrap',
        },
        {
            key: 'visits',
            header: 'Visits',
            cell: (patient) => formatCount(patient.visit_count),
            align: 'right',
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            cell: (patient) => (
                <Button
                    id={`delete-patient-${patient.id}`}
                    variant="link"
                    size="sm"
                    className="h-auto px-1.5 py-0.5 text-xs text-bad"
                    onClick={() => startTrash(patient)}
                    aria-label={`Delete ${patient.patient_no}, ${patient.patient_name}`}
                >
                    Delete
                </Button>
            ),
        },
    ];

    const total = patients.meta.total;

    return (
        <>
            <Head title="Patient Records" />

            <PageHeader
                title="Patient Records"
                description={`${formatCount(total)} ${total === 1 ? 'patient' : 'patients'}. A patient can be moved to Trash only after all their visits are in Trash.`}
            />

            <RecordsTabs current="patients" url={url} />

            <section className="rounded-lg border bg-card">
                <div className="grid gap-3 border-b p-4 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="records-search">Search</Label>
                        <Input
                            id="records-search"
                            type="search"
                            value={q}
                            placeholder="Name or Patient ID"
                            maxLength={60}
                            autoComplete="off"
                            onChange={(e) => search(e.target.value)}
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    rows={patients.data}
                    rowKey={(patient) => patient.id}
                    caption="Patients"
                    empty={<EmptyState message="No patients match." />}
                    rowClassName={(patient) =>
                        patient.id === trashing?.id ? 'bg-bad-soft' : undefined
                    }
                    rowDetail={(patient) =>
                        patient.id === trashing?.id ? (
                            <MoveToTrashRow
                                reasons={deleteReasons}
                                onConfirm={moveToTrash}
                                onCancel={cancelTrash}
                                processing={processing}
                                errors={trashErrors}
                            />
                        ) : null
                    }
                    footer={
                        patients.meta.last_page > 1 ? (
                            <Pagination
                                meta={patients.meta}
                                pageUrl={(page) => patientsUrl(filters.q, page)}
                                noun="patients"
                            />
                        ) : undefined
                    }
                />
            </section>
        </>
    );
}
