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
 * The Summary figures count every category together: a patient seen in two
 * categories counts once, using their latest visit in any category (the same
 * rule as the grand total). Diagnoses and the daily census count visits.
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
 *     age_sex   [{label, male, female}]     the same brackets, split by sex
 *     diagnoses [{label, count}]            every diagnosis with visits, per VISIT
 *     branches  [{name, total, ranks: [{label, count}]}]
 *     afp       {afp, others}               patients of the AFP branches, and of "Others"
 *     returning {new, returning}            first visit in this category ever in the period, or seen before
 *   }
 *   summary {                               every category together, each patient once
 *     sex, age, age_sex, branches, afp, returning    (same shapes as above)
 *     diagnoses [{label, count}]            the top 20, per VISIT, highest first
 *   }
 *   daily {by: day|month, rows: [{label, counts: {category: visits}}]}
 *   previous {label, patients, visits}|null    the period of the same length before; null before January 2026
 *
 * Everything is counted with grouped SQL queries and bindings only.
 */
class ReportBuilder
{
    /** Bump when the array shape changes, so old saved copies are not mistaken for new ones. */
    public const VERSION = 4;

    /** Nothing was encoded before this day, so there is nothing to compare an earlier period with. */
    public const EARLIEST_DATA = '2026-01-01';

    /** Lines of the Summary diagnosis table. */
    public const TOP_DIAGNOSES = 20;

    /** A period of this many days or fewer gets one daily census row for each day; a longer one gets one row for each month. */
    public const DAILY_MAX_DAYS = 31;

    /** The key of the figures that count every category together. */
    private const ALL = 'All';

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
        $brackets = AgeBracket::query()->ordered()->get();

        $sex = $this->sexCounts($start, $end, $names);
        $ageSex = $this->ageSexCounts($start, $end, $names);
        $diagnoses = $this->diagnosisCounts($start, $end, $names);
        $branches = $this->branchRankCounts($start, $end, $names);
        $afp = $this->afpCounts($start, $end, $names);
        $returning = $this->returningCounts($start, $end, $names);

        $result = [];

        foreach ($names as $name) {
            $ageSexLines = $this->ageSexLines($brackets, $ageSex[$name] ?? []);

            $result[$name] = [
                'patients' => $perCategory[$name]['patients'],
                'visits' => $perCategory[$name]['visits'],
                'sex' => $this->sexLines($sex[$name] ?? []),
                'age' => $this->ageLines($ageSexLines),
                'age_sex' => $ageSexLines,
                'diagnoses' => $diagnoses[$name] ?? [],
                'branches' => $branches[$name] ?? [],
                'afp' => $afp[$name] ?? ['afp' => 0, 'others' => 0],
                'returning' => $returning[$name] ?? ['new' => 0, 'returning' => 0],
            ];
        }

        $allAgeSex = $this->ageSexLines($brackets, $ageSex[self::ALL] ?? []);

        return [
            'version' => self::VERSION,
            'period' => $period,
            'from' => $start->format('Y-m-d'),
            'to' => $end->subSecond()->format('Y-m-d'),
            'generated_at' => now()->toIso8601String(),
            'totals' => $this->grandTotals($start, $end, $names),
            'categories' => $result,
            'summary' => [
                'sex' => $this->sexLines($sex[self::ALL] ?? []),
                'age' => $this->ageLines($allAgeSex),
                'age_sex' => $allAgeSex,
                'diagnoses' => $this->topDiagnoses($diagnoses),
                'branches' => $branches[self::ALL] ?? [],
                'afp' => $afp[self::ALL] ?? ['afp' => 0, 'others' => 0],
                'returning' => $returning[self::ALL] ?? ['new' => 0, 'returning' => 0],
            ],
            'daily' => $this->daily($start, $end, $names),
            'previous' => $this->previous($start, $end, $names),
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
     * patient has a later visit in the same category and period. With
     * $overall the categories are not told apart: one row per patient, their
     * latest visit in any of the categories.
     */
    private function latestVisits(CarbonInterface $from, CarbonInterface $to, array $categories, bool $overall = false): Builder
    {
        return $this->visitsInPeriod($from, $to, $categories)->whereNotExists(
            fn (Builder $later) => $later
                ->select(DB::raw(1))
                ->from('visits as later')
                ->whereColumn('later.patient_id', 'visits.patient_id')
                ->when(! $overall, fn (Builder $same) => $same->whereColumn('later.category', 'visits.category'))
                ->whereNull('later.deleted_at')
                ->whereIn('later.category', $categories)
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
     * The same count made for each category and for every category together.
     * The second set is filed under "All".
     *
     * @param  callable(bool): array<string, mixed>  $count  given $overall
     * @return array<string, mixed>
     */
    private function both(callable $count): array
    {
        return [...$count(false), ...$count(true)];
    }

    /** The key a row is filed under: its category, or "All" when the categories are counted together. */
    private function bucket(bool $overall): string
    {
        return $overall ? "'".self::ALL."'" : 'visits.category';
    }

    /**
     * GROUP BY columns: the category (unless every category is counted
     * together), then the given ones.
     *
     * @return list<string>
     */
    private function groups(bool $overall, string ...$columns): array
    {
        return $overall ? array_values($columns) : ['visits.category', ...$columns];
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
     * @return array<string, array<string, int>> category (or "All") => sex => patients
     */
    private function sexCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        return $this->both(function (bool $overall) use ($from, $to, $categories): array {
            $counts = [];

            $rows = $this->latestVisits($from, $to, $categories, $overall)
                ->join('patients', 'patients.id', '=', 'visits.patient_id')
                ->groupBy(...$this->groups($overall, 'patients.sex'))
                ->selectRaw($this->bucket($overall).' AS category, patients.sex AS sex, COUNT(*) AS n')
                ->get();

            foreach ($rows as $row) {
                $counts[$row->category][$row->sex] = (int) $row->n;
            }

            return $counts;
        });
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
     * Patients per exact age and sex. Placing ages into brackets happens in ageSexLines().
     *
     * @return array<string, array<int, array<string, int>>> category (or "All") => age => sex => patients
     */
    private function ageSexCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        return $this->both(function (bool $overall) use ($from, $to, $categories): array {
            $counts = [];

            $rows = $this->latestVisits($from, $to, $categories, $overall)
                ->join('patients', 'patients.id', '=', 'visits.patient_id')
                ->groupBy(...$this->groups($overall, 'visits.age', 'patients.sex'))
                ->selectRaw($this->bucket($overall).' AS category, visits.age AS age, patients.sex AS sex, COUNT(*) AS n')
                ->get();

            foreach ($rows as $row) {
                $counts[$row->category][(int) $row->age][$row->sex] = (int) $row->n;
            }

            return $counts;
        });
    }

    /**
     * Each active bracket in From-age order, split by sex, plus "No bracket"
     * when some age fits none.
     *
     * @param  iterable<AgeBracket>  $brackets
     * @param  array<int, array<string, int>>  $byAge  age => sex => patients
     * @return list<array{label: string, male: int, female: int}>
     */
    private function ageSexLines(iterable $brackets, array $byAge): array
    {
        $lines = [];
        $unplaced = $byAge;

        foreach ($brackets as $bracket) {
            $male = 0;
            $female = 0;

            foreach ($byAge as $age => $bySex) {
                if ($bracket->contains($age)) {
                    $male += $bySex[Sex::Male->value] ?? 0;
                    $female += $bySex[Sex::Female->value] ?? 0;
                    unset($unplaced[$age]);
                }
            }

            $lines[] = ['label' => $bracket->label, 'male' => $male, 'female' => $female];
        }

        if ($unplaced !== []) {
            $lines[] = [
                'label' => 'No bracket',
                'male' => array_sum(array_column($unplaced, Sex::Male->value)),
                'female' => array_sum(array_column($unplaced, Sex::Female->value)),
            ];
        }

        return $lines;
    }

    /**
     * The age range lines (both sexes together) of the split lines.
     *
     * @param  list<array{label: string, male: int, female: int}>  $lines
     * @return list<array{label: string, count: int}>
     */
    private function ageLines(array $lines): array
    {
        return array_map(fn (array $line): array => ['label' => $line['label'], 'count' => $line['male'] + $line['female']], $lines);
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
            $result[$name] = self::byCountThenName(array_values($lines[$name] ?? []));
        }

        return $result;
    }

    /**
     * Highest count first, then by name (upper / lower case ignored).
     *
     * @param  list<array{label: string, count: int}>  $lines
     * @return list<array{label: string, count: int}>
     */
    private static function byCountThenName(array $lines): array
    {
        usort($lines, fn (array $a, array $b): int => [$b['count'], mb_strtolower($a['label']), $a['label']]
            <=> [$a['count'], mb_strtolower($b['label']), $b['label']]);

        return $lines;
    }

    /**
     * The most common diagnoses of every category together, per visit: each
     * category's lines added up by name, highest first, at most
     * TOP_DIAGNOSES. There is no "Others" line; the full list is on each
     * category's page.
     *
     * @param  array<string, list<array{label: string, count: int}>>  $perCategory
     * @return list<array{label: string, count: int}>
     */
    private function topDiagnoses(array $perCategory): array
    {
        $merged = [];

        foreach ($perCategory as $lines) {
            foreach ($lines as $line) {
                $key = self::nameKey($line['label']);
                $merged[$key] ??= ['label' => $line['label'], 'count' => 0];
                $merged[$key]['count'] += $line['count'];
            }
        }

        return array_slice(self::byCountThenName(array_values($merged)), 0, self::TOP_DIAGNOSES);
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
     * listed ranks. Typed text is cleaned of extra spaces and merged ignoring
     * upper / lower case, and text that matches a listed rank of the same
     * branch (including one in Trash) counts under that rank, the same way
     * typed diagnoses are merged, so a branch never shows two lines for one rank.
     *
     * @return array<string, list<array{name: string, total: int, ranks: list<array{label: string, count: int}>}>> category (or "All") => branches
     */
    private function branchRankCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        // Listed ranks by branch and name, including ones in Trash, to merge typed text into.
        $listedRanks = [];

        foreach (DB::table('ranks')->where('is_other', false)->get(['id', 'branch_id', 'name', 'sort_order']) as $listed) {
            $listedRanks[$listed->branch_id][self::nameKey($listed->name)] = $listed;
        }

        return $this->both(fn (bool $overall): array => $this->branchTrees($from, $to, $categories, $overall, $listedRanks));
    }

    /**
     * @param  array<int, array<string, object>>  $listedRanks
     * @return array<string, list<array{name: string, total: int, ranks: list<array{label: string, count: int}>}>>
     */
    private function branchTrees(CarbonInterface $from, CarbonInterface $to, array $categories, bool $overall, array $listedRanks): array
    {
        $rows = $this->latestVisits($from, $to, $categories, $overall)
            ->join('branches', 'branches.id', '=', 'visits.branch_id')
            ->join('ranks', 'ranks.id', '=', 'visits.rank_id')
            ->groupBy(...$this->groups(
                $overall,
                'branches.id', 'branches.name', 'branches.sort_order',
                'ranks.id', 'ranks.name', 'ranks.is_other', 'ranks.sort_order', 'visits.rank_other',
            ))
            ->selectRaw(
                $this->bucket($overall).' AS category, branches.id AS branch_id, branches.name AS branch, branches.sort_order AS branch_order, '
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

            $text = $row->is_other ? trim((string) preg_replace('/\s+/u', ' ', (string) $row->rank_other)) : '';
            $match = $text !== '' ? ($listedRanks[$row->branch_id][self::nameKey($text)] ?? null) : null;

            if ($match !== null) {
                // Typed text that is a listed rank of this branch counts as that rank.
                $branch['listed'][$match->id] ??= ['label' => $match->name, 'order' => (int) $match->sort_order, 'count' => 0];
                $branch['listed'][$match->id]['count'] += $n;
            } elseif ($row->is_other) {
                $label = $text !== '' ? $text : $row->rank_name;
                $key = self::nameKey($label);
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

    /**
     * Patients of the AFP branches (Army, Navy, Air Force, Marines) against
     * "Others" (Dependent, Retiree, Civilian Employee, Civilian, Other), by
     * the branch's is_afp flag, counted on each patient's latest visit.
     *
     * @return array<string, array{afp: int, others: int}> category (or "All") => split
     */
    private function afpCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        return $this->both(function (bool $overall) use ($from, $to, $categories): array {
            $counts = [];

            $rows = $this->latestVisits($from, $to, $categories, $overall)
                ->join('branches', 'branches.id', '=', 'visits.branch_id')
                ->groupBy(...$this->groups($overall, 'branches.is_afp'))
                ->selectRaw($this->bucket($overall).' AS category, branches.is_afp AS afp, COUNT(*) AS n')
                ->get();

            foreach ($rows as $row) {
                $counts[$row->category] ??= ['afp' => 0, 'others' => 0];
                $counts[$row->category][$row->afp ? 'afp' : 'others'] += (int) $row->n;
            }

            return $counts;
        });
    }

    /**
     * New against returning patients: of the patients counted in the period,
     * those with no earlier visit (before the period, not in Trash) are new
     * and the rest were seen before. For a category, "earlier" means an
     * earlier visit in that category; for the Summary, in any category.
     *
     * @return array<string, array{new: int, returning: int}> category (or "All") => split
     */
    private function returningCounts(CarbonInterface $from, CarbonInterface $to, array $categories): array
    {
        return $this->both(function (bool $overall) use ($from, $to, $categories): array {
            $counts = [];

            $rows = $this->latestVisits($from, $to, $categories, $overall)
                ->selectRaw(
                    $this->bucket($overall).' AS category, COUNT(*) AS patients, '
                    .'SUM(EXISTS (SELECT 1 FROM visits AS earlier WHERE earlier.patient_id = visits.patient_id '
                    .($overall ? '' : 'AND earlier.category = visits.category ')
                    .'AND earlier.deleted_at IS NULL AND earlier.visited_at < ?)) AS returning_patients',
                    [$from],
                )
                ->groupBy(...$this->groups($overall))
                ->get();

            foreach ($rows as $row) {
                $returning = (int) $row->returning_patients;
                $counts[$row->category] = ['new' => (int) $row->patients - $returning, 'returning' => $returning];
            }

            return $counts;
        });
    }

    /**
     * The daily census: visits in each category, one row for each day, or for
     * each month when the period is longer than DAILY_MAX_DAYS days. Days and
     * months with no visits are included.
     *
     * @return array{by: string, rows: list<array{label: string, counts: array<string, int>}>}
     */
    private function daily(CarbonImmutable $from, CarbonImmutable $to, array $categories): array
    {
        $byDay = (int) round($from->diffInDays($to, true)) <= self::DAILY_MAX_DAYS;

        // Two fixed queries (no text is put into the SQL), each with the same
        // expression in SELECT and GROUP BY.
        $query = $this->visitsInPeriod($from, $to, $categories);

        $found = $byDay
            ? $query->groupByRaw("DATE_FORMAT(visits.visited_at, '%Y-%m-%d'), visits.category")
                ->selectRaw("DATE_FORMAT(visits.visited_at, '%Y-%m-%d') AS bucket, visits.category AS category, COUNT(*) AS n")
                ->get()
            : $query->groupByRaw("DATE_FORMAT(visits.visited_at, '%Y-%m'), visits.category")
                ->selectRaw("DATE_FORMAT(visits.visited_at, '%Y-%m') AS bucket, visits.category AS category, COUNT(*) AS n")
                ->get();

        $counts = [];

        foreach ($found as $row) {
            $counts[$row->bucket][$row->category] = (int) $row->n;
        }

        $rows = [];
        $last = $to->subSecond();

        for ($at = $byDay ? $from->startOfDay() : $from->startOfMonth(); $at <= $last; $at = $byDay ? $at->addDay() : $at->addMonth()) {
            $key = $at->format($byDay ? 'Y-m-d' : 'Y-m');

            $rows[] = [
                'label' => $at->format($byDay ? 'd-M-Y (D)' : 'F Y'),
                'counts' => array_combine($categories, array_map(fn (string $name): int => $counts[$key][$name] ?? 0, $categories)),
            ];
        }

        return ['by' => $byDay ? 'day' : 'month', 'rows' => $rows];
    }

    /**
     * Patients and visits of the period of the same length just before this
     * one, counted the same way as the grand total. A whole number of months
     * (a month, a quarter, a year) is compared with the same number of months
     * before; any other range with the same number of days before. Null when
     * that period starts before EARLIEST_DATA.
     *
     * @return array{label: string, patients: int, visits: int}|null
     */
    private function previous(CarbonImmutable $from, CarbonImmutable $to, array $categories): ?array
    {
        $wholeMonths = $from->day === 1 && $to->day === 1 && $from->format('H:i:s') === '00:00:00' && $to->format('H:i:s') === '00:00:00';
        $months = ($to->year - $from->year) * 12 + $to->month - $from->month;

        if ($wholeMonths && $months >= 1) {
            $start = $from->subMonths($months);
            $label = match (true) {
                $months === 1 => $start->format('F Y'),
                $months === 3 && ($start->month - 1) % 3 === 0 => sprintf('Q%d %d', intdiv($start->month - 1, 3) + 1, $start->year),
                $months === 12 && $start->month === 1 => (string) $start->year,
                default => $start->format('d-M-Y').' to '.$from->subDay()->format('d-M-Y'),
            };
        } else {
            $start = $from->subDays((int) round($from->diffInDays($to, true)));
            $label = $start->format('d-M-Y').' to '.$from->subDay()->format('d-M-Y');
        }

        if ($start->lessThan(CarbonImmutable::parse(self::EARLIEST_DATA, $from->timezone))) {
            return null;
        }

        return ['label' => $label, ...$this->grandTotals($start, $from, $categories)];
    }
}
