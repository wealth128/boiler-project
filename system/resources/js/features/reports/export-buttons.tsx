import { useState } from "react";
import ReportExportController from "@/actions/App/Http/Controllers/Reports/ReportExportController";
import ReportPrintController from "@/actions/App/Http/Controllers/Reports/ReportPrintController";
import { Button } from "@/components/ui/button";
import { reportQuery } from "@/features/reports/report-query";
import { REPORT_TABS } from "@/features/reports/report-tabs";
import type { ReportFilters } from "@/features/reports/types";

/**
 * Export PDF, Export Excel and Print (prototype, reportsView). They use the
 * report that is on the screen now (the `filters` it was built from), not
 * choices typed in the boxes and not yet generated.
 *
 * Print and PDF can take "This category" (the open tab) or "All categories"
 * (the summary and each category, every one on a new page with its own header
 * and signatures). Excel always holds every category, one sheet each.
 *
 * The downloads are plain links, so the browser saves the file and the page
 * stays where it is. Print opens the print view in a new tab and starts the
 * browser's Print dialog there.
 */
export function ExportButtons({ filters }: { filters: ReportFilters }) {
    const [scope, setScope] = useState<"tab" | "all">("tab");

    const tab = REPORT_TABS.find((t) => t.key === filters.category);
    const thisLabel =
        filters.category === "summary"
            ? "This page (Summary)"
            : `This category (${tab?.label})`;

    const pageQuery = reportQuery(filters, scope === "all" ? "all" : undefined);
    const excelQuery = reportQuery(filters);

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 print:hidden">
            <fieldset className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <legend className="sr-only">Pages for Print and PDF</legend>
                <span className="text-sm text-muted-foreground" aria-hidden="true">
                    Print / PDF:
                </span>
                <label className="flex cursor-pointer items-center gap-1.5 text-sm">
                    <input
                        type="radio"
                        name="report-scope"
                        value="tab"
                        checked={scope === "tab"}
                        onChange={() => setScope("tab")}
                        className="size-4 accent-primary"
                    />
                    {thisLabel}
                </label>
                <label className="flex cursor-pointer items-center gap-1.5 text-sm">
                    <input
                        type="radio"
                        name="report-scope"
                        value="all"
                        checked={scope === "all"}
                        onChange={() => setScope("all")}
                        className="size-4 accent-primary"
                    />
                    All categories
                </label>
            </fieldset>

            <div className="flex flex-wrap items-center gap-2">
                <Button asChild variant="outline" size="sm">
                    <a
                        href={ReportExportController.pdf.url({
                            query: pageQuery,
                        })}
                        download
                    >
                        Export PDF
                    </a>
                </Button>
                <Button asChild variant="outline" size="sm">
                    <a
                        href={ReportExportController.excel.url({
                            query: excelQuery,
                        })}
                        download
                    >
                        Export Excel
                    </a>
                </Button>
                <Button asChild variant="outline" size="sm">
                    <a
                        href={ReportPrintController.show.url({
                            query: pageQuery,
                        })}
                        target="_blank"
                        rel="noopener"
                    >
                        Print
                    </a>
                </Button>
            </div>
        </div>
    );
}
