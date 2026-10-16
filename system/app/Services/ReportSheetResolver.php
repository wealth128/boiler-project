<?php

namespace App\Services;

use App\Enums\Category;
use App\Http\Controllers\Reports\ReportFilters;
use App\Models\MonthClosure;
use App\Models\ReportSnapshot;
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
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly HospitalSettings $hospital,
        private readonly FrozenSignatures $frozenSignatures,
    ) {}

    /**
     * @param  array<string, mixed>  $query  the query string
     * @return array{filters: ReportFilters, report: array<string, mixed>, sheet: array<string, mixed>, snapshot: array{month_label: string, taken_at: CarbonImmutable}|null, frozen: array{period: string, block: array<string, string|null>}|null}
     */
    public function resolve(array $query): array
    {
        $filters = ReportFilters::resolve($query, now()->toImmutable());

        $saved = $this->savedCopy($filters);
        $report = $saved !== null
            ? $this->forCategory($saved['data'], $filters->category)
            : $this->builder->build($filters->from, $filters->to, $filters->category);

        // Signature pictures only on a final report. Otherwise the names and
        // titles stay, over a blank line, with a note.
        $note = $this->notFinalNote($filters);

        $sheet = [
            'title' => $filters->title(),
            'period_label' => $filters->label,
            'category' => $filters->category?->value,
            ...$this->header(),
            'signatures_final' => $note === null,
            'signature_note' => $note,
        ];

        // A closed month shows the signature block it was closed with. A
        // month closed before this was kept uses the current settings, and so
        // do quarters and years.
        $frozen = $note === null ? $this->frozenBlock($filters) : null;

        if ($frozen !== null) {
            $sheet = $this->withFrozenBlock($sheet, $frozen);
        } elseif ($note !== null) {
            $sheet = $this->withoutSignaturePictures($sheet);
        }

        return [
            'filters' => $filters,
            'report' => $report,
            'sheet' => $sheet,
            'snapshot' => $saved === null ? null : [
                'month_label' => $filters->label,
                'taken_at' => $saved['taken_at'],
            ],
            'frozen' => $frozen,
        ];
    }

    /**
     * The signature pictures of a resolved report as data addresses for the
     * PDF: [] when the report is not final, the frozen copies for a closed
     * month, otherwise the current pictures.
     *
     * @param  array{sheet: array<string, mixed>, frozen: array{period: string, block: array<string, string|null>}|null}  $resolved
     * @return array{prepared?: string|null, noted?: string|null}
     */
    public function signatureData(array $resolved): array
    {
        if (! $resolved['sheet']['signatures_final']) {
            return [];
        }

        if ($resolved['frozen'] !== null) {
            ['period' => $period, 'block' => $block] = $resolved['frozen'];

            return [
                'prepared' => $this->frozenSignatures->dataUri($period, 'prepared', $block),
                'noted' => $this->frozenSignatures->dataUri($period, 'noted', $block),
            ];
        }

        return [
            'prepared' => $this->hospital->imageDataUri('prepared_signature'),
            'noted' => $this->hospital->imageDataUri('noted_signature'),
        ];
    }

    /**
     * The signature block saved when this (monthly, closed) report's month was
     * closed, or null when it is not a closed month or was closed before the
     * block was kept.
     *
     * @return array{period: string, block: array<string, string|null>}|null
     */
    private function frozenBlock(ReportFilters $filters): ?array
    {
        $period = $filters->period();

        if ($period === null || ! MonthClosure::isClosed($period)) {
            return null;
        }

        $block = ReportSnapshot::query()->where('period', $period)->first()?->signature_block;

        return $block === null ? null : ['period' => $period, 'block' => $block];
    }

    /**
     * The sheet with the frozen names, titles and picture addresses.
     *
     * @param  array<string, mixed>  $sheet
     * @param  array{period: string, block: array<string, string|null>}  $frozen
     * @return array<string, mixed>
     */
    private function withFrozenBlock(array $sheet, array $frozen): array
    {
        ['period' => $period, 'block' => $block] = $frozen;

        return [
            ...$sheet,
            'prepared_by' => (string) $block['prepared_by'],
            'prepared_by_title' => (string) $block['prepared_by_title'],
            'noted_by' => (string) $block['noted_by'],
            'noted_by_title' => (string) $block['noted_by_title'],
            'prepared_signature_url' => $this->frozenSignatures->url($period, 'prepared', $block),
            'noted_signature_url' => $this->frozenSignatures->url($period, 'noted', $block),
        ];
    }

    /**
     * Why the report is not final, or null when it is. A report is final when
     * every month in it is closed: a closed month, or a quarter or year whose
     * months are all closed. A custom range is never final.
     */
    private function notFinalNote(ReportFilters $filters): ?string
    {
        if ($filters->type === ReportFilters::CUSTOM) {
            return 'Not final – custom range';
        }

        $periods = [];

        for ($month = $filters->from->startOfMonth(); $month->lessThan($filters->to); $month = $month->addMonth()) {
            $periods[] = $month->format('Y-m');
        }

        $closed = MonthClosure::query()->whereIn('period', $periods)->where('is_closed', true)->count();

        return $closed === count($periods) ? null : 'Not final – month still open';
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
     * Report header and signatures from the settings table (HospitalSettings:
     * an empty value shows its default), plus the logo address when one is set.
     *
     * @return array{hospital_name: string, office: string, prepared_by: string, prepared_by_title: string, noted_by: string, noted_by_title: string, logo_url: string|null, prepared_signature_url: string|null, noted_signature_url: string|null}
     */
    private function header(): array
    {
        $values = $this->hospital->values();

        return [
            'hospital_name' => $values['hospital_name'],
            'office' => $values['report_office'],
            'prepared_by' => $values['prepared_by'],
            'prepared_by_title' => $values['prepared_by_title'],
            'noted_by' => $values['noted_by'],
            'noted_by_title' => $values['noted_by_title'],
            'logo_url' => $this->hospital->imageUrl('logo'),
            'prepared_signature_url' => $this->hospital->imageUrl('prepared_signature'),
            'noted_signature_url' => $this->hospital->imageUrl('noted_signature'),
        ];
    }

    /**
     * Header values with the signature pictures dropped, for a report that is not final.
     *
     * @param  array<string, mixed>  $sheet
     * @return array<string, mixed>
     */
    private function withoutSignaturePictures(array $sheet): array
    {
        $sheet['prepared_signature_url'] = null;
        $sheet['noted_signature_url'] = null;

        return $sheet;
    }
}
