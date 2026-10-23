import { useEffect, useRef } from "react";
import { ChartFigure } from "@/features/reports/chart-figure";
import type {
    ColumnType,
    ReportCell,
    ReportChart,
    ReportTable,
} from "@/features/reports/types";
import { formatCount } from "@/lib/format";
import { cn } from "@/lib/utils";

/** The key of the table the page shows as big numbers instead (ReportSections::TOTALS). */
const TOTALS_KEY = "totals";

/**
 * A figure as text: thousands separators, one decimal and a percent sign for
 * percentages, a plus or minus sign for changes, a dash when there is none.
 * The PDF and Excel show the same.
 */
export function formatCell(cell: ReportCell, type: ColumnType): string {
    if (cell === null) {
        return "–";
    }

    if (typeof cell === "string") {
        return cell;
    }

    const sign = cell > 0 ? "+" : "";
    const decimal = (value: number) =>
        value.toLocaleString("en-US", {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        });

    switch (type) {
        case "pct":
            return `${decimal(cell)}%`;
        case "delta":
            return `${sign}${formatCount(cell)}`;
        case "deltapct":
            return `${sign}${decimal(cell)}%`;
        default:
            return formatCount(cell);
    }
}

type ReportTablesProps = {
    tables: ReportTable[];
    /** The charts of this page by table key: each sits above its table. */
    charts?: Record<string, ReportChart>;
    /** On screen each table is a panel that can be folded; in the print view they are plain. */
    collapsible: boolean;
};

/**
 * The tables of one report page, in the order the server gives them (the
 * same order as the PDF and the Excel file). The big-numbers table is left
 * out: the page shows it as numbers above.
 */
export function ReportTables({
    tables,
    charts,
    collapsible,
}: ReportTablesProps) {
    const container = useRef<HTMLDivElement>(null);

    // Printing the screen (Ctrl + P) always shows every panel open, even one
    // folded on screen. The folded ones are opened just before the browser
    // prints and folded again afterwards. (The print view has no panels to
    // fold: it draws the tables plain.)
    useEffect(() => {
        if (!collapsible) {
            return;
        }

        let folded: HTMLDetailsElement[] = [];

        const openAll = () => {
            folded = Array.from(
                container.current?.querySelectorAll<HTMLDetailsElement>(
                    "details:not([open])",
                ) ?? [],
            );
            folded.forEach((panel) => {
                panel.open = true;
            });
        };
        const foldAgain = () => {
            folded.forEach((panel) => {
                panel.open = false;
            });
            folded = [];
        };

        window.addEventListener("beforeprint", openAll);
        window.addEventListener("afterprint", foldAgain);

        return () => {
            window.removeEventListener("beforeprint", openAll);
            window.removeEventListener("afterprint", foldAgain);
        };
    }, [collapsible]);

    return (
        <div ref={container} className="space-y-3 print:space-y-4">
            {tables
                .filter((table) => table.key !== TOTALS_KEY)
                .map((table) => (
                    <TablePanel
                        key={table.key}
                        table={table}
                        chart={charts?.[table.key]}
                        collapsible={collapsible}
                    />
                ))}
        </div>
    );
}

function TablePanel({
    table,
    chart,
    collapsible,
}: {
    table: ReportTable;
    chart: ReportChart | undefined;
    collapsible: boolean;
}) {
    const heading =
        "text-xs font-semibold tracking-wide text-muted-foreground uppercase";
    // The chart sits above its table, and prints with it on one page when it fits.
    const body = <TableBody table={table} />;

    if (!collapsible) {
        // Print: the chart, then the table's title and the table, like the PDF.
        return (
            <section
                aria-label={table.title}
                className="print:break-inside-avoid"
            >
                {chart && <ChartFigure chart={chart} />}
                <h4 className={cn(heading, "mb-1.5 print:break-after-avoid")}>
                    {table.title}
                </h4>
                {body}
            </section>
        );
    }

    return (
        <details
            open
            className="group rounded-md border print:break-inside-avoid"
            aria-label={table.title}
        >
            <summary
                className={cn(
                    heading,
                    "flex min-h-10 cursor-pointer items-center gap-2 px-3 select-none",
                )}
            >
                <span
                    aria-hidden="true"
                    className="inline-block transition-transform group-open:rotate-90"
                >
                    ▸
                </span>
                {table.title}
            </summary>
            <div className="border-t px-3 py-2">
                {chart && <ChartFigure chart={chart} />}
                {body}
            </div>
        </details>
    );
}

function TableBody({ table }: { table: ReportTable }) {
    return (
        <>
            <div className="relative overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            {table.columns.map((column, index) => (
                                <th
                                    key={`${index}-${column.label}`}
                                    scope="col"
                                    className={cn(
                                        "border-b bg-muted px-2 py-1.5 text-xs font-semibold text-muted-foreground",
                                        column.type === "text"
                                            ? "text-left"
                                            : "text-right",
                                    )}
                                >
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {table.rows.map((row, rowIndex) => (
                            <tr
                                key={rowIndex}
                                className={cn(
                                    "border-b",
                                    row.style === "group" &&
                                        "bg-muted/60 font-bold",
                                    row.style === "total" && "font-bold",
                                )}
                            >
                                {row.cells.map((cell, index) => {
                                    const type = table.columns[index].type;

                                    return (
                                        <td
                                            key={index}
                                            className={cn(
                                                "px-2 py-1.5",
                                                type === "text"
                                                    ? "text-left"
                                                    : "text-right tabular-nums",
                                                index === 0 &&
                                                    row.style === "child" &&
                                                    "pl-5",
                                            )}
                                        >
                                            {formatCell(cell, type)}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {table.note && (
                <p className="mt-1.5 text-xs text-muted-foreground italic">
                    {table.note}
                </p>
            )}
        </>
    );
}
