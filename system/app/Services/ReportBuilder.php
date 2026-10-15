<?php

namespace App\Services;

use App\Enums\Category;
use App\Enums\Sex;
use App\Models\AgeBracket;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * All report counting (planning/mvp-scope.md sections 3-4, planning/database-schema.md section 4).
 *
 * Counting rule: for each category, each patient counts ONCE in the period,
 * using their latest visit in that category and period. Sex comes from the
 * patient; age bracket, branch and rank come from that latest visit.
 * "Visits" is every visit in that category and period. The Diagnosis table
 * is the exception: it counts every visit, so its total equals "Visits".
 * Visits in Trash are left out everywhere.
 *
 * The result is plain arrays with every label included, so a saved copy
 * (ReportSnapshot) can be shown later without looking at the lists again:
 *
 *   version, period (YYYY-MM or null), from, to (first and last day),
 *   generated_at, totals {patients, visits},
 *   categories[OPD|ER|Admission] {
 *     patients, visits,
 *     sex       [{label, count}]            Male, Female
 *     age       [{label, count}]            each active bracket, "No bracket" only if needed
 *     diagnoses [{label, count}]            every diagnosis with visits, per VISIT
 *     branches  [{name, total, ranks: [{label, count}]}]
 *   }
 *
 * Everything is counted with grouped SQL queries and bindings only.
 */
class ReportBuilder
{
    /** Bump when the array shape changes, so old saved copies are not mistaken for new ones. */
    public const VERSION = 3;

    /**
     * The report for the time from $from up to (not including) $to.
     * Without $to, $from is a month ("2026-09"). With no $category, all
     * three categories are included.
     *
     * @return array<string, mixed>
     */
    public function build(CarbonInterface|string $from, ?CarbonInterface $to = null, ?Category $category = null): array
    {
        $period = null;

        if (is_string($from)) {
            [$start, $end] = self::monthRange($from);
            $period = $from;
        } else {
            $start = CarbonImmutable::instance($from);
            $end = CarbonImmutable::instance($to ?? throw new InvalidArgumentException('A report needs both a start and an end.'));
        }

        $categories = $category !== null ? [$category] : Category::cases();
        $names = array_map(fn (Category $c): string => $c->value, $categories);

        $perCategory = $this->categoryTotals($start, $end, $names);
        $sex = $this->sexCounts($start, $end, $names);
        $age = $this->ageCounts($start, $end, $names);
        $diagnoses = $this->diagnosisCounts($start, $end, $names);
        $branches = $this->branchRankCounts($start, $end, $names);
        $brackets = AgeBracket::query()->ordered()->get();

        $result = [];

        foreach ($names as $name) {
            $result[$name] = [
                'patients' => $perCategory[$name]['patients'],
                'visits' => $perCategory[$name]['visits'],
                'sex' => $this->sexLines($sex[$name] ?? []),
                'age' => $this->ageLines($brackets, $age[$name] ?? []),
                'diagnoses' => $diagnoses[$name] ?? [],
                'branches' => $branches[$name] ?? [],
            ];
        }

        return [
            'version' => self::VERSION,
            'period' => $period,
            'from' => $start->format('Y-m-d'),
            'to' => $end->subSecond()->format('Y-m-d'),
            'generated_at' => now()->toIso8601String(),
            'totals' => $this->grandTotals($start, $end, $names),
            'categories' => $result,
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

    /**
     * Every visit in the period that is not in Trash, in the chosen categories.
     */
    private function visitsInPeriod(CarbonInterface $from, CarbonInterface $to, array $categories): Builder
    {
        return Visit::query()
            ->toBase() // applies the soft-delete rule: visits in Trash are left out
            ->whereIn('visits.category', $categories)
            ->where('visits.visited_at', '>=', $from)
            ->where('visits.visited_at', '<', $to);
    }

    /**
     * One row per patient per category: their latest visit in the period
     * (latest time, then highest id). A visit is left out when the same
     * patient has a later visit in the same category and period.
     */
    private function latestVisits(CarbonInterface $from, CarbonInterface $to, array $categories): Builder
    {
        return $this->visitsInPeriod($from, $to, $categories)->whereNotExists(
            fn (Builder $later) => $later
                ->select(DB::raw(1))
                ->from('visits as later')
                ->whereColumn('later.patient_id', 'visits.patient_id')
                ->whereColumn('later.category', 'visits.category')
                ->whereNull('later.deleted_at')
                ->where('later.visited_at', '>=', $from)
                ->where('later.visited_at', '<', $to)
                ->where(fn (Builder $newer) => $newer
                    ->whereColumn('later.visited_at', '>', 'visits.visited_at')
                    ->orWhere(fn (Builder $tie) => $tie
                        ->whereColumn('later.visited_at', 'visits.visited_at')
                        ->whereColumn('later.id', '>', 'visits.id'))),
        );
    }

    /**
     * Patients counted once across the chosen categories, and all their visits.
     *
     * @return array{patients: int, visits: int}
     */
    private function grandTotals(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        $row = $this->visitsInPeriod($from, $to, $categories)
            ->selectRaw('COUNT(DISTINCT visits.patient_id) AS patients, COUNT(*) AS visits')
            ->first();

        return ['patients' => (int) ($row->patients ?? 0), 'visits' => (int) ($row->visits ?? 0)];
    }

    /**
     * @return array<string, array{patients: int, visits: int}>
     */
    private function categoryTotals(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        $totals = array_fill_keys($categories, ['patients' => 0, 'visits' => 0]);

        $visits = $this->visitsInPeriod($from, $to, $categories)
            ->groupBy('visits.category')
            ->selectRaw('visits.category AS category, COUNT(*) AS n')
            ->pluck('n', 'category');

        $patients = $this->latestVisits($from, $to, $categories)
            ->groupBy('visits.category')
            ->selectRaw('visits.category AS category, COUNT(*) AS n')
            ->pluck('n', 'category');

        foreach ($categories as $name) {
            $totals[$name] = ['patients' => (int) ($patients[$name] ?? 0), 'visits' => (int) ($visits[$name] ?? 0)];
        }

        return $totals;
    }

    /**
     * @return array<string, array<string, int>> category => sex => patients
     */
    private function sexCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        $counts = [];

        $rows = $this->latestVisits($from, $to, $categories)
            ->join('patients', 'patients.id', '=', 'visits.patient_id')
            ->groupBy('visits.category', 'patients.sex')
            ->selectRaw('visits.category AS category, patients.sex AS sex, COUNT(*) AS n')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->category][$row->sex] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{label: string, count: int}>
     */
    private function sexLines(array $counts): array
    {
        return array_map(
            fn (Sex $sex): array => ['label' => $sex->value, 'count' => $counts[$sex->value] ?? 0],
            Sex::cases(),
        );
    }

    /**
     * Patients per exact age. Placing ages into brackets happens in ageLines().
     *
     * @return array<string, array<int, int>> category => age => patients
     */
    private function ageCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        $counts = [];

        $rows = $this->latestVisits($from, $to, $categories)
            ->groupBy('visits.category', 'visits.age')
            ->selectRaw('visits.category AS category, visits.age AS age, COUNT(*) AS n')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->category][(int) $row->age] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * Each active bracket in From-age order, plus "No bracket" when some age fits none.
     *
     * @param  iterable<AgeBracket>  $brackets
     * @param  array<int, int>  $byAge
     * @return list<array{label: string, count: int}>
     */
    private function ageLines(iterable $brackets, array $byAge): array
    {
        $lines = [];
        $unplaced = $byAge;

        foreach ($brackets as $bracket) {
            $count = 0;

            foreach ($byAge as $age => $n) {
                if ($bracket->contains($age)) {
                    $count += $n;
                    unset($unplaced[$age]);
                }
            }

            $lines[] = ['label' => $bracket->label, 'count' => $count];
        }

        if ($unplaced !== []) {
            $lines[] = ['label' => 'No bracket', 'count' => array_sum($unplaced)];
        }

        return $lines;
    }

    /**
     * Diagnoses per VISIT: every visit in the category and period, grouped by
     * diagnosis, highest count first, then by name. No cut-off. "Other
     * (specify)" visits show the typed text as their own lines, merged
     * ignoring upper / lower case and extra spaces ("Other (unspecified)"
     * when nothing was typed). The lines add up to the category's Visits.
     *
     * @return array<string, list<array{label: string, count: int}>>
     */
    private function diagnosisCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        $rows = $this->visitsInPeriod($from, $to, $categories)
            ->join('diagnoses', 'diagnoses.id', '=', 'visits.diagnosis_id')
            ->groupBy('visits.category', 'diagnoses.id', 'diagnoses.name', 'diagnoses.is_other', 'visits.diagnosis_other')
            ->selectRaw('visits.category AS category, diagnoses.id AS diagnosis_id, diagnoses.name AS name, diagnoses.is_other AS is_other, visits.diagnosis_other AS typed, COUNT(*) AS n')
            ->orderByDesc('n')
            ->get();

        // category => key => {label, count}. Rows come biggest first, so a
        // merged typed line is named after its most common spelling.
        $lines = [];

        // Listed diagnoses by name, including ones in Trash. Typed text that
        // matches one of them (ignoring case and extra spaces) counts under
        // it, so the report never shows two lines with the same name.
        $listedNames = [];

        foreach (DB::table('diagnoses')->where('is_other', false)->pluck('name') as $listed) {
            $listedNames[self::nameKey($listed)] = $listed;
        }

        foreach ($rows as $row) {
            if ($row->is_other) {
                $label = trim((string) preg_replace('/\s+/u', ' ', (string) $row->typed));
                $label = $label !== '' ? $label : 'Other (unspecified)';
                $key = self::nameKey($label);

                if (isset($listedNames[$key])) {
                    $label = $listedNames[$key];
                }
            } else {
                // Typed text is ignored for a listed diagnosis.
                $label = $row->name;
                $key = self::nameKey($label);
            }

            $lines[$row->category][$key] ??= ['label' => $label, 'count' => 0];
            $lines[$row->category][$key]['count'] += (int) $row->n;
        }

        $result = [];

        foreach ($categories as $name) {
            $list = array_values($lines[$name] ?? []);

            usort($list, fn (array $a, array $b): int => [$b['count'], mb_strtolower($a['label']), $a['label']]
                <=> [$a['count'], mb_strtolower($b['label']), $b['label']]);

            $result[$name] = $list;
        }

        return $result;
    }

    /** A name for comparing: no extra spaces, upper / lower case ignored. */
    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Each branch that has patients, in branch order, with its total, then
     * its ranks with patients in seniority order (ranks.sort_order). "Other
     * (specify)" ranks show the typed text as their own lines, after the
     * listed ranks.
     *
     * @return array<string, list<array{name: string, total: int, ranks: list<array{label: string, count: int}>}>>
     */
    private function branchRankCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        $rows = $this->latestVisits($from, $to, $categories)
            ->join('branches', 'branches.id', '=', 'visits.branch_id')
            ->join('ranks', 'ranks.id', '=', 'visits.rank_id')
            ->groupBy(
                'visits.category', 'branches.id', 'branches.name', 'branches.sort_order',
                'ranks.id', 'ranks.name', 'ranks.is_other', 'ranks.sort_order', 'visits.rank_other',
            )
            ->selectRaw(
                'visits.category AS category, branches.id AS branch_id, branches.name AS branch, branches.sort_order AS branch_order, '
                .'ranks.id AS rank_id, ranks.name AS rank_name, ranks.is_other AS is_other, ranks.sort_order AS rank_order, '
                .'visits.rank_other AS rank_other, COUNT(*) AS n'
            )
            ->get();

        // category => branch id => {name, order, total, listed: [...], typed: [...]}
        $tree = [];

        foreach ($rows as $row) {
            $n = (int) $row->n;
            $branch = &$tree[$row->category][$row->branch_id];
            $branch ??= ['name' => $row->branch, 'order' => (int) $row->branch_order, 'total' => 0, 'listed' => [], 'typed' => []];
            $branch['total'] += $n;

            if ($row->is_other) {
                $text = trim((string) $row->rank_other);
                $label = $text !== '' ? $text : $row->rank_name;
                $key = mb_strtolower($label);
                $branch['typed'][$key] ??= ['label' => $label, 'count' => 0];
                $branch['typed'][$key]['count'] += $n;
            } else {
                $branch['listed'][$row->rank_id] ??= ['label' => $row->rank_name, 'order' => (int) $row->rank_order, 'count' => 0];
                $branch['listed'][$row->rank_id]['count'] += $n;
            }

            unset($branch);
        }

        $result = [];

        foreach ($tree as $category => $branches) {
            uasort($branches, fn (array $a, array $b): int => [$a['order'], mb_strtolower($a['name'])] <=> [$b['order'], mb_strtolower($b['name'])]);

            foreach ($branches as $branch) {
                $listed = $branch['listed'];
                uasort($listed, fn (array $a, array $b): int => [$a['order'], mb_strtolower($a['label'])] <=> [$b['order'], mb_strtolower($b['label'])]);

                $typed = $branch['typed'];
                uasort($typed, fn (array $a, array $b): int => mb_strtolower($a['label']) <=> mb_strtolower($b['label']));

                $result[$category][] = [
                    'name' => $branch['name'],
                    'total' => $branch['total'],
                    'ranks' => array_values(array_map(
                        fn (array $rank): array => ['label' => $rank['label'], 'count' => $rank['count']],
                        [...$listed, ...$typed],
                    )),
                ];
            }
        }

        return $result;
    }
}
