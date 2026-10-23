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
 * The files are fetched in the background and saved when ready, so the page
 * stays where it is. While one is being made its button says "Preparing the
 * file…" and both file buttons are off, so it can't be started twice. Print
 * opens the print view in a new tab and starts the browser's Print dialog there.
 */
export function ExportButtons({ filters }: { filters: ReportFilters }) {
    const [scope, setScope] = useState<"tab" | "all">("tab");
    const [busy, setBusy] = useState<"pdf" | "excel" | null>(null);
    const [failed, setFailed] = useState(false);

    async function download(kind: "pdf" | "excel", url: string) {
        if (busy !== null) {
            return;
        }

        setBusy(kind);
        setFailed(false);

        try {
            const response = await fetch(url, {
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });
            const type = response.headers.get("content-type") ?? "";

            // A sign-in page or an error page is not the file.
            if (!response.ok || type.includes("text/html")) {
                throw new Error("not a file");
            }

            const blob = await response.blob();
            const name =
                /filename="?([^";]+)"?/.exec(
                    response.headers.get("content-disposition") ?? "",
                )?.[1] ?? (kind === "pdf" ? "report.pdf" : "report.xlsx");
            const address = URL.createObjectURL(blob);
            const link = document.createElement("a");

            link.href = address;
            link.download = name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(address), 10000);
        } catch {
            setFailed(true);
        } finally {
            setBusy(null);
        }
    }

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
                <Button
                    type="button"
                    className="max-sm:w-full"
                    variant="outline"
                    size="sm"
                    disabled={busy !== null}
                    aria-busy={busy === "pdf"}
                    onClick={() =>
                        download(
                            "pdf",
                            ReportExportController.pdf.url({ query: pageQuery }),
                        )
                    }
                >
                    {busy === "pdf" ? "Preparing the file…" : "Export PDF"}
                </Button>
                <Button
                    type="button"
                    className="max-sm:w-full"
                    variant="outline"
                    size="sm"
                    disabled={busy !== null}
                    aria-busy={busy === "excel"}
                    onClick={() =>
                        download(
                            "excel",
                            ReportExportController.excel.url({
                                query: excelQuery,
                            }),
                        )
                    }
                >
                    {busy === "excel" ? "Preparing the file…" : "Export Excel"}
                </Button>
                <Button className="max-sm:w-full" asChild variant="outline" size="sm">
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

            <p className="sr-only" role="status">
                {busy !== null ? "Preparing the file…" : ""}
            </p>
            {failed && (
                <p role="alert" className="w-full text-sm text-destructive">
                    The file could not be made. Check your connection and try
                    again.
                </p>
            )}
        </div>
    );
}
