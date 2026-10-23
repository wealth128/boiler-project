import type { ReactNode } from "react";
import { CategoryChip } from "@/components/shared/category-chip";
import {
    ReportHeader,
    ReportSignatures,
} from "@/components/shared/report-sheet-parts";
import type {
    CategoryReport,
    CountLine,
    Report,
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
    snapshot: Snapshot | null;
    className?: string;
};

const boxTitle =
    "mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase print:break-after-avoid";

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
    snapshot,
    className,
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
            ) : (
                data && <CategorySection name={page} data={data} />
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
}: {
    name: Category;
    data: CategoryReport;
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
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 print:grid-cols-2">
                    <Box title="Sex">
                        <Lines lines={data.sex} />
                        <TotalLine count={data.patients} />
                    </Box>
                    <Box title="Age range">
                        <Lines lines={data.age} />
                    </Box>
                    <Box
                        title="Diagnosis (per visit)"
                        scroll
                        className="print:col-span-2"
                    >
                        <Lines lines={data.diagnoses} />
                        <TotalLine count={data.visits} sticky />
                    </Box>
                    <div className="md:col-span-2">
                        <h4 className={boxTitle}>Branch of service and rank</h4>
                        <div className="relative overflow-x-auto">
                            <table className="w-full border-collapse text-sm">
                                <thead>
                                    <tr>
                                        <th
                                            scope="col"
                                            className="border-b bg-muted px-2 py-1.5 text-left text-xs font-semibold text-muted-foreground"
                                        >
                                            Branch / Rank
                                        </th>
                                        <th
                                            scope="col"
                                            className="border-b bg-muted px-2 py-1.5 text-right text-xs font-semibold text-muted-foreground"
                                        >
                                            Patients
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.branches.map((branch) => (
                                        <BranchRows
                                            key={branch.name}
                                            name={branch.name}
                                            total={branch.total}
                                            ranks={branch.ranks}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}

/**
 * A small table with a title. With `scroll`, it shows at most 10 rows plus
 * the Total line (every row is 2rem high, so 11 rows = 22rem). A longer list
 * scrolls inside the box and the Total line stays at the bottom. On paper the
 * box shows the whole list.
 */
function Box({
    title,
    children,
    scroll = false,
    className,
}: {
    title: string;
    children: ReactNode;
    scroll?: boolean;
    className?: string;
}) {
    return (
        <div className={className}>
            <h4 className={boxTitle}>{title}</h4>
            <div
                className={
                    scroll
                        ? "max-h-[22rem] overflow-y-auto print:max-h-none print:overflow-visible"
                        : undefined
                }
                // A scrolling box must be reachable with the keyboard.
                {...(scroll
                    ? {
                          role: "region",
                          tabIndex: 0,
                          "aria-label": `${title}, scrollable list`,
                      }
                    : {})}
            >
                <table className="w-full border-collapse text-sm">
                    <tbody className="[&>tr]:border-b">{children}</tbody>
                </table>
            </div>
        </div>
    );
}

function Lines({ lines }: { lines: CountLine[] }) {
    return (
        <>
            {lines.map((line, index) => (
                <tr key={`${index}-${line.label}`} className="h-8">
                    <td className="px-2 py-1.5">{line.label}</td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatCount(line.count)}
                    </td>
                </tr>
            ))}
        </>
    );
}

/** The Total line. In a scrolling box, `sticky` keeps it in view at the bottom. */
function TotalLine({
    count,
    sticky = false,
}: {
    count: number;
    sticky?: boolean;
}) {
    return (
        <tr
            className={
                sticky
                    ? "h-8 [&>td]:sticky [&>td]:bottom-0 [&>td]:bg-card [&>td]:shadow-[inset_0_1px_0_var(--color-border)]"
                    : "h-8"
            }
        >
            <td className="px-2 py-1.5 font-bold">Total</td>
            <td className="px-2 py-1.5 text-right font-bold tabular-nums">
                {formatCount(count)}
            </td>
        </tr>
    );
}

function BranchRows({
    name,
    total,
    ranks,
}: {
    name: string;
    total: number;
    ranks: CountLine[];
}) {
    return (
        <>
            <tr className="border-b bg-muted/60 font-bold">
                <td className="px-2 py-1.5">{name}</td>
                <td className="px-2 py-1.5 text-right tabular-nums">
                    {formatCount(total)}
                </td>
            </tr>
            {ranks.map((rank) => (
                <tr key={rank.label} className="border-b">
                    <td className="py-1.5 pr-2 pl-5">{rank.label}</td>
                    <td className="px-2 py-1.5 text-right tabular-nums">
                        {formatCount(rank.count)}
                    </td>
                </tr>
            ))}
        </>
    );
}
