<?php

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paging shared by every long table (Patient Records, Trash, Audit log): 10 rows per
 * page by default (10, 20 or 50 with ?per_page=), the page numbers for the
 * footer, and the page-past-the-end redirect.
 */
final class TablePaging
{
    /** Rows per page when ?per_page= is missing or not allowed. */
    public const DEFAULT_PER_PAGE = 10;

    /** The only sizes the "Rows per page" dropdown offers. */
    public const PER_PAGE_OPTIONS = [10, 20, 50];

    /**
     * Rows per page from ?per_page=. Only 10, 20 or 50 are allowed. Anything
     * else (missing, 999, "abc", a list) is 10.
     */
    public static function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        if (is_string($value) && ctype_digit($value) && in_array((int) $value, self::PER_PAGE_OPTIONS, true)) {
            return (int) $value;
        }

        return self::DEFAULT_PER_PAGE;
    }

    /**
     * Page numbers for the table footer.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}
     */
    public static function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /**
     * A page number past the end (e.g. after the last row of the last page
     * went to Trash) goes to the last page instead of an empty table.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    public static function redirectPastLastPage(Request $request, LengthAwarePaginator $page): ?RedirectResponse
    {
        if ($page->total() === 0 || $page->currentPage() <= $page->lastPage()) {
            return null;
        }

        return redirect($request->fullUrlWithQuery(['page' => $page->lastPage()]));
    }
}
