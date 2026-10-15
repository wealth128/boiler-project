import type { ReportFilters } from "@/features/reports/types";

/**
 * The URL values for a report: only the ones that matter for the period
 * type. The Reports screen and the PDF and Excel downloads all use these, so
 * a file is always the report on the screen.
 */
export function reportQuery(
    values: ReportFilters,
): Record<string, string | undefined> {
    const query: Record<string, string | undefined> = {
        type: values.type,
        category: values.category || undefined,
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
