<?php

use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Services\ReportBuilder;
use App\Services\TableStatistics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Step 7d-3: ReportBuilder finds each patient's latest visit with a window
 * function (ROW_NUMBER, two queries). Before, it used one correlated
 * "no later visit exists" query for every table. This test keeps the old
 * method as a reference and checks that both give the SAME numbers for every
 * month, every quarter and the year of a seeded year of data: patients,
 * visits, sex, age ranges, branch and rank, and new against returning, for
 * each category and for all together.
 *
 * The data is made to be awkward: patients seen in two or three categories,
 * two visits of one patient at the very same minute (the higher id wins),
 * visits in Trash, visits on the first and the last minute of a month, and
 * ages that fit no age range.
 */

const EQ_YEAR = 2026;

beforeEach(function () {
    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $this->branches = collect(['Army', 'Navy', 'Air Force', 'Marines', 'Others'])->map(
        fn (string $name, int $order): Branch => Branch::factory()->create(['name' => $name, 'sort_order' => $order + 1, 'is_afp' => $name !== 'Others']),
    );

    $this->ranks = collect();

    foreach ($this->branches as $branch) {
        foreach (['A', 'B', 'C'] as $order => $name) {
            $this->ranks->push(Rank::factory()->for($branch)->create(['name' => "{$branch->name} {$name}", 'sort_order' => $order + 1]));
        }
    }

    $this->diagnoses = Diagnosis::factory()->count(4)->create();

    // Ages above 60 fit no range, so the "No bracket" line is exercised too.
    AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    AgeBracket::create(['min_age' => 18, 'max_age' => 35]);
    AgeBracket::create(['min_age' => 36, 'max_age' => 59]);
});

/** A year of awkward visits, bulk inserted. */
function eqSeedYear(object $test): void
{
    mt_srand(7);

    $patients = [];

    for ($i = 1; $i <= 260; $i++) {
        $patients[] = ['last_name' => "Eq{$i}", 'first_name' => 'P', 'sex' => mt_rand(0, 1) ? 'Male' : 'Female', 'birthdate' => '1990-01-01', 'created_at' => now(), 'updated_at' => now()];
    }

    DB::table('patients')->insert($patients);
    $patientIds = DB::table('patients')->pluck('id')->all();
    $ranks = $test->ranks;
    $rows = [];

    $visit = function (int $patient, string $when, string $category, ?string $deleted = null) use ($test, $ranks): array {
        $rank = $ranks[mt_rand(0, $ranks->count() - 1)];

        return [
            'patient_id' => $patient,
            'visited_at' => $when,
            'age' => [4, 17, 18, 30, 35, 36, 59, 75][mt_rand(0, 7)],
            'branch_id' => $rank->branch_id,
            'rank_id' => $rank->id,
            'diagnosis_id' => $test->diagnoses[mt_rand(0, 3)]->id,
            'category' => $category,
            'encoded_by' => $test->encoder->id,
            'deleted_at' => $deleted,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    };

    for ($day = 0; $day < 365; $day++) {
        $date = Carbon::create(EQ_YEAR, 1, 1)->addDays($day)->format('Y-m-d');

        foreach (['ER' => 9, 'OPD' => 3, 'Admission' => 1] as $category => $count) {
            for ($k = 0; $k < $count; $k++) {
                $when = $date.sprintf(' %02d:%02d:00', mt_rand(0, 23), mt_rand(0, 59));
                $patient = $patientIds[mt_rand(0, 119)]; // a smaller group, so patients come back
                $rows[] = $visit($patient, $when, $category, mt_rand(1, 30) === 1 ? $date.' 23:59:59' : null);

                // The same patient again at the very same minute: the higher id must win.
                if (mt_rand(1, 25) === 1) {
                    $rows[] = $visit($patient, $when, $category);
                }

                // The same patient in another category the same day.
                if (mt_rand(1, 20) === 1) {
                    $rows[] = $visit($patient, $when, $category === 'ER' ? 'OPD' : 'ER');
                }
            }
        }
    }

    // The edges of every month: the very first and the very last minute.
    for ($month = 1; $month <= 12; $month++) {
        $first = Carbon::create(EQ_YEAR, $month, 1, 0, 0, 0);
        $last = $first->copy()->endOfMonth()->startOfMinute();
        $rows[] = $visit($patientIds[200 + $month], $first->format('Y-m-d H:i:s'), 'ER');
        $rows[] = $visit($patientIds[200 + $month], $last->format('Y-m-d H:i:s'), 'OPD');
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('visits')->insert($chunk);
    }
}

/**
 * The OLD method: every visit that has no later visit of the same patient (in the same category, or in any) in
 * the period, with the later = a later time, or the same time and a higher id.
 *
 * @return list<object>
 */
function eqOldLatest(string $from, string $to, bool $overall): array
{
    $sameCategory = $overall ? '' : 'AND later.category = visits.category ';
    $earlierCategory = $overall ? '' : 'AND earlier.category = visits.category ';

    return DB::select(
        'SELECT visits.category AS category, patients.sex AS sex, visits.age AS age, branches.name AS branch, ranks.name AS rank_name, '
        .'(SELECT COUNT(*) FROM visits AS earlier WHERE earlier.patient_id = visits.patient_id '.$earlierCategory.'AND earlier.deleted_at IS NULL AND earlier.visited_at < ?) > 0 AS is_returning '
        .'FROM visits '
        .'INNER JOIN patients ON patients.id = visits.patient_id '
        .'INNER JOIN branches ON branches.id = visits.branch_id '
        .'INNER JOIN ranks ON ranks.id = visits.rank_id '
        .'WHERE visits.deleted_at IS NULL AND visits.visited_at >= ? AND visits.visited_at < ? '
        .'AND NOT EXISTS (SELECT 1 FROM visits AS later WHERE later.patient_id = visits.patient_id '.$sameCategory
        .'AND later.deleted_at IS NULL AND later.visited_at >= ? AND later.visited_at < ? '
        .'AND (later.visited_at > visits.visited_at OR (later.visited_at = visits.visited_at AND later.id > visits.id)))',
        [$from, $from, $to, $from, $to],
    );
}

/**
 * What a report must say, worked out from the old method's rows for one group (a category, or all together).
 *
 * @param  list<object>  $rows
 * @return array{patients: int, sex: array<string, int>, age: array<string, array{0: int, 1: int}>, branches: array<string, int>, ranks: array<string, int>, returning: array{new: int, returning: int}}
 */
function eqExpected(array $rows, $brackets): array
{
    $sex = ['Male' => 0, 'Female' => 0];
    $age = [];
    $branches = [];
    $ranks = [];
    $returning = ['new' => 0, 'returning' => 0];

    foreach ($brackets as $bracket) {
        $age[$bracket->label] = [0, 0];
    }

    foreach ($rows as $row) {
        $sex[$row->sex]++;

        $label = 'No bracket';

        foreach ($brackets as $bracket) {
            if ($bracket->contains((int) $row->age)) {
                $label = $bracket->label;
                break;
            }
        }

        $age[$label] ??= [0, 0];
        $age[$label][$row->sex === 'Male' ? 0 : 1]++;
        $branches[$row->branch] = ($branches[$row->branch] ?? 0) + 1;
        $ranks[$row->branch.'/'.$row->rank_name] = ($ranks[$row->branch.'/'.$row->rank_name] ?? 0) + 1;
        $returning[$row->is_returning ? 'returning' : 'new']++;
    }

    return ['patients' => count($rows), 'sex' => $sex, 'age' => $age, 'branches' => $branches, 'ranks' => $ranks, 'returning' => $returning];
}

/**
 * What the report holds for one group, in the same shape as eqExpected().
 *
 * @param  array<string, mixed>  $group  one category of the report, or its summary
 */
function eqActual(array $group, int $patients): array
{
    $sex = array_column($group['sex'], 'count', 'label');
    $age = [];

    foreach ($group['age_sex'] as $line) {
        $age[$line['label']] = [$line['male'], $line['female']];
    }

    $branches = [];
    $ranks = [];

    foreach ($group['branches'] as $branch) {
        $branches[$branch['name']] = $branch['total'];

        foreach ($branch['ranks'] as $rank) {
            $ranks[$branch['name'].'/'.$rank['label']] = $rank['count'];
        }
    }

    return ['patients' => $patients, 'sex' => $sex, 'age' => $age, 'branches' => $branches, 'ranks' => $ranks, 'returning' => $group['returning']];
}

/** Every month, every quarter and the year: [label, from, to]. */
function eqPeriods(): array
{
    $periods = [];

    for ($month = 1; $month <= 12; $month++) {
        $from = Carbon::create(EQ_YEAR, $month, 1);
        $periods[] = [$from->format('F Y'), $from, $from->copy()->addMonth()];
    }

    for ($quarter = 0; $quarter < 4; $quarter++) {
        $from = Carbon::create(EQ_YEAR, $quarter * 3 + 1, 1);
        $periods[] = ['Q'.($quarter + 1), $from, $from->copy()->addMonths(3)];
    }

    $periods[] = ['Year', Carbon::create(EQ_YEAR, 1, 1), Carbon::create(EQ_YEAR + 1, 1, 1)];

    return $periods;
}

it('gives the same numbers as the old method for every month, quarter and the year', function () {
    eqSeedYear($this);

    $brackets = AgeBracket::query()->ordered()->get();
    $builder = app(ReportBuilder::class);
    $checked = 0;

    foreach (eqPeriods() as [$label, $from, $to]) {
        $report = $builder->build($from->toImmutable(), $to->toImmutable());
        $f = $from->format('Y-m-d H:i:s');
        $t = $to->format('Y-m-d H:i:s');

        // Each category: patients counted once in that category.
        $perCategory = collect(eqOldLatest($f, $t, overall: false))->groupBy('category');

        foreach (['OPD', 'ER', 'Admission'] as $category) {
            $rows = $perCategory->get($category, collect())->all();
            $expected = eqExpected($rows, $brackets);
            $group = $report['categories'][$category];

            $visits = (int) DB::table('visits')->whereNull('deleted_at')->where('category', $category)->where('visited_at', '>=', $f)->where('visited_at', '<', $t)->count();

            // The period and category are in both sides, so a failure says where it differs.
            expect([$label, $category, eqActual($group, $group['patients']), $group['visits']])
                ->toEqual([$label, $category, $expected, $visits]);
            $checked++;
        }

        // All categories together: each patient once, their latest visit in any category.
        $all = eqOldLatest($f, $t, overall: true);
        $expected = eqExpected($all, $brackets);

        $visits = (int) DB::table('visits')->whereNull('deleted_at')->where('visited_at', '>=', $f)->where('visited_at', '<', $t)->count();

        expect([$label, 'all', eqActual($report['summary'], $report['totals']['patients']), $report['totals']['visits']])
            ->toEqual([$label, 'all', $expected, $visits]);
        $checked++;
    }

    // 12 months + 4 quarters + the year, each with three categories and the total.
    expect($checked)->toBe(17 * 4);
});

it('breaks a tie of two visits at the same minute by the higher id, like the old method', function () {
    $patient = Patient::factory()->create(['sex' => 'Female']);
    $army = $this->branches[0];
    $navy = $this->branches[1];

    $first = DB::table('visits')->insertGetId(['patient_id' => $patient->id, 'visited_at' => '2026-03-05 10:00:00', 'age' => 20, 'branch_id' => $army->id, 'rank_id' => $this->ranks[0]->id, 'diagnosis_id' => $this->diagnoses[0]->id, 'category' => 'ER', 'encoded_by' => $this->encoder->id, 'created_at' => now(), 'updated_at' => now()]);
    $second = DB::table('visits')->insertGetId(['patient_id' => $patient->id, 'visited_at' => '2026-03-05 10:00:00', 'age' => 20, 'branch_id' => $navy->id, 'rank_id' => $this->ranks[3]->id, 'diagnosis_id' => $this->diagnoses[0]->id, 'category' => 'ER', 'encoded_by' => $this->encoder->id, 'created_at' => now(), 'updated_at' => now()]);

    $report = app(ReportBuilder::class)->build('2026-03');

    expect($second)->toBeGreaterThan($first)
        ->and($report['categories']['ER']['patients'])->toBe(1)
        ->and(array_column($report['categories']['ER']['branches'], 'total', 'name'))->toBe(['Navy' => 1]);
});

it('runs the latest-visit work in two queries however long the period', function () {
    eqSeedYear($this);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(ReportBuilder::class)->build(Carbon::create(EQ_YEAR, 1, 1)->toImmutable(), Carbon::create(EQ_YEAR + 1, 1, 1)->toImmutable());
    $windowed = count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'ROW_NUMBER')));
    DB::disableQueryLog();

    expect($windowed)->toBe(2);
});

it('leaves a caller that is inside a transaction alone when refreshing the table statistics', function () {
    app(TableStatistics::class)->refresh();

    expect(TableStatistics::TABLES)->toBe(['visits', 'patients']);
});
