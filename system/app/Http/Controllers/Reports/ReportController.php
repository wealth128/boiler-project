<?php

namespace App\Http\Controllers\Reports;

use App\Enums\Category;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\MonthClosure;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Services\ReportBuilder;
use Carbon\CarbonImmutable;
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
    private const DEFAULT_OFFICE = 'Admission / Administrative Office';

    public function index(Request $request, ReportBuilder $builder): Response
    {
        Gate::authorize(Permission::ViewReports->value);

        $filters = ReportFilters::resolve($request->query(), now()->toImmutable());

        $saved = $this->savedCopy($filters);
        $report = $saved !== null
            ? $this->forCategory($saved['data'], $filters->category)
            : $builder->build($filters->from, $filters->to, $filters->category);

        return Inertia::render('reports/index', [
            'filters' => $filters->toArray(),
            'options' => [
                'months' => $filters->monthOptions,
                'years' => array_map('strval', $filters->yearOptions),
                'categories' => Category::values(),
            ],
            'sheet' => [
                'title' => $filters->title(),
                'period_label' => $filters->label,
                'category' => $filters->category?->value,
                ...$this->header(),
            ],
            'report' => $report,
            // The month's status for a monthly report: null for other periods.
            'month_status' => $filters->period() === null ? null : ['closed' => $saved !== null || MonthClosure::isClosed($filters->month)],
            'snapshot' => $saved === null ? null : [
                'month_label' => $filters->label,
                'taken_at' => $saved['taken_at']->toIso8601String(),
            ],
        ]);
    }

    /**
     * The saved copy for a closed month, or null when the report is counted live.
     * A copy in an older shape (before the full report was saved) is not used.
     *
     * @return array{data: array<string, mixed>, taken_at: CarbonImmutable}|null
     */
    private function savedCopy(ReportFilters $filters): ?array
    {
        $period = $filters->period();

        if ($period === null) {
            return null;
        }

        $closure = MonthClosure::query()->where('period', $period)->where('is_closed', true)->first();
        $snapshot = $closure === null ? null : ReportSnapshot::query()->where('period', $period)->first();

        if ($snapshot === null || ($snapshot->data['version'] ?? null) !== ReportBuilder::VERSION) {
            return null;
        }

        return ['data' => $snapshot->data, 'taken_at' => CarbonImmutable::instance($closure->closed_at)];
    }

    /**
     * Keep only the chosen category of a saved report. The grand totals of
     * the saved copy are for all categories, so with one category chosen the
     * totals are that category's own.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function forCategory(array $data, ?Category $category): array
    {
        if ($category === null) {
            return $data;
        }

        $only = $data['categories'][$category->value];
        $data['categories'] = [$category->value => $only];
        $data['totals'] = ['patients' => $only['patients'], 'visits' => $only['visits']];

        return $data;
    }

    /**
     * Report header and signatures from the settings table.
     *
     * @return array{hospital_name: string, office: string, prepared_by: string, prepared_by_title: string, noted_by: string, noted_by_title: string}
     */
    private function header(): array
    {
        $values = Setting::query()
            ->whereIn('key', ['hospital_name', 'report_office', 'prepared_by', 'prepared_by_title', 'noted_by', 'noted_by_title'])
            ->pluck('value', 'key');

        return [
            'hospital_name' => $values->get('hospital_name') ?: '[Hospital Name]',
            'office' => $values->get('report_office') ?: self::DEFAULT_OFFICE,
            'prepared_by' => (string) $values->get('prepared_by'),
            'prepared_by_title' => $values->get('prepared_by_title') ?: 'Admission / Admin Office',
            'noted_by' => (string) $values->get('noted_by'),
            'noted_by_title' => $values->get('noted_by_title') ?: 'Commanding Officer',
        ];
    }
}
