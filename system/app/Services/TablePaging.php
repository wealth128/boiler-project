<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

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

    /** The highest page number accepted from ?page=. */
    public const MAX_PAGE = 100000;

    /**
     * The page number from ?page=, between 1 and 100000. Missing, not a number
     * or a list is page 1; a huge number is 100000 (and then the page past the
     * end goes to the last page), so it can never overflow a database offset.
     */
    public static function page(Request $request, string $name = 'page'): int
    {
        $value = $request->query($name);

        if (! is_string($value) || ! ctype_digit($value)) {
            return 1;
        }

        if (strlen(ltrim($value, '0')) > 6) {
            return self::MAX_PAGE;
        }

        return min(max(1, (int) $value), self::MAX_PAGE);
    }

    /** Page numbers shown at once around the current page: 4 5 6. */
    public const WINDOW = 3;

    /**
     * The page numbers to show around the current page, never more than
     * three: 4 5 6, or 1 2 3 at the start and 8 9 10 at the end. First, Last,
     * Previous, Next and the jump list do the rest.
     *
     * @return list<int>
     */
    public static function window(int $current, int $last): array
    {
        $last = max(1, $last);
        $current = min(max(1, $current), $last);
        $start = max(1, min($current - 1, $last - self::WINDOW + 1));

        return range($start, min($last, $start + self::WINDOW - 1));
    }

    /**
     * One page of a long table (Records, Audit log), read in two steps so a
     * late page stays fast: first only the ids of that page, which MySQL
     * finds in an index while skipping the earlier rows without reading
     * them, then the full rows of those ids with their relations and counts.
     * Same rows, same order and same page numbers as Eloquent's paginate():
     * page 2,000 of the Audit log no longer reads 20,000 whole rows first.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  with its filters and orderBy, without limit
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $perPage = self::perPage($request);
        $current = Paginator::resolveCurrentPage();
        $model = $query->getModel();

        $total = (clone $query)->toBase()->getCountForPagination();

        // A copy: without global scopes toBase() is the query itself, and select() would change it.
        $ids = $total === 0 ? [] : (clone $query)->toBase()
            ->select($model->getQualifiedKeyName())
            ->forPage($current, $perPage)
            ->pluck($model->getKeyName())
            ->all();

        $position = array_flip($ids);

        $rows = $ids === [] ? $model->newCollection() : (clone $query)
            ->reorder()
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Model $row): int => $position[$row->getKey()])
            ->values();

        return new LengthAwarePaginator($rows, $total, $perPage, $current, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Page an in-memory list (the System health checks) the same way the
     * database tables are paged, so the footer and the redirect work alike.
     *
     * @param  Collection<int, mixed>  $items
     * @return LengthAwarePaginator<int, mixed>
     */
    public static function fromCollection(Request $request, Collection $items): LengthAwarePaginator
    {
        $perPage = self::perPage($request);
        $current = self::page($request);

        return (new LengthAwarePaginator(
            $items->forPage($current, $perPage)->values(),
            $items->count(),
            $perPage,
            $current,
            ['path' => $request->url()],
        ))->withQueryString();
    }

    /**
     * Page numbers for the table footer.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, pages: list<int>}
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
            'pages' => self::window($page->currentPage(), $page->lastPage()),
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
