import type { ReportFilters } from "@/features/reports/types";

/**
 * The URL values for a report: only the ones that matter for the period
 * type, and the tab. The Reports screen, Print and the PDF and Excel
 * downloads all use these, so a file is always the report on the screen.
 * `scope: "all"` asks Print and PDF for every page (summary and each category).
 */
export function reportQuery(
    values: ReportFilters,
    scope?: "all",
): Record<string, string | undefined> {
    const query: Record<string, string | undefined> = {
        type: values.type,
        category: values.category,
        scope,
    };

    if (values.type === "monthly") {
        query.month = values.month;
    } else if (values.type === "quarterly") {
        query.quarter = values.quarter;
        query.year = values.year;
    } else if (values.type === "annual") {
        query.year = values.year;
    } else {
        query.from = values.from;
        query.to = values.to;
    }

    return query;
}
