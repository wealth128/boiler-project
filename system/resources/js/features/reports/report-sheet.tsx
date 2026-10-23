import { CategoryChip } from "@/components/shared/category-chip";
import {
    ReportHeader,
    ReportSignatures,
} from "@/components/shared/report-sheet-parts";
import { ReportTables } from "@/features/reports/report-tables";
import type {
    CategoryReport,
    Report,
    ReportSections,
    Sheet,
    Snapshot,
} from "@/features/reports/types";
import { formatDate, formatDateTime } from "@/lib/dates";
import { formatCount } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { Category } from "@/types";

/** A page of the report: the summary, or one category by its name. */
export type ReportPageKey = "summary" | Category;

type ReportPageProps = {
    page: ReportPageKey;
    sheet: Sheet;
    report: Report;
    /** The tables of every page, from the server. */
    sections: ReportSections;
    snapshot: Snapshot | null;
    className?: string;
    /** On screen the tables are panels that can be folded; the print view shows them plain. */
    collapsible?: boolean;
};

/** The note above a saved copy of a closed month. */
export function SnapshotNote({ snapshot }: { snapshot: Snapshot }) {
    return (
        <div
            role="note"
            className="mb-3.5 rounded-md bg-navy-soft px-3 py-2 text-sm font-medium text-navy print:hidden"
        >
            Saved copy, taken when {snapshot.month_label} was closed on{" "}
            {formatDateTime(snapshot.taken_at)}. Later changes to lists or
            records don’t affect it.
        </div>
    );
}

/**
 * One page of the report as it prints (prototype "Reports"): header, then
 * either the grand totals (Summary) or one category's section, then the
 * signatures and when it was generated. Reads only the report it is given, so
 * a saved copy of a closed month shows exactly as it was. Used by the Reports
 * tabs and by the print view (one page per category, each on a new sheet).
 */
export function ReportPage({
    page,
    sheet,
    report,
    sections,
    snapshot,
    className,
    collapsible = false,
}: ReportPageProps) {
    const categories = Object.entries(report.categories) as [
        Category,
        CategoryReport,
    ][];
    const label = page === "summary" ? "Summary" : page;
    const data = page === "summary" ? undefined : report.categories[page];

    return (
        <article
            aria-label={`${sheet.title}, ${sheet.period_label}, ${label}`}
            // Print: the whole page width, no frame, and no table row split
            // across two pages.
            className={cn(
                "max-w-[980px] min-w-0 rounded-lg border bg-card px-3 pt-5 pb-8 break-words sm:px-7 sm:pt-7 print:max-w-none print:rounded-none print:border-0 print:p-0 print:[&_tr]:break-inside-avoid",
                className,
            )}
        >
            <ReportHeader sheet={{ ...sheet, category: label }} />

            {snapshot && (
                <p className="mb-3 hidden text-xs text-muted-foreground print:block">
                    Saved copy, taken when {snapshot.month_label} was closed on{" "}
                    {formatDateTime(snapshot.taken_at)}.
                </p>
            )}

            {page === "summary" ? (
                <>
                    <Totals
                        tiles={[
                            {
                                value: report.totals.patients,
                                label: "Total patients (all categories)",
                            },
                            {
                                value: report.totals.visits,
                                label: "Total visits",
                            },
                            ...categories.map(([name, category]) => ({
                                value: category.patients,
                                label: `${name} patients`,
                            })),
                        ]}
                    />
                    <ReportTables
                        tables={sections.summary ?? []}
                        collapsible={collapsible}
                    />
                </>
            ) : (
                data && (
                    <CategorySection
                        name={page}
                        data={data}
                        tables={sections[page] ?? []}
                        collapsible={collapsible}
                    />
                )
            )}

            <ReportSignatures sheet={sheet} />

            <p className="mt-4 text-xs text-muted-foreground print:break-inside-avoid">
                {snapshot
                    ? `Saved copy from ${formatDateTime(snapshot.taken_at)}`
                    : `Generated ${formatDateTime(report.generated_at)}`}
                {" · "}
                {formatDate(report.from)} to {formatDate(report.to)}
            </p>
        </article>
    );
}

/** The page on the Reports screen: the saved-copy note, then the page. */
export function ReportSheet(props: ReportPageProps) {
    return (
        <>
            {props.snapshot && <SnapshotNote snapshot={props.snapshot} />}
            <ReportPage {...props} />
        </>
    );
}
function Totals({ tiles }: { tiles: { value: number; label: string }[] }) {
    return (
        <dl className="mb-3 flex flex-wrap gap-x-7 gap-y-3">
            {tiles.map((tile) => (
                <div key={tile.label} className="flex flex-col-reverse">
                    <dt className="text-xs text-muted-foreground">
                        {tile.label}
                    </dt>
                    <dd className="text-2xl font-bold tabular-nums">
                        {formatCount(tile.value)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function CategorySection({
    name,
    data,
    tables,
    collapsible,
}: {
    name: Category;
    data: CategoryReport;
    tables: ReportSections[string];
    collapsible: boolean;
}) {
    return (
        <section className="mt-6" aria-label={`${name} report`}>
            <h3 className="mb-2.5 flex items-center gap-2.5 border-b pb-1.5 text-base font-semibold print:break-after-avoid">
                <CategoryChip category={name} /> {name}
            </h3>

            <Totals
                tiles={[
                    { value: data.patients, label: "Patients" },
                    { value: data.visits, label: "Visits" },
                ]}
            />

            {data.patients === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No visits encoded for {name} in this period.
                </p>
            ) : (
                <ReportTables tables={tables} collapsible={collapsible} />
            )}
        </section>
    );
}
