import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/shared/empty-state';
import { cn } from '@/lib/utils';

export type DataTableColumn<T> = {
    /** Unique per table. */
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    /** "right" for numbers: right-aligned with even digit widths. */
    align?: 'left' | 'right';
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
    /** Content under the table, e.g. "Showing the latest 60" or pagination. */
    footer?: ReactNode;
    className?: string;
};

/**
 * Plain table in the prototype's style: small uppercase headers on a grey
 * band, thin row lines, and sideways scrolling on narrow screens.
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
    footer,
    className,
}: DataTableProps<T>) {
    const alignClass = (align: DataTableColumn<T>['align']) =>
        align === 'right' ? 'text-right tabular-nums' : 'text-left';

    return (
        <div className={className}>
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    {caption && (
                        <caption className="sr-only">{caption}</caption>
                    )}
                    <thead>
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    className={cn(
                                        'border-b bg-muted px-2.5 py-2 text-xs font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase',
                                        alignClass(column.align),
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
                                <td colSpan={columns.length} className="p-0">
                                    {empty}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => {
                                const detail = rowDetail?.(row);

                                return (
                                    <Fragment key={rowKey(row)}>
                                        <tr className={rowClassName?.(row)}>
                                            {columns.map((column) => (
                                                <td
                                                    key={column.key}
                                                    className={cn(
                                                        'border-b px-2.5 py-2 align-top',
                                                        alignClass(
                                                            column.align,
                                                        ),
                                                        column.className,
                                                    )}
                                                >
                                                    {column.cell(row)}
                                                </td>
                                            ))}
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
            {footer && (
                <div className="px-4 py-2.5 text-sm text-muted-foreground">
                    {footer}
                </div>
            )}
        </div>
    );
}
