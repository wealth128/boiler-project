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
 * period is counted live by ReportBuilder, or remembered by ReportCache when
 * nothing has changed since it was last counted.
 *
 * The report always holds every category: the tab (OPD, ER, Admission or
 * Summary) only chooses what the screen, Print and PDF show, so the numbers
 * are the same whichever tab is open.
 */
class ReportSheetResolver
{
    public function __construct(
        private readonly ReportCache $reports,
        private readonly HospitalSettings $hospital,
        private readonly FrozenSignatures $frozenSignatures,
        private readonly ReportSections $sections,
        private readonly ReportCharts $charts,
    ) {}

    /**
     * @param  array<string, mixed>  $query  the query string
     * @return array{filters: ReportFilters, report: array<string, mixed>, sections: array<string, list<array<string, mixed>>>, charts: array<string, array<string, array<string, mixed>>>, sheet: array<string, mixed>, snapshot: array{month_label: string, taken_at: CarbonImmutable}|null, frozen: array{period: string, block: array<string, string|null>}|null}
     */
    public function resolve(array $query): array
    {
        $filters = ReportFilters::resolve($query, now()->toImmutable());

        $saved = $this->savedCopy($filters);
        $report = $saved !== null
            ? $this->inCategoryOrder($saved['data'])
            : $this->reports->build($filters->from, $filters->to);

        // Signature pictures only on a final report. Otherwise the names and
        // titles stay, over a blank line, with a note.
        $note = $this->notFinalNote($filters, $saved !== null);

        $sheet = [
            'title' => $filters->title(),
            'period_label' => $filters->label,
            'category' => $filters->category?->value,
            ...$this->header(),
            'signatures_final' => $note === null,
            'signature_note' => $note,
        ];

        // A closed month, and a quarter or year whose months are all closed,
        // show the signature block saved with their snapshot. A month closed
        // before the block was kept uses the current settings.
        $frozen = $note === null ? $this->frozenBlock($filters) : null;

        if ($frozen !== null) {
            $sheet = $this->withFrozenBlock($sheet, $frozen);
        } elseif ($note !== null) {
            $sheet = $this->withoutSignaturePictures($sheet);
        }

        // The tables of every page (summary and each category), drawn the same by screen, Print, PDF and Excel,
        // and the charts that sit above them. Both are made once here and used by every format.
        $sections = $this->sections->pages($report);

        return [
            'filters' => $filters,
            'report' => $report,
            'sections' => $sections,
            'charts' => $this->charts->pages($sections, $filters->label, $report['daily']),
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
     * The signature block saved with this report's snapshot (a closed month,
     * or a quarter or year whose months are all closed), or null when the
     * report has no snapshot or its month was closed before the block was
     * kept. Only asked for when the report is final.
     *
     * @return array{period: string, block: array<string, string|null>}|null
     */
    private function frozenBlock(ReportFilters $filters): ?array
    {
        $key = $this->spanKey($filters);

        if ($key === null) {
            return null;
        }

        $block = ReportSnapshot::query()->where('period', $key)->first()?->signature_block;

        return $block === null ? null : ['period' => $key, 'block' => $block];
    }

    /**
     * The snapshot key of the report's period: the month ("2026-09"), the
     * quarter ("2026-Q3") or the year ("2026"). A custom range has none.
     */
    private function spanKey(ReportFilters $filters): ?string
    {
        return match ($filters->type) {
            ReportFilters::MONTHLY => $filters->month,
            ReportFilters::QUARTERLY => "{$filters->year}-Q{$filters->quarter}",
            ReportFilters::ANNUAL => (string) $filters->year,
            default => null,
        };
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
     * Why the report is not final, or null when it is. A closed month is
     * final. A quarter or year is final only when it has its snapshot, which
     * is saved when its last month is closed (so some month still open, or
     * months closed before snapshots of quarters and years existed, are not
     * final). A custom range is never final.
     */
    private function notFinalNote(ReportFilters $filters, bool $hasSavedCopy): ?string
    {
        if ($filters->type === ReportFilters::CUSTOM) {
            return 'Not final – custom range';
        }

        $periods = [];

        for ($month = $filters->from->startOfMonth(); $month->lessThan($filters->to); $month = $month->addMonth()) {
            $periods[] = $month->format('Y-m');
        }

        $closed = MonthClosure::query()->whereIn('period', $periods)->where('is_closed', true)->count();
        $allClosed = $closed === count($periods);

        if ($filters->type === ReportFilters::MONTHLY) {
            if (! $allClosed) {
                return 'Not final – month still open';
            }

            // Closed, but its saved copy is in an older shape (made before the
            // report grew): counted live, so it is not the closed figure.
            return $hasSavedCopy ? null : 'Not final – no saved copy';
        }

        if ($hasSavedCopy) {
            return null;
        }

        return $allClosed ? 'Not final – no saved copy' : 'Not final – month still open';
    }

    /**
     * The saved copy for a closed month, or for a quarter or year whose
     * months are all closed, or null when the report is counted live. A copy
     * in an older shape (before the full report was saved) is not used.
     *
     * @return array{data: array<string, mixed>, taken_at: CarbonImmutable}|null
     */
    private function savedCopy(ReportFilters $filters): ?array
    {
        if (in_array($filters->type, [ReportFilters::QUARTERLY, ReportFilters::ANNUAL], true)) {
            // Saved when the last month of the quarter or year was closed;
            // reopening any of its months removes it.
            $snapshot = ReportSnapshot::query()->where('period', $this->spanKey($filters))->first();

            if ($snapshot === null || ($snapshot->data['version'] ?? null) !== ReportBuilder::VERSION) {
                return null;
            }

            return ['data' => $snapshot->data, 'taken_at' => CarbonImmutable::instance($snapshot->created_at)];
        }

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
     * A saved copy comes back from MySQL JSON with its categories in the
     * database's own key order (ER, OPD, Admission). Put them in the usual
     * order (OPD, ER, Admission) so the summary and the PDF pages read the
     * same as for a live report. Only the order changes, never a number.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function inCategoryOrder(array $data): array
    {
        $data['categories'] = array_replace(array_flip(Category::values()), $data['categories']);

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
