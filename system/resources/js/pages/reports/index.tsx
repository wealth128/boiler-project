import { Head } from '@inertiajs/react';
import { Chip } from '@/components/shared/chip';
import { PageHeader } from '@/components/shared/page-header';
import { ReportControls } from '@/features/reports/report-controls';
import { ReportSheet } from '@/features/reports/report-sheet';
import type {
    Report,
    ReportFilters,
    ReportOptions,
    Sheet,
    Snapshot,
} from '@/features/reports/types';

type Props = {
    filters: ReportFilters;
    options: ReportOptions;
    sheet: Sheet;
    report: Report;
    /** Only for a monthly report. */
    month_status: { closed: boolean } | null;
    /** Set when a closed month shows its saved copy. */
    snapshot: Snapshot | null;
};

/**
 * Reports (prototype/prototype.html, "Reports"). Admin and Viewer (CO).
 * The choices live in the URL, so Refresh and Back keep them.
 */
export default function Reports({
    filters,
    options,
    sheet,
    report,
    month_status,
    snapshot,
}: Props) {
    // A new key resets the controls whenever the URL gives new choices.
    const controlsKey = JSON.stringify(filters);

    return (
        <>
            <Head title="Reports" />

            <PageHeader
                title="Reports"
                description="Counts each patient once per category in the period. Deleted visits (in Trash) are excluded."
            />

            <section className="mb-4 rounded-lg border bg-card">
                <ReportControls
                    key={controlsKey}
                    filters={filters}
                    options={options}
                />
                {month_status && (
                    <div className="flex justify-end border-t px-4 py-2.5">
                        {month_status.closed ? (
                            <Chip>Month closed · locked for editing</Chip>
                        ) : (
                            <Chip tone="ok">Month open</Chip>
                        )}
                    </div>
                )}
            </section>

            <ReportSheet sheet={sheet} report={report} snapshot={snapshot} />
        </>
    );
}
