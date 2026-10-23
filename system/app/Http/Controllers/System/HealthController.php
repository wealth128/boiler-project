<?php

namespace App\Http\Controllers\System;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\HealthChecker;
use App\Services\TablePaging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System health (Admin only): role:admin on the route and the "view-health"
 * Gate here. Shows each check as OK, Warning or Problem with one plain
 * "What to do" line. The same checks run in the terminal with
 * php artisan census:health. Nothing here shows a password, the .env
 * contents or connection details (App\Services\HealthChecker).
 *
 * Problems come first, then Warnings, then OK, so paging never hides a
 * problem on page 2. ?status= (problem, warning, ok) filters the list and
 * ?per_page= / ?page= page it (App\Services\TablePaging). The counts at the
 * top always cover every check, whatever the filter.
 */
class HealthController extends Controller
{
    /** The values ?status= accepts. Anything else shows all checks. */
    public const FILTERS = [HealthChecker::PROBLEM, HealthChecker::WARNING, HealthChecker::OK];

    public function __invoke(Request $request, HealthChecker $health): Response|RedirectResponse
    {
        Gate::authorize(Permission::ViewHealth->value);

        $checks = HealthChecker::sortBySeverity($health->checks());

        $status = $request->query('status');
        $filter = is_string($status) && in_array($status, self::FILTERS, true) ? $status : 'all';

        $shown = collect($checks)
            ->when($filter !== 'all', fn ($rows) => $rows->where('status', $filter))
            ->values();

        $page = TablePaging::fromCollection($request, $shown);

        if ($redirect = TablePaging::redirectPastLastPage($request, $page)) {
            return $redirect;
        }

        return Inertia::render('system/health', [
            'checks' => [
                'data' => $page->items(),
                'meta' => TablePaging::meta($page),
            ],
            'counts' => HealthChecker::counts($checks),
            'filter' => $filter,
            'overall' => HealthChecker::overall($checks),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
