import { Head } from "@inertiajs/react";
import type { DeleteReasonOption } from "@/components/shared/delete-reason-field";
import { PageHeader } from "@/components/shared/page-header";
import { ExportButtons } from "@/features/reports/export-buttons";
import { MonthBar } from "@/features/reports/month-bar";
import { ReportControls } from "@/features/reports/report-controls";
import { ReportSheet } from "@/features/reports/report-sheet";
import {
    REPORT_PANEL_ID,
    ReportTabs,
    pageOfTab,
    tabId,
} from "@/features/reports/report-tabs";
import type {
    MonthStatus,
    Report,
    ReportFilters,
    ReportCharts,
    ReportOptions,
    ReportSections,
    Sheet,
    Snapshot,
} from "@/features/reports/types";

type Props = {
    filters: ReportFilters;
    options: ReportOptions;
    sheet: Sheet;
    report: Report;
    /** The tables of every page. */
    sections: ReportSections;
    /** The charts above those tables. */
    charts: ReportCharts;
    /** Only for a monthly report. */
    month_status: MonthStatus | null;
    /** Admin only: reasons for Delete month report. */
    month_delete_reasons: DeleteReasonOption[];
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
    sections,
    charts,
    month_status,
    month_delete_reasons,
    snapshot,
}: Props) {
    // A new key resets the controls whenever the URL gives new choices.
    const controlsKey = JSON.stringify(filters);

    return (
        <>
            <Head title="Reports" />

            <PageHeader
                className="print:hidden"
                title="Reports"
                description="Counts each patient once per category in the period. Deleted visits (in Trash) are excluded."
            />

            <section className="mb-4 rounded-lg border bg-card print:hidden">
                <ReportControls
                    key={controlsKey}
                    filters={filters}
                    options={options}
                />
                {month_status ? (
                    <MonthBar
                        // Fresh state (no open questions) for another month.
                        key={month_status.period}
                        status={month_status}
                        monthLabel={sheet.period_label}
                        reasons={month_delete_reasons}
                        leading={<ExportButtons filters={filters} />}
                    />
                ) : (
                    <div className="border-t px-4 py-2.5">
                        <ExportButtons filters={filters} />
                    </div>
                )}
            </section>

            <ReportTabs filters={filters} />

            <div
                role="tabpanel"
                id={REPORT_PANEL_ID}
                aria-labelledby={tabId(filters.category)}
            >
                <ReportSheet
                    page={pageOfTab(filters.category)}
                    sheet={sheet}
                    report={report}
                    sections={sections}
                    charts={charts}
                    snapshot={snapshot}
                    collapsible
                />
            </div>
        </>
    );
}
