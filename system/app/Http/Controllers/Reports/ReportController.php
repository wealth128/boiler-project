<?php

namespace App\Http\Controllers\Reports;

use App\Enums\Category;
use App\Enums\MonthReportDeleteReason;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\MonthClosure;
use App\Models\Visit;
use App\Services\ReportBuilder;
use App\Services\ReportSheetResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports on screen (prototype/prototype.html, "Reports"). Admin and Viewer
 * (CO): role:admin,viewer on the route plus the view-reports Gate.
 *
 * The choices live in the URL (see ReportFilters), so Refresh and Back keep
 * them. Counting is in ReportBuilder. A closed month shows the saved copy
 * taken when it was closed; quarterly, annual and custom reports are always
 * counted live.
 */
class ReportController extends Controller
{
    public function index(Request $request, ReportSheetResolver $resolver): Response
    {
        Gate::authorize(Permission::ViewReports->value);

        ['filters' => $filters, 'report' => $report, 'sections' => $sections, 'sheet' => $sheet, 'snapshot' => $snapshot] = $resolver->resolve($request->query());

        return Inertia::render('reports/index', [
            'filters' => $filters->toArray(),
            'options' => [
                'months' => $filters->monthOptions,
                'years' => array_map('strval', $filters->yearOptions),
                'categories' => Category::values(),
            ],
            'sheet' => $sheet,
            'report' => $report,
            // The tables of every page; the tab picks the page.
            'sections' => $sections,
            // The month's status for a monthly report: null for other periods.
            'month_status' => $this->monthStatus($filters, $snapshot !== null),
            // Admin only: the reasons for "Delete month report".
            'month_delete_reasons' => Gate::allows(Permission::CloseMonth->value) ? MonthReportDeleteReason::options() : [],
            'snapshot' => $snapshot === null ? null : [
                'month_label' => $snapshot['month_label'],
                'taken_at' => $snapshot['taken_at']->toIso8601String(),
            ],
        ]);
    }

    /**
     * Status of a monthly report (null for other periods): closed or open,
     * the period for the month buttons, and how many visits "Delete month
     * report" would move to Trash. Only Admin gets the buttons (canManage).
     *
     * @return array{period: string, closed: bool, visit_count: int, can_manage: bool}|null
     */
    private function monthStatus(ReportFilters $filters, bool $hasSavedCopy): ?array
    {
        $period = $filters->period();

        if ($period === null) {
            return null;
        }

        [$from, $to] = ReportBuilder::monthRange($period);

        return [
            'period' => $period,
            'closed' => $hasSavedCopy || MonthClosure::isClosed($period),
            'visit_count' => Visit::query()->where('visited_at', '>=', $from)->where('visited_at', '<', $to)->count(),
            'can_manage' => Gate::allows(Permission::CloseMonth->value),
        ];
    }
}
