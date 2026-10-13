import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/page-header';
import { TodayEntries } from '@/features/encode/today-entries';
import type { TodayEntry } from '@/features/encode/types';
import { VisitForm } from '@/features/encode/visit-form';
import type { VisitFormProps } from '@/features/encode/visit-form';
import type { Office } from '@/types';

type Props = VisitFormProps & {
    office: Office;
    todayEntries: TodayEntry[];
};

/**
 * Encode Patient (prototype/prototype.html, "Encode Patient"): the encoding
 * form and "My entries today". /encode?edit={visit} opens a visit in the
 * same form. Rules: planning/mvp-scope.md section 2; the server checks
 * everything again (StoreVisitRequest, UpdateVisitRequest, VisitPolicy).
 */
export default function EncodeIndex({ office, todayEntries, ...form }: Props) {
    const title = form.editing ? 'Edit Visit' : 'Encode Patient';

    return (
        <>
            <Head title={title} />

            <PageHeader
                title={title}
                description={`Office: ${office}. Each save records one visit.`}
            />

            <div className="grid items-start gap-4 min-[1100px]:grid-cols-[minmax(0,1fr)_320px]">
                <section className="rounded-lg border bg-card">
                    {/* A new key starts the form fresh when switching visits. */}
                    <VisitForm key={form.editing?.id ?? 'new'} {...form} />
                </section>

                <TodayEntries
                    entries={todayEntries}
                    editingId={form.editing?.id}
                />
            </div>
        </>
    );
}
