<?php

namespace App\Services;

use App\Enums\Category;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Report counts for one month.
 *
 * STUB (Step 5): only the totals per category for now, enough for the
 * snapshot saved when a month is closed. The full report (sex, age
 * brackets, diagnosis Top 10, branch x rank) comes with the Reports feature
 * and must keep this array's keys.
 *
 * Counting rule (planning/mvp-scope.md): each patient once per category per
 * period. Visits in Trash are left out.
 */
class ReportBuilder
{
    /**
     * @return array{period: string, generated_at: string, categories: array<string, array{patients: int, visits: int}>}
     */
    public function build(string $period): array
    {
        [$from, $to] = self::monthRange($period);

        $rows = Visit::query()
            ->where('visited_at', '>=', $from)
            ->where('visited_at', '<', $to)
            ->groupBy('category')
            ->selectRaw('category, COUNT(*) AS visits, COUNT(DISTINCT patient_id) AS patients')
            ->toBase()
            ->get()
            ->keyBy('category');

        $categories = [];

        foreach (Category::cases() as $category) {
            $row = $rows->get($category->value);

            $categories[$category->value] = [
                'patients' => (int) ($row->patients ?? 0),
                'visits' => (int) ($row->visits ?? 0),
            ];
        }

        return [
            'period' => $period,
            'generated_at' => now()->toIso8601String(),
            'categories' => $categories,
        ];
    }

    /**
     * First moment of the month and first moment of the next one (Asia/Manila).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function monthRange(string $period): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Not a month: {$period}. Use YYYY-MM, e.g. 2026-09.");
        }

        $from = CarbonImmutable::parse($period.'-01')->startOfMonth();

        return [$from, $from->addMonth()];
    }
}
