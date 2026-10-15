<?php

namespace App\Services;

use App\Enums\Category;
use App\Http\Controllers\Reports\ReportFilters;
use App\Models\MonthClosure;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Works out the one report a request asks for, for the Reports screen, the
 * PDF and the Excel file alike, so a file always matches the screen and
 * nothing is counted in a second place.
 *
 * The choices come from the URL through ReportFilters (bad values fall back
 * to the defaults). A closed month gives its saved copy; every other
 * period is counted live by ReportBuilder.
 */
class ReportSheetResolver
{
    private const DEFAULT_OFFICE = 'Admission / Administrative Office';

    public function __construct(private readonly ReportBuilder $builder) {}

    /**
     * @param  array<string, mixed>  $query  the query string
     * @return array{filters: ReportFilters, report: array<string, mixed>, sheet: array<string, mixed>, snapshot: array{month_label: string, taken_at: CarbonImmutable}|null}
     */
    public function resolve(array $query): array
    {
        $filters = ReportFilters::resolve($query, now()->toImmutable());

        $saved = $this->savedCopy($filters);
        $report = $saved !== null
            ? $this->forCategory($saved['data'], $filters->category)
            : $this->builder->build($filters->from, $filters->to, $filters->category);

        return [
            'filters' => $filters,
            'report' => $report,
            'sheet' => [
                'title' => $filters->title(),
                'period_label' => $filters->label,
                'category' => $filters->category?->value,
                ...$this->header(),
            ],
            'snapshot' => $saved === null ? null : [
                'month_label' => $filters->label,
                'taken_at' => $saved['taken_at'],
            ],
        ];
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
