import { Fragment } from "react";
import type { ReactNode } from "react";
import { EmptyState } from "@/components/shared/empty-state";
import { useIsMobile } from "@/hooks/use-mobile";
import { cn } from "@/lib/utils";

export type DataTableColumn<T> = {
    /** Unique per table. */
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    /** "right" for numbers: right-aligned with even digit widths. */
    align?: "left" | "right";
    /** Extra classes for this column's cells, e.g. "font-mono". */
    className?: string;
};

type DataTableProps<T> = {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    /** Read by screen readers; describes the table. */
    caption?: string;
    /** Shown in place of the rows when there are none. */
    empty?: ReactNode;
    /** Extra classes for a row, e.g. to grey out a voided visit. */
    rowClassName?: (row: T) => string | undefined;
    /**
     * Extra content right under a row, e.g. an inline note. Return null for
     * rows that need nothing. Rendered inside one full-width cell.
     */
    rowDetail?: (row: T) => ReactNode;
    /**
     * Stacked card for one row. When given, screens narrower than 768 px show
     * one card per row instead of the table (the most-used tables only). The
     * table is still used from 768 px up.
     */
    card?: (row: T) => ReactNode;
    /** Content under the table, e.g. "Showing the latest 60" or pagination. */
    footer?: ReactNode;
    className?: string;
};

/**
 * Plain table in the prototype's style: uppercase headers on a grey band,
 * thin row lines. On a narrow screen the table scrolls sideways inside its
 * own box (never the whole page) and the first column stays in view; a table
 * that passes `card` becomes stacked cards below 768 px instead.
 * Sorting, filters and pagination belong to each feature.
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    caption,
    empty = <EmptyState message="Nothing to show." />,
    rowClassName,
    rowDetail,
    card,
    footer,
    className,
}: DataTableProps<T>) {
    // Only one of the two layouts is in the page, so ids and form fields in a
    // row's detail are never doubled.
    const stacked = useIsMobile() && card !== undefined;

    const alignClass = (align: DataTableColumn<T>["align"]) =>
        align === "right" ? "text-right tabular-nums" : "text-left";

    // The first column stays visible while the rest scrolls. It needs its own
    // background so the cells passing under it are hidden.
    const stickyClass = (index: number) =>
        index === 0 ? "sticky left-0 z-[1]" : undefined;

    return (
        <div className={className}>
            {stacked && card ? (
                rows.length === 0 ? (
                    <div>{empty}</div>
                ) : (
                    <ul>
                        {rows.map((row) => {
                            const detail = rowDetail?.(row);

                            return (
                                <li
                                    key={rowKey(row)}
                                    className={cn(
                                        "border-b bg-card px-4 py-3",
                                        rowClassName?.(row),
                                    )}
                                >
                                    {card(row)}
                                    {detail && (
                                        <div className="mt-2">{detail}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )
            ) : (
                <div className="relative overflow-x-auto overscroll-x-contain">
                    <table className="w-full border-collapse text-sm">
                        {caption && (
                            <caption className="sr-only">{caption}</caption>
                        )}
                        <thead>
                            <tr>
                                {columns.map((column, index) => (
                                    <th
                                        key={column.key}
                                        scope="col"
                                        className={cn(
                                            "border-b bg-muted px-2.5 py-2 text-xs font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase",
                                            alignClass(column.align),
                                            stickyClass(index),
                                        )}
                                    >
                                        {column.header}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={columns.length}
                                        className="p-0"
                                    >
                                        {empty}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => {
                                    const detail = rowDetail?.(row);

                                    return (
                                        <Fragment key={rowKey(row)}>
                                            <tr
                                                className={cn(
                                                    "bg-card",
                                                    rowClassName?.(row),
                                                )}
                                            >
                                                {columns.map(
                                                    (column, index) => (
                                                        <td
                                                            key={column.key}
                                                            className={cn(
                                                                "border-b px-2.5 py-2 align-top",
                                                                alignClass(
                                                                    column.align,
                                                                ),
                                                                index === 0 &&
                                                                    "bg-inherit",
                                                                stickyClass(
                                                                    index,
                                                                ),
                                                                column.className,
                                                            )}
                                                        >
                                                            {column.cell(row)}
                                                        </td>
                                                    ),
                                                )}
                                            </tr>
                                            {detail && (
                                                <tr>
                                                    <td
                                                        colSpan={columns.length}
                                                        className="border-b px-2.5 py-2"
                                                    >
                                                        {detail}
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            )}
            {footer && (
                <div className="px-4 py-2.5 text-sm text-muted-foreground">
                    {footer}
                </div>
            )}
        </div>
    );
}
