<?php

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paging shared by every long table (Patient Records, Trash): 50 rows per
 * page, the page numbers for the footer, and the page-past-the-end redirect.
 */
final class TablePaging
{
    /** Rows per page. */
    public const PER_PAGE = 50;

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
