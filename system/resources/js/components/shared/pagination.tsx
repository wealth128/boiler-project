import { Link, router } from "@inertiajs/react";
import { useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { NativeSelect } from "@/components/ui/native-select";
import { formatCount } from "@/lib/format";
import { JUMP_SELECT_MAX, PER_PAGE_OPTIONS } from "@/lib/paging";
import { cn } from "@/lib/utils";
import type { PageMeta } from "@/types";

type PaginationProps = {
    meta: PageMeta;
    /** URL of a page, keeping the current filters and rows per page. */
    pageUrl: (page: number) => string;
    /** URL of page 1 with this many rows per page, keeping the filters. */
    perPageUrl: (perPage: number) => string;
    /** Plural word for the rows, e.g. "visits". */
    noun: string;
    /**
     * Show the "Rows per page" dropdown even when all rows fit on one page of
     * 10 (as long as there is at least one row).
     */
    alwaysShow?: boolean;
};

const buttonClass =
    "inline-flex h-8 min-w-8 max-lg:h-10 max-lg:min-w-10 items-center justify-center rounded-md border px-2.5 text-sm font-medium tabular-nums";

/**
 * The footer of every long table. "Showing 41–50 of 1,234 visits", then
 * « First, ‹ Prev, at most three page numbers around the current page
 * (4 5 6), Next ›, Last », "Page [jump] of N" and "Rows per page" (10, 20,
 * 50). Choosing a size goes back to page 1. On a phone only Prev,
 * "Page x of N" and Next show. Hidden when there are no more rows than the
 * smallest page size, unless alwaysShow is set.
 */
export function Pagination({
    meta,
    pageUrl,
    perPageUrl,
    noun,
    alwaysShow = false,
}: PaginationProps) {
    if (
        (meta.total <= PER_PAGE_OPTIONS[0] && !alwaysShow) ||
        meta.from === null ||
        meta.to === null
    ) {
        return null;
    }

    const current = meta.current_page;
    const last = meta.last_page;

    return (
        <nav
            aria-label="Pages"
            className="flex flex-wrap items-center gap-x-4 gap-y-2"
        >
            <span>
                Showing {formatCount(meta.from)}–{formatCount(meta.to)} of{" "}
                {formatCount(meta.total)} {noun}
            </span>
            <div className="ml-auto flex flex-wrap items-center gap-x-4 gap-y-2">
                {last > 1 && (
                    <>
                        <ul className="flex items-center gap-1">
                            <li className="hidden sm:block">
                                <PageLink
                                    page={1}
                                    disabled={current <= 1}
                                    pageUrl={pageUrl}
                                    label="First page"
                                >
                                    « First
                                </PageLink>
                            </li>
                            <li>
                                <PageLink
                                    page={current - 1}
                                    disabled={current <= 1}
                                    pageUrl={pageUrl}
                                    label="Previous page"
                                >
                                    ‹ Prev
                                </PageLink>
                            </li>
                            <li className="px-2 text-sm sm:hidden">
                                Page {formatCount(current)} of{" "}
                                {formatCount(last)}
                            </li>
                            {meta.pages.map((page) => (
                                <li key={page} className="hidden sm:block">
                                    <PageLink
                                        page={page}
                                        current={page === current}
                                        pageUrl={pageUrl}
                                        label={`Page ${page}`}
                                    >
                                        {page}
                                    </PageLink>
                                </li>
                            ))}
                            <li>
                                <PageLink
                                    page={current + 1}
                                    disabled={current >= last}
                                    pageUrl={pageUrl}
                                    label="Next page"
                                >
                                    Next ›
                                </PageLink>
                            </li>
                            <li className="hidden sm:block">
                                <PageLink
                                    page={last}
                                    disabled={current >= last}
                                    pageUrl={pageUrl}
                                    label="Last page"
                                >
                                    Last »
                                </PageLink>
                            </li>
                        </ul>
                        <div className="hidden sm:block">
                            <JumpToPage
                                current={current}
                                last={last}
                                pageUrl={pageUrl}
                            />
                        </div>
                    </>
                )}
                <label className="flex items-center gap-2 whitespace-nowrap">
                    Rows per page
                    <NativeSelect
                        value={meta.per_page}
                        className="h-8 w-auto py-0"
                        onChange={(e) =>
                            router.visit(perPageUrl(Number(e.target.value)), {
                                preserveScroll: true,
                            })
                        }
                    >
                        {PER_PAGE_OPTIONS.map((size) => (
                            <option key={size} value={size}>
                                {size}
                            </option>
                        ))}
                    </NativeSelect>
                </label>
            </div>
        </nav>
    );
}

/**
 * "Page [dropdown] of N": a list of every page up to 200 pages, a small
 * number box with a Go button beyond that.
 */
function JumpToPage({
    current,
    last,
    pageUrl,
}: {
    current: number;
    last: number;
    pageUrl: (page: number) => string;
}) {
    const [typed, setTyped] = useState("");

    const go = (page: number) => router.visit(pageUrl(page));

    if (last <= JUMP_SELECT_MAX) {
        return (
            <label className="flex items-center gap-2 whitespace-nowrap">
                Page
                <NativeSelect
                    value={current}
                    className="h-8 w-auto py-0"
                    aria-label="Jump to page"
                    onChange={(e) => go(Number(e.target.value))}
                >
                    {Array.from({ length: last }, (_, i) => i + 1).map(
                        (page) => (
                            <option key={page} value={page}>
                                {page}
                            </option>
                        ),
                    )}
                </NativeSelect>
                of {formatCount(last)}
            </label>
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        const page = Number(typed);

        if (Number.isInteger(page) && page >= 1) {
            go(Math.min(page, last));
        }
    }

    return (
        <form
            onSubmit={submit}
            className="flex items-center gap-2 whitespace-nowrap"
        >
            <label className="flex items-center gap-2">
                Page
                <input
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={last}
                    value={typed}
                    placeholder={String(current)}
                    onChange={(e) => setTyped(e.target.value)}
                    aria-label="Jump to page"
                    className="h-8 w-20 rounded-md border bg-background px-2 text-sm tabular-nums"
                />
            </label>
            of {formatCount(last)}
            <button
                type="submit"
                className={cn(buttonClass, "bg-background hover:bg-muted")}
            >
                Go
            </button>
        </form>
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
    if (disabled) {
        return (
            <span
                className={cn(buttonClass, "opacity-50")}
                aria-disabled="true"
            >
                {children}
            </span>
        );
    }

    return (
        <Link
            href={pageUrl(page)}
            preserveScroll={false}
            aria-current={current ? "page" : undefined}
            aria-label={label}
            className={cn(
                buttonClass,
                current
                    ? "border-navy bg-navy text-white dark:text-background"
                    : "bg-background text-foreground hover:bg-muted",
            )}
        >
            {children}
        </Link>
    );
}
