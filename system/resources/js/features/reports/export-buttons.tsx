import ReportExportController from "@/actions/App/Http/Controllers/Reports/ReportExportController";
import { Button } from "@/components/ui/button";
import { reportQuery } from "@/features/reports/report-query";
import type { ReportFilters } from "@/features/reports/types";

/**
 * Export PDF, Export Excel and Print (prototype, reportsView). They use the
 * report that is on the screen now (the `filters` it was built from), not
 * choices typed in the boxes and not yet generated. The downloads are plain
 * links, so the browser saves the file and the page stays where it is.
 * Print is the browser's print of this page (see the print styles).
 */
export function ExportButtons({ filters }: { filters: ReportFilters }) {
    const query = reportQuery(filters);

    return (
        <div className="flex flex-wrap items-center gap-2 print:hidden">
            <Button asChild variant="outline" size="sm">
                <a href={ReportExportController.pdf.url({ query })} download>
                    Export PDF
                </a>
            </Button>
            <Button asChild variant="outline" size="sm">
                <a href={ReportExportController.excel.url({ query })} download>
                    Export Excel
                </a>
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => window.print()}
            >
                Print
            </Button>
        </div>
    );
}
