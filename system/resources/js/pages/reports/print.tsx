import { Head } from "@inertiajs/react";
import { useEffect } from "react";
import { Button } from "@/components/ui/button";
import { ReportPage } from "@/features/reports/report-sheet";
import type { ReportPageKey } from "@/features/reports/report-sheet";
import type {
    Report,
    ReportFilters,
    Sheet,
    Snapshot,
} from "@/features/reports/types";

type Props = {
    filters: ReportFilters;
    sheet: Sheet;
    report: Report;
    snapshot: Snapshot | null;
    /** What to print, in order: "summary" or a category name. */
    pages: ReportPageKey[];
};

/**
 * The print view of a report, opened by Print on the Reports screen in a new
 * tab. It holds only the pages to print: this category, or the summary and
 * every category. Each page starts on a new sheet with its own header and
 * signature block. The browser's Print dialog opens by itself; the buttons
 * above (not printed) print again or close the tab.
 */
export default function ReportPrint({ sheet, report, snapshot, pages }: Props) {
    useEffect(() => {
        // Let the page draw first, then open the Print dialog.
        const timer = window.setTimeout(() => window.print(), 400);

        return () => window.clearTimeout(timer);
    }, []);

    return (
        <>
            <Head title={`${sheet.title} · ${sheet.period_label}`} />

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                <Button type="button" onClick={() => window.print()}>
                    Print
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => window.close()}
                >
                    Close
                </Button>
                <span className="text-sm text-muted-foreground">
                    {pages.length === 1
                        ? "Printing this page."
                        : `Printing ${pages.length} pages: the summary and each category, each on a new sheet.`}
                </span>
            </div>

            <div className="space-y-6 print:space-y-0">
                {pages.map((page, index) => (
                    <ReportPage
                        key={page}
                        page={page}
                        sheet={sheet}
                        report={report}
                        snapshot={snapshot}
                        // Every page after the first starts on a new sheet.
                        className={index > 0 ? "print:break-before-page" : undefined}
                    />
                ))}
            </div>
        </>
    );
}
