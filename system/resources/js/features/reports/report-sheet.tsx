import type { ReactNode } from 'react';
import { CategoryChip } from '@/components/shared/category-chip';
import type {
    CategoryReport,
    CountLine,
    Report,
    Sheet,
    Snapshot,
} from '@/features/reports/types';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatCount } from '@/lib/format';
import type { Category } from '@/types';

type ReportSheetProps = {
    sheet: Sheet;
    report: Report;
    snapshot: Snapshot | null;
};

const boxTitle =
    'mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase';

/**
 * The report as it prints (prototype "Reports"): header, grand totals when
 * all categories are shown, one section per category, signatures, and when
 * it was generated. Reads only the report it is given, so a saved copy of a
 * closed month shows exactly as it was.
 */
export function ReportSheet({ sheet, report, snapshot }: ReportSheetProps) {
    const categories = Object.entries(report.categories) as [
        Category,
        CategoryReport,
    ][];

    return (
        <>
            {snapshot && (
                <div
                    role="note"
                    className="mb-3.5 rounded-md bg-navy-soft px-3 py-2 text-sm font-medium text-navy"
                >
                    Saved copy, taken when {snapshot.month_label} was closed on{' '}
                    {formatDateTime(snapshot.taken_at)}. Later changes to lists
                    or records don’t affect it.
                </div>
            )}

            <article
                aria-label={`${sheet.title}, ${sheet.period_label}`}
                className="max-w-[980px] rounded-lg border bg-card px-4 pt-5 pb-8 sm:px-7 sm:pt-7"
            >
                <header className="mb-4 border-b-2 border-foreground pb-3 text-center">
                    <div className="text-xs tracking-[0.12em] text-muted-foreground uppercase">
                        {sheet.hospital_name} · {sheet.office}
                    </div>
                    <h2 className="mt-1 mb-0.5 text-lg font-semibold">
                        {sheet.title}
                    </h2>
                    <div className="font-mono text-sm">
                        {sheet.period_label}
                        {sheet.category ? ` · ${sheet.category} only` : ''}
                    </div>
                </header>

                {!sheet.category && (
                    <Totals
                        tiles={[
                            {
                                value: report.totals.patients,
                                label: 'Total patients (all categories)',
                            },
                            {
                                value: report.totals.visits,
                                label: 'Total visits',
                            },
                            ...categories.map(([name, data]) => ({
                                value: data.patients,
                                label: `${name} patients`,
                            })),
                        ]}
                    />
                )}

                {categories.map(([name, data]) => (
                    <CategorySection key={name} name={name} data={data} />
                ))}

                <div className="mt-9 grid gap-10 sm:grid-cols-2">
                    <Signature
                        name={sheet.prepared_by}
                        caption={`Prepared by · ${sheet.prepared_by_title}`}
                    />
                    <Signature
                        name={sheet.noted_by}
                        caption={`Noted by · ${sheet.noted_by_title}`}
                    />
                </div>

                <p className="mt-4 text-xs text-muted-foreground">
                    {snapshot
                        ? `Saved copy from ${formatDateTime(snapshot.taken_at)}`
                        : `Generated ${formatDateTime(report.generated_at)}`}
                    {' · '}
                    {formatDate(report.from)} to {formatDate(report.to)}
                </p>
            </article>
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
            <h3 className="mb-2.5 flex items-center gap-2.5 border-b pb-1.5 text-base font-semibold">
                <CategoryChip category={name} /> {name}
            </h3>

            <Totals
                tiles={[
                    { value: data.patients, label: 'Patients' },
                    { value: data.visits, label: 'Visits' },
                ]}
            />

            {data.patients === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No {name} patients in this period.
                </p>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <Box title="Sex">
                        <Lines lines={data.sex} />
                        <TotalLine count={data.patients} />
                    </Box>
                    <Box title="Age range">
                        <Lines lines={data.age} />
                    </Box>
                    <Box title="Diagnosis (per visit)" scroll>
                        <Lines lines={data.diagnoses} />
                        <TotalLine count={data.visits} sticky />
                    </Box>
                    <div className="md:col-span-2">
                        <h4 className={boxTitle}>Branch of service and rank</h4>
                        <div className="overflow-x-auto">
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
 * scrolls inside the box and the Total line stays at the bottom.
 */
function Box({
    title,
    children,
    scroll = false,
}: {
    title: string;
    children: ReactNode;
    scroll?: boolean;
}) {
    return (
        <div>
            <h4 className={boxTitle}>{title}</h4>
            <div
                className={
                    scroll ? 'max-h-[22rem] overflow-y-auto' : undefined
                }
                // A scrolling box must be reachable with the keyboard.
                {...(scroll
                    ? {
                          role: 'region',
                          tabIndex: 0,
                          'aria-label': `${title}, scrollable list`,
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
                    ? 'h-8 [&>td]:sticky [&>td]:bottom-0 [&>td]:bg-card [&>td]:shadow-[inset_0_1px_0_var(--color-border)]'
                    : 'h-8'
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

function Signature({ name, caption }: { name: string; caption: string }) {
    return (
        <div className="border-t border-foreground pt-1.5 text-xs text-muted-foreground">
            <b className="block min-h-5 text-sm text-foreground">{name}</b>
            {caption}
        </div>
    );
}
