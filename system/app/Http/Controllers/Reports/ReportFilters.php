<?php

namespace App\Http\Controllers\Reports;

use App\Enums\Category;
use App\Services\ReportBuilder;
use Carbon\CarbonImmutable;

/**
 * What the Reports screen shows, worked out from the URL
 * (?type=&month=&quarter=&year=&from=&to=&category=).
 *
 * Every value is checked here. A missing or bad value falls back to its
 * default instead of an error page, so a hand-typed or old link still opens
 * a report. With nothing chosen: last month, the ER tab.
 *
 * `category` is the tab: opd, er, admission or summary (any letter case,
 * anything else is ER). It only chooses what is shown; the report itself
 * always holds every category. `scope=all` (PDF and Print only) asks for
 * every page: the summary and each category.
 *
 * Quarters and years are calendar periods. All dates are Asia/Manila (the
 * app time zone). `from` and `to` on this object are the first moment of
 * the period and the first moment AFTER it, which is what ReportBuilder takes.
 */
final class ReportFilters
{
    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const ANNUAL = 'annual';

    public const CUSTOM = 'custom';

    /** Month and year lists start in January of this year. */
    public const FIRST_YEAR = 2026;

    public const TYPES = [self::MONTHLY, self::QUARTERLY, self::ANNUAL, self::CUSTOM];

    public const SUMMARY = 'summary';

    /** The tab shown when none (or a bad one) is chosen. */
    public const DEFAULT_TAB = 'er';

    private const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private function __construct(
        public readonly string $type,
        public readonly string $month,
        public readonly int $quarter,
        public readonly int $year,
        public readonly string $customFrom,
        public readonly string $customTo,
        /** The category of the tab, or null on the Summary tab. */
        public readonly ?Category $category,
        /** The tab: opd, er, admission or summary. */
        public readonly string $tab,
        /** Every page (summary and each category), for the PDF and Print. */
        public readonly bool $all,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        /** @var list<array{value: string, label: string}> */
        public readonly array $monthOptions,
        /** @var list<int> */
        public readonly array $yearOptions,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the query string
     */
    public static function resolve(array $input, CarbonImmutable $now): self
    {
        $currentMonth = $now->startOfMonth();
        $lastMonth = $currentMonth->subMonth();

        // Month and year lists: from January 2026 (the first month of the
        // system) up to now. Last month is always in them, since it is the default.
        $first = CarbonImmutable::create(self::FIRST_YEAR, 1, 1, 0, 0, 0, $now->timezone)->min($lastMonth);

        $monthOptions = [];
        for ($m = $currentMonth; $m->greaterThanOrEqualTo($first); $m = $m->subMonth()) {
            $monthOptions[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }
        $yearOptions = range($now->year, $first->year);

        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        // Tab: opd, er, admission or summary, in any letter case. Else ER.
        $tab = strtolower($text('category'));
        $category = collect(Category::cases())->first(fn (Category $c): bool => strtolower($c->value) === $tab);
        if ($category === null && $tab !== self::SUMMARY) {
            $tab = self::DEFAULT_TAB;
            $category = Category::ER;
        }
        $all = $text('scope') === 'all';

        // Month
        $month = $text('month');
        if (! in_array($month, array_column($monthOptions, 'value'), true)) {
            $month = $lastMonth->format('Y-m');
        }

        // Year and quarter. The default is the one that holds last month.
        $year = ctype_digit($text('year')) ? (int) $text('year') : 0;
        if (! in_array($year, $yearOptions, true)) {
            $year = $lastMonth->year;
        }

        $quarter = ctype_digit($text('quarter')) ? (int) $text('quarter') : 0;
        if ($quarter < 1 || $quarter > 4) {
            $quarter = intdiv($lastMonth->month - 1, 3) + 1;
        }
        // A quarter that hasn't started yet is not a report.
        if (self::quarterStart($year, $quarter)->greaterThan($now)) {
            $year = $lastMonth->year;
            $quarter = intdiv($lastMonth->month - 1, 3) + 1;
        }

        // Custom range: both dates, real, and To not before From.
        $customFrom = self::date($text('from'), $now);
        $customTo = self::date($text('to'), $now);
        $customOk = $customFrom !== null && $customTo !== null && $customTo->greaterThanOrEqualTo($customFrom);

        $type = in_array($text('type'), self::TYPES, true) ? $text('type') : self::MONTHLY;
        if ($type === self::CUSTOM && ! $customOk) {
            $type = self::MONTHLY;
        }

        if (! $customOk) {
            // Start Custom on the default month, to save typing.
            $customFrom = $lastMonth;
            $customTo = $lastMonth->endOfMonth()->startOfDay();
        }

        [$from, $to, $label] = match ($type) {
            self::QUARTERLY => (function () use ($year, $quarter): array {
                $start = self::quarterStart($year, $quarter);
                $lastShort = self::MONTH_NAMES[$quarter * 3 - 1];

                return [$start, $start->addMonths(3), "Q{$quarter} {$year} (".self::MONTH_NAMES[($quarter - 1) * 3]."–{$lastShort})"];
            })(),
            self::ANNUAL => [
                $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $now->timezone),
                $start->addYear(),
                "Calendar Year {$year}",
            ],
            self::CUSTOM => [
                $customFrom,
                $customTo->addDay(),
                $customFrom->format('d-M-Y').' to '.$customTo->format('d-M-Y'),
            ],
            default => (function () use ($month): array {
                [$start, $end] = ReportBuilder::monthRange($month);

                return [$start, $end, $start->format('F Y')];
            })(),
        };

        return new self(
            $type, $month, $quarter, $year,
            $customFrom->format('Y-m-d'), $customTo->format('Y-m-d'),
            $category, $tab, $all, $from, $to, $label, $monthOptions, $yearOptions,
        );
    }

    /** "2026-09" when this is a monthly report, otherwise null. */
    public function period(): ?string
    {
        return $this->type === self::MONTHLY ? $this->month : null;
    }

    /**
     * The file name for an export, without the extension:
     * census-report_2026-09_er, census-report_2026-Q3_summary,
     * census-report_2026_all, census-report_2026-09-01_to_2026-09-30_all.
     * The last part is the tab, or "all" for every page (and for Excel,
     * which always holds every category: pass $everything).
     */
    public function fileStem(bool $everything = false): string
    {
        $period = str_replace(' ', '_', $this->periodCode());

        return 'census-report_'.$period.'_'.($everything || $this->all ? 'all' : $this->tab);
    }

    /**
     * What was taken, as written in the audit log: "All" (every page),
     * "Summary", "OPD", "ER" or "Admission".
     */
    public function categoryName(): string
    {
        return $this->all ? 'All' : ($this->category?->value ?? 'Summary');
    }

    /**
     * The pages of a PDF or Print: the Summary and each category for
     * "All categories", otherwise only the chosen tab. A page is "summary"
     * or a category name.
     *
     * @return list<string>
     */
    public function pages(): array
    {
        if ($this->all) {
            return [self::SUMMARY, ...Category::values()];
        }

        return [$this->category?->value ?? self::SUMMARY];
    }

    /**
     * The period as written in the audit log: "2026-09", "2026-Q3", "2026"
     * or "2026-09-01 to 2026-09-30".
     */
    public function periodCode(): string
    {
        return match ($this->type) {
            self::MONTHLY => $this->month,
            self::QUARTERLY => "{$this->year}-Q{$this->quarter}",
            self::ANNUAL => (string) $this->year,
            default => "{$this->customFrom} to {$this->customTo}",
        };
    }

    /** The title on the report sheet. */
    public function title(): string
    {
        return match ($this->type) {
            self::MONTHLY => 'Monthly Patient Census Report',
            self::QUARTERLY => 'Quarterly Patient Census Report',
            self::ANNUAL => 'Annual Patient Census Report',
            default => 'Patient Census Report',
        };
    }

    /**
     * The choices as the screen shows them.
     *
     * @return array{type: string, month: string, quarter: string, year: string, from: string, to: string, category: string} category is the tab: opd, er, admission or summary
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'month' => $this->month,
            'quarter' => (string) $this->quarter,
            'year' => (string) $this->year,
            'from' => $this->customFrom,
            'to' => $this->customTo,
            'category' => $this->tab,
        ];
    }

    private static function quarterStart(int $year, int $quarter): CarbonImmutable
    {
        return CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1, 0, 0, 0, config('app.timezone'));
    }

    /** A real "YYYY-MM-DD" date in the app time zone, or null. */
    private static function date(string $value, CarbonImmutable $now): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || (int) $m[1] < 1900 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, $now->timezone);
    }
}
