import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PageMeta } from '@/types';

type PaginationProps = {
    meta: PageMeta;
    /** URL of a page, keeping the current filters. */
    pageUrl: (page: number) => string;
    /** Plural word for the rows, e.g. "visits". */
    noun: string;
};

/**
 * "Showing 51–100 of 1,234 visits" with Previous, page numbers and Next.
 * Hidden when everything fits on one page.
 */
export function Pagination({ meta, pageUrl, noun }: PaginationProps) {
    if (meta.last_page <= 1 || meta.from === null || meta.to === null) {
        return null;
    }

    const current = meta.current_page;

    return (
        <nav
            aria-label="Pages"
            className="flex flex-wrap items-center gap-x-4 gap-y-2"
        >
            <span>
                Showing {formatCount(meta.from)}–{formatCount(meta.to)} of{' '}
                {formatCount(meta.total)} {noun}
            </span>
            <ul className="ml-auto flex flex-wrap items-center gap-1">
                <li>
                    <PageLink
                        page={current - 1}
                        disabled={current <= 1}
                        pageUrl={pageUrl}
                    >
                        Previous
                    </PageLink>
                </li>
                {pageNumbers(current, meta.last_page).map((page, index) =>
                    page === null ? (
                        <li key={`gap-${index}`} aria-hidden="true">
                            …
                        </li>
                    ) : (
                        <li key={page}>
                            <PageLink
                                page={page}
                                current={page === current}
                                pageUrl={pageUrl}
                                label={`Page ${page}`}
                            >
                                {page}
                            </PageLink>
                        </li>
                    ),
                )}
                <li>
                    <PageLink
                        page={current + 1}
                        disabled={current >= meta.last_page}
                        pageUrl={pageUrl}
                    >
                        Next
                    </PageLink>
                </li>
            </ul>
        </nav>
    );
}

function PageLink({
    page,
    pageUrl,
    current = false,
    disabled = false,
    label,
    children,
}: {
    page: number;
    pageUrl: (page: number) => string;
    current?: boolean;
    disabled?: boolean;
    label?: string;
    children: ReactNode;
}) {
    const className =
        'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-sm font-medium tabular-nums';

    if (disabled) {
        return (
            <span className={cn(className, 'opacity-50')} aria-disabled="true">
                {children}
            </span>
        );
    }

    return (
        <Link
            href={pageUrl(page)}
            preserveScroll={false}
            aria-current={current ? 'page' : undefined}
            aria-label={label}
            className={cn(
                className,
                current
                    ? 'border-navy bg-navy text-white dark:text-background'
                    : 'bg-background text-foreground hover:bg-muted',
            )}
        >
            {children}
        </Link>
    );
}

/**
 * Pages to show: the first, the last, and two on each side of the current
 * one. null marks a gap: 1 … 4 5 [6] 7 8 … 20
 */
function pageNumbers(current: number, last: number): (number | null)[] {
    const pages: (number | null)[] = [];

    for (let page = 1; page <= last; page++) {
        if (page === 1 || page === last || Math.abs(page - current) <= 2) {
            pages.push(page);
        } else if (pages[pages.length - 1] !== null) {
            pages.push(null);
        }
    }

    return pages;
}
