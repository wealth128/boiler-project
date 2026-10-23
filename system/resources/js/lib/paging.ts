import type { PageMeta } from "@/types";

/*
 * Rows per page for every long table (Patient Records, Trash, Audit log).
 * Mirrors App\Services\TablePaging: the server accepts only these sizes and
 * uses DEFAULT_PER_PAGE for anything else.
 */

export const PER_PAGE_OPTIONS = [10, 20, 50] as const;

export const DEFAULT_PER_PAGE = 10;

/**
 * The page and per_page parts of a table's URL query. The defaults (page 1,
 * 10 rows) are left out to keep the address short.
 */
export function pagingQuery(
    perPage: number,
    page = 1,
): { page: number | undefined; per_page: number | undefined } {
    return {
        page: page > 1 ? page : undefined,
        per_page: perPage !== DEFAULT_PER_PAGE ? perPage : undefined,
    };
}

/** Is there anything to page through, or to change the size of? */
export function showsPaging(meta: PageMeta): boolean {
    return meta.total > PER_PAGE_OPTIONS[0];
}

/**
 * Up to this many pages "Page [dropdown] of N" lists every page; with more
 * it is a number box with a Go button.
 */
export const JUMP_SELECT_MAX = 200;
