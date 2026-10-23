<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Visit;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use App\Services\ReportSections;
use App\Services\ReportSheetResolver;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/*
 * Step 7d-2: the full report content (planning/mvp-scope.md section 4).
 * One small September 2026 dataset, counted by hand below, checked in the
 * builder, on the screen (Inertia props), in the PDF page and in the Excel
 * cells. "Today" is 15-Oct-2026.
 *
 * September 2026 (hand count)
 *   A  Male    ER 3-Sep (Army CPL, Hypertension, 30)   ER 10-Sep (Army SGT, Asthma, 31)   OPD 5-Sep (Army CPL, Hypertension, 30)
 *              and ER 15-Aug (seen before in ER)
 *   B  Female  ER 4-Sep   (Navy ENS, Asthma, 10)
 *   C  Female  OPD 5-Sep  (Others Dependent, Hypertension, 45)   and OPD 20-Aug (seen before in OPD)
 *   D  Male    Admission 6-Sep (Army CPL, Hypertension, 70: no age bracket)
 *   E  Male    ER 4-Sep   (Others, typed rank "Retiree", typed diagnosis "Dengue", 25)
 *
 *   ER: 3 patients (A, B, E), 4 visits.  OPD: 2 patients (A, C), 2 visits.  Admission: 1 patient (D), 1 visit.
 *   All categories: 5 patients, 7 visits.  August: 2 patients (A, C), 2 visits.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $this->army = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'sort_order' => 2]);
    $this->others = Branch::factory()->create(['name' => 'Others', 'is_afp' => false, 'sort_order' => 3]);

    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL', 'sort_order' => 1]);
    $this->sgt = Rank::factory()->for($this->army)->create(['name' => 'SGT', 'sort_order' => 2]);
    $this->ens = Rank::factory()->for($this->navy)->create(['name' => 'ENS', 'sort_order' => 1]);
    $this->dependent = Rank::factory()->for($this->others)->create(['name' => 'Dependent', 'sort_order' => 1]);
    $this->otherRank = Rank::factory()->for($this->others)->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 9]);

    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->asthma = Diagnosis::factory()->create(['name' => 'Asthma']);
    $this->otherDiagnosis = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true]);

    AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);
});

/** @param  array<string, mixed>  $attributes */
function contentVisit(object $test, Patient $patient, string $when, string $category, array $attributes = []): Visit
{
    return Visit::factory()->create([
        'patient_id' => $patient->id,
        'visited_at' => Carbon::parse($when, 'Asia/Manila'),
        'category' => $category,
        'age' => 30,
        'branch_id' => $test->army->id,
        'rank_id' => $test->cpl->id,
        'diagnosis_id' => $test->hypertension->id,
        'encoded_by' => $test->encoder->id,
        ...$attributes,
    ]);
}

/** The September dataset described at the top of this file. */
function seedSeptember(object $test): void
{
    $a = Patient::factory()->create(['sex' => 'Male']);
    $b = Patient::factory()->create(['sex' => 'Female']);
    $c = Patient::factory()->create(['sex' => 'Female']);
    $d = Patient::factory()->create(['sex' => 'Male']);
    $e = Patient::factory()->create(['sex' => 'Male']);

    $test->patientA = $a;

    contentVisit($test, $a, '2026-08-15 09:00', 'ER');
    contentVisit($test, $c, '2026-08-20 09:00', 'OPD', ['branch_id' => $test->others->id, 'rank_id' => $test->dependent->id, 'age' => 45]);

    contentVisit($test, $a, '2026-09-03 09:00', 'ER');
    contentVisit($test, $a, '2026-09-10 09:00', 'ER', ['age' => 31, 'rank_id' => $test->sgt->id, 'diagnosis_id' => $test->asthma->id]);
    contentVisit($test, $a, '2026-09-05 08:00', 'OPD');
    contentVisit($test, $b, '2026-09-04 09:00', 'ER', ['age' => 10, 'branch_id' => $test->navy->id, 'rank_id' => $test->ens->id, 'diagnosis_id' => $test->asthma->id]);
    contentVisit($test, $c, '2026-09-05 09:00', 'OPD', ['age' => 45, 'branch_id' => $test->others->id, 'rank_id' => $test->dependent->id]);
    contentVisit($test, $d, '2026-09-06 09:00', 'Admission', ['age' => 70]);
    contentVisit($test, $e, '2026-09-04 10:00', 'ER', [
        'age' => 25, 'branch_id' => $test->others->id, 'rank_id' => $test->otherRank->id, 'rank_other' => 'Retiree',
        'diagnosis_id' => $test->otherDiagnosis->id, 'diagnosis_other' => 'Dengue',
    ]);
}

/** The cells of the rows of one table of a page. */
function tableCells(array $pages, string $page, string $key): array
{
    $table = collect($pages[$page])->firstWhere('key', $key);

    return array_map(fn (array $row): array => $row['cells'], $table['rows']);
}

function tableOf(array $pages, string $page, string $key): array
{
    return collect($pages[$page])->firstWhere('key', $key);
}

function septemberPages(): array
{
    $report = app(ReportBuilder::class)->build('2026-09');

    return app(ReportSections::class)->pages($report);
}

// --- Summary: hand-counted ---------------------------------------------------------

it('counts the Summary tables of the September dataset by hand', function () {
    seedSeptember($this);
    $pages = septemberPages();

    expect(array_keys($pages))->toBe(['summary', 'OPD', 'ER', 'Admission'])
        ->and(array_column($pages['summary'], 'key'))->toBe(['totals', 'sex', 'age_sex', 'diagnoses', 'branches', 'ranks', 'returning', 'daily', 'comparison'])
        ->and(tableCells($pages, 'summary', 'totals'))->toBe([['OPD', 2, 2], ['ER', 3, 4], ['Admission', 1, 1], ['Total (all categories)', 5, 7]])
        ->and(tableCells($pages, 'summary', 'sex'))->toBe([['OPD', 1, 1, 2], ['ER', 2, 1, 3], ['Admission', 1, 0, 1], ['All categories', 3, 2, 5]])
        ->and(tableCells($pages, 'summary', 'age_sex'))->toBe([['0–17', 0, 1, 1], ['18–59', 2, 1, 3], ['No bracket', 1, 0, 1], ['Total', 3, 2, 5]])
        ->and(tableCells($pages, 'summary', 'diagnoses'))->toBe([['Hypertension', 4, 57.1], ['Asthma', 2, 28.6], ['Dengue', 1, 14.3]])
        ->and(tableOf($pages, 'summary', 'diagnoses')['note'])->toBe('Full list on each category page.')
        ->and(tableCells($pages, 'summary', 'branches'))->toBe([
            ['Army', 2, 40.0], ['Navy', 1, 20.0], ['Others', 2, 40.0],
            ['AFP personnel', 3, 60.0], ['Others (not AFP personnel)', 2, 40.0],
        ])
        ->and(tableCells($pages, 'summary', 'ranks'))->toBe([
            ['Army', 2], ['CPL', 1], ['SGT', 1], ['Navy', 1], ['ENS', 1], ['Others', 2], ['Dependent', 1], ['Retiree', 1],
        ])
        ->and(tableCells($pages, 'summary', 'returning'))->toBe([
            ['New (first visit ever in this period)', 3, 60.0], ['Returning (seen before)', 2, 40.0], ['Total patients', 5, 100.0],
        ]);
});

it('draws the Summary ranks grouped by branch with subtotals, and the AFP split under the branches', function () {
    seedSeptember($this);
    $pages = septemberPages();

    $styles = array_column(tableOf($pages, 'summary', 'ranks')['rows'], 'style');
    $branchStyles = array_column(tableOf($pages, 'summary', 'branches')['rows'], 'style');

    expect($styles)->toBe(['group', 'child', 'child', 'group', 'child', 'group', 'child', 'child'])
        ->and($branchStyles)->toBe(['body', 'body', 'body', 'total', 'total']);
});

it('counts the daily census of September by hand, with the busiest day', function () {
    seedSeptember($this);
    $pages = septemberPages();
    $table = tableOf($pages, 'summary', 'daily');
    $rows = array_column($table['rows'], 'cells');

    expect(array_column($table['columns'], 'label'))->toBe(['Day', 'OPD', 'ER', 'Admission', 'Total visits'])
        ->and($table['title'])->toBe('Daily census (visits)')
        // 30 days, then the total row.
        ->and($rows)->toHaveCount(31)
        ->and($rows[0])->toBe(['01-Sep-2026 (Tue)', 0, 0, 0, 0])
        ->and($rows[2])->toBe(['03-Sep-2026 (Thu)', 0, 1, 0, 1])
        ->and($rows[3])->toBe(['04-Sep-2026 (Fri)', 0, 2, 0, 2])
        ->and($rows[4])->toBe(['05-Sep-2026 (Sat)', 2, 0, 0, 2])
        ->and($rows[5])->toBe(['06-Sep-2026 (Sun)', 0, 0, 1, 1])
        ->and($rows[9])->toBe(['10-Sep-2026 (Thu)', 0, 1, 0, 1])
        ->and($rows[30])->toBe(['Total', 2, 4, 1, 7])
        // Two days have 2 visits; the earlier one is named.
        ->and($table['note'])->toBe('Busiest day: 04-Sep-2026 (Fri), 2 visits.');
});

it('compares with the previous period', function () {
    seedSeptember($this);
    $table = tableOf(septemberPages(), 'summary', 'comparison');

    expect(array_column($table['columns'], 'label'))->toBe(['Measure', 'This period', 'August 2026', 'Change', 'Change %'])
        ->and(array_column($table['rows'], 'cells'))->toBe([
            ['Patients', 5, 2, 3, 150.0],
            ['Visits', 7, 2, 5, 250.0],
        ]);
});

it('names the previous period of a quarter and a year, and skips it before January 2026', function () {
    $builder = app(ReportBuilder::class);
    $at = fn (string $from, string $to) => $builder->build(Carbon::parse($from, 'Asia/Manila')->toImmutable(), Carbon::parse($to, 'Asia/Manila')->toImmutable());

    expect($at('2026-07-01', '2026-10-01')['previous']['label'])->toBe('Q2 2026')
        ->and($at('2026-04-01', '2026-07-01')['previous']['label'])->toBe('Q1 2026')
        ->and($at('2026-01-01', '2026-04-01')['previous'])->toBeNull()
        ->and($at('2026-01-01', '2027-01-01')['previous'])->toBeNull()
        ->and($builder->build('2026-01')['previous'])->toBeNull()
        ->and($builder->build('2026-02')['previous']['label'])->toBe('January 2026')
        // 10 days (1 to 10 October): the 10 days before are 21 to 30 September.
        ->and($at('2026-10-01', '2026-10-11')['previous']['label'])->toBe('21-Sep-2026 to 30-Sep-2026')
        ->and($at('2026-01-05', '2026-01-15')['previous'])->toBeNull();

    // Without a previous period there is no comparison table.
    $pages = app(ReportSections::class)->pages($builder->build('2026-01'));

    expect(array_column($pages['summary'], 'key'))->not->toContain('comparison');
});

it('shows the daily census by month for a quarter, a year and a range longer than 31 days', function () {
    seedSeptember($this);
    $builder = app(ReportBuilder::class);

    $quarter = $builder->build(Carbon::parse('2026-07-01', 'Asia/Manila')->toImmutable(), Carbon::parse('2026-10-01', 'Asia/Manila')->toImmutable());
    $table = tableOf(app(ReportSections::class)->pages($quarter), 'summary', 'daily');

    expect($quarter['daily']['by'])->toBe('month')
        ->and($table['title'])->toBe('Monthly census (visits)')
        ->and(array_column($table['rows'], 'cells'))->toBe([
            ['July 2026', 0, 0, 0, 0],
            ['August 2026', 1, 1, 0, 2],
            ['September 2026', 2, 4, 1, 7],
            ['Total', 3, 5, 1, 9],
        ])
        ->and($table['note'])->toBe('Busiest month: September 2026, 7 visits.');

    // 31 days is still by day; 32 is by month.
    expect($builder->build(Carbon::parse('2026-09-01', 'Asia/Manila')->toImmutable(), Carbon::parse('2026-10-02', 'Asia/Manila')->toImmutable())['daily']['by'])->toBe('day')
        ->and($builder->build(Carbon::parse('2026-09-01', 'Asia/Manila')->toImmutable(), Carbon::parse('2026-10-03', 'Asia/Manila')->toImmutable())['daily']['by'])->toBe('month');
});

// --- Category tabs: hand-counted -----------------------------------------------------

it('counts the ER tables of the September dataset by hand', function () {
    seedSeptember($this);
    $pages = septemberPages();

    expect(array_column($pages['ER'], 'key'))->toBe(['totals', 'sex', 'age_sex', 'diagnoses', 'branch_rank', 'returning', 'daily'])
        ->and(tableCells($pages, 'ER', 'totals'))->toBe([['Patients', 3], ['Visits', 4]])
        ->and(tableCells($pages, 'ER', 'sex'))->toBe([['Male', 2], ['Female', 1], ['Total', 3]])
        ->and(tableCells($pages, 'ER', 'age_sex'))->toBe([['0–17', 0, 1, 1], ['18–59', 2, 0, 2], ['Total', 2, 1, 3]])
        // Highest first, ties by name; every diagnosis, with its share of the 4 visits.
        ->and(tableCells($pages, 'ER', 'diagnoses'))->toBe([['Asthma', 2, 50.0], ['Dengue', 1, 25.0], ['Hypertension', 1, 25.0], ['Total', 4, 100.0]])
        ->and(tableCells($pages, 'ER', 'branch_rank'))->toBe([
            ['Army', 1], ['SGT', 1], ['Navy', 1], ['ENS', 1], ['Others', 1], ['Retiree', 1],
            ['AFP personnel', 2], ['Others (not AFP personnel)', 1],
        ])
        ->and(tableCells($pages, 'ER', 'returning'))->toBe([
            ['New (first visit ever in this period)', 2, 66.7], ['Returning (seen before)', 1, 33.3], ['Total patients', 3, 100.0],
        ]);

    $daily = tableOf($pages, 'ER', 'daily');

    expect(array_column($daily['columns'], 'label'))->toBe(['Day', 'Visits'])
        ->and(array_column($daily['rows'], 'cells')[3])->toBe(['04-Sep-2026 (Fri)', 2])
        ->and(array_column($daily['rows'], 'cells')[30])->toBe(['Total', 4])
        ->and($daily['note'])->toBe('Busiest day: 04-Sep-2026 (Fri), 2 visits.');
});

it('counts OPD and Admission, and a returning patient is returning only in the category seen before', function () {
    seedSeptember($this);
    $pages = septemberPages();

    // A was seen in ER in August, not in OPD: new in OPD. C was seen in OPD in August.
    expect(tableCells($pages, 'OPD', 'returning'))->toBe([
        ['New (first visit ever in this period)', 1, 50.0], ['Returning (seen before)', 1, 50.0], ['Total patients', 2, 100.0],
    ])
        ->and(tableCells($pages, 'OPD', 'age_sex'))->toBe([['0–17', 0, 0, 0], ['18–59', 1, 1, 2], ['Total', 1, 1, 2]])
        ->and(tableCells($pages, 'OPD', 'branch_rank'))->toBe([
            ['Army', 1], ['CPL', 1], ['Others', 1], ['Dependent', 1], ['AFP personnel', 1], ['Others (not AFP personnel)', 1],
        ])
        ->and(tableCells($pages, 'Admission', 'returning')[0])->toBe(['New (first visit ever in this period)', 1, 100.0])
        ->and(tableCells($pages, 'Admission', 'age_sex'))->toBe([['0–17', 0, 0, 0], ['18–59', 0, 0, 0], ['No bracket', 1, 0, 1], ['Total', 1, 0, 1]])
        ->and(tableCells($pages, 'Admission', 'diagnoses'))->toBe([['Hypertension', 1, 100.0], ['Total', 1, 100.0]]);
});

it('keeps the empty category tables at zero', function () {
    Patient::factory()->create();
    $pages = septemberPages();

    expect(tableCells($pages, 'ER', 'totals'))->toBe([['Patients', 0], ['Visits', 0]])
        ->and(tableCells($pages, 'ER', 'sex'))->toBe([['Male', 0], ['Female', 0], ['Total', 0]])
        ->and(tableCells($pages, 'ER', 'age_sex'))->toBe([['0–17', 0, 0, 0], ['18–59', 0, 0, 0], ['Total', 0, 0, 0]])
        ->and(tableCells($pages, 'ER', 'diagnoses'))->toBe([['Total', 0, 0.0]])
        ->and(tableCells($pages, 'ER', 'branch_rank'))->toBe([['AFP personnel', 0], ['Others (not AFP personnel)', 0]])
        ->and(tableCells($pages, 'ER', 'returning')[2])->toBe(['Total patients', 0, 0.0])
        ->and(tableOf($pages, 'ER', 'daily')['note'])->toBe('No visits in this period.')
        ->and(tableCells($pages, 'summary', 'comparison'))->toBe([['Patients', 0, 0, 0, null], ['Visits', 0, 0, 0, null]]);
});

// --- The numbers add up --------------------------------------------------------------

it('adds up: sex and age by sex total the patients, daily totals the visits, new plus returning the patients', function () {
    seedSeptember($this);
    $report = app(ReportBuilder::class)->build('2026-09');

    $groups = ['summary' => $report['summary'], ...$report['categories']];
    $patients = ['summary' => $report['totals']['patients'], ...array_map(fn ($c) => $c['patients'], $report['categories'])];

    foreach ($groups as $name => $group) {
        expect(array_sum(array_column($group['sex'], 'count')))->toBe($patients[$name], "sex of {$name}")
            ->and(array_sum(array_map(fn ($l) => $l['male'] + $l['female'], $group['age_sex'])))->toBe($patients[$name], "age by sex of {$name}")
            ->and(array_sum(array_column($group['age'], 'count')))->toBe($patients[$name], "age of {$name}")
            ->and($group['returning']['new'] + $group['returning']['returning'])->toBe($patients[$name], "new and returning of {$name}")
            ->and(array_sum(array_column($group['branches'], 'total')))->toBe($patients[$name], "branches of {$name}")
            ->and($group['afp']['afp'] + $group['afp']['others'])->toBe($patients[$name], "AFP split of {$name}");
    }

    $dailyByCategory = array_fill_keys(['OPD', 'ER', 'Admission'], 0);

    foreach ($report['daily']['rows'] as $row) {
        foreach ($row['counts'] as $name => $n) {
            $dailyByCategory[$name] += $n;
        }
    }

    expect($dailyByCategory)->toBe(['OPD' => 2, 'ER' => 4, 'Admission' => 1])
        ->and(array_sum($dailyByCategory))->toBe($report['totals']['visits'])
        ->and(array_sum(array_column($report['categories']['ER']['diagnoses'], 'count')))->toBe($report['categories']['ER']['visits']);
});

it('counts a patient once on the Summary even when seen in two categories', function () {
    seedSeptember($this);
    $report = app(ReportBuilder::class)->build('2026-09');

    // A is in ER and OPD: 6 category patients, 5 people.
    expect(array_sum(array_column($report['categories'], 'patients')))->toBe(6)
        ->and($report['totals']['patients'])->toBe(5)
        ->and(array_sum(array_column($report['summary']['sex'], 'count')))->toBe(5)
        // A's latest visit in any category is ER 10-Sep: SGT, not CPL.
        ->and(collect($report['summary']['branches'])->firstWhere('name', 'Army')['ranks'])->toBe([['label' => 'CPL', 'count' => 1], ['label' => 'SGT', 'count' => 1]]);
});

it('leaves out visits in Trash everywhere', function () {
    seedSeptember($this);
    Visit::query()->where('category', 'Admission')->first()->moveToTrash($this->admin, 'Duplicate entry');
    $report = app(ReportBuilder::class)->build('2026-09');

    expect($report['totals'])->toBe(['patients' => 4, 'visits' => 6])
        ->and($report['summary']['returning'])->toBe(['new' => 2, 'returning' => 2])
        ->and(array_sum(array_map(fn ($l) => $l['male'] + $l['female'], $report['summary']['age_sex'])))->toBe(4)
        ->and(array_sum(array_map(fn ($r) => array_sum($r['counts']), $report['daily']['rows'])))->toBe(6);
});

it('caps the Summary diagnoses at 20, highest first, and lists them all on the category page', function () {
    $patient = Patient::factory()->create();

    // Diagnosis 01 has 1 visit, 02 has 2, ... 25 has 25.
    foreach (range(1, 25) as $n) {
        $diagnosis = Diagnosis::factory()->create(['name' => sprintf('Diagnosis %02d', $n)]);

        foreach (range(1, $n) as $i) {
            contentVisit($this, $patient, '2026-09-05 09:00', 'ER', ['diagnosis_id' => $diagnosis->id]);
        }
    }

    $report = app(ReportBuilder::class)->build('2026-09');
    $pages = app(ReportSections::class)->pages($report);
    $top = tableCells($pages, 'summary', 'diagnoses');
    $counts = array_column($top, 1);

    expect($top)->toHaveCount(20)
        ->and($top[0][0])->toBe('Diagnosis 25')
        ->and($top[19][0])->toBe('Diagnosis 06')
        ->and($counts)->toBe(range(25, 6))
        // 325 visits in all; 25 is 7.7% of them. No "Others" line and no total.
        ->and($top[0][2])->toBe(7.7)
        ->and(collect($top)->pluck(0))->not->toContain('Others')
        // The ER page keeps every diagnosis (25), plus the Total row.
        ->and(tableCells($pages, 'ER', 'diagnoses'))->toHaveCount(26);
});

it('puts diagnoses with the same count in name order', function () {
    $patient = Patient::factory()->create();

    foreach (['Zeta', 'Alpha', 'Mike'] as $name) {
        contentVisit($this, $patient, '2026-09-05 09:00', 'ER', ['diagnosis_id' => Diagnosis::factory()->create(['name' => $name])->id]);
    }

    $labels = array_column(tableCells(septemberPages(), 'summary', 'diagnoses'), 0);

    expect($labels)->toBe(['Alpha', 'Mike', 'Zeta']);
});

// --- Saved copies ----------------------------------------------------------------------

it('saves every section in a version 4 snapshot when a month is closed', function () {
    seedSeptember($this);
    app(MonthService::class)->close('2026-09', $this->admin);

    $data = ReportSnapshot::where('period', '2026-09')->sole()->data;

    expect(ReportBuilder::VERSION)->toBe(4)
        ->and($data['version'])->toBe(4)
        ->and($data)->toHaveKeys(['summary', 'daily', 'previous'])
        ->and($data['summary'])->toHaveKeys(['sex', 'age', 'age_sex', 'diagnoses', 'branches', 'afp', 'returning'])
        ->and($data['categories']['ER'])->toHaveKeys(['sex', 'age', 'age_sex', 'diagnoses', 'branches', 'afp', 'returning'])
        ->and($data['previous'])->toMatchArray(['label' => 'August 2026', 'patients' => 2, 'visits' => 2])
        ->and($data['daily']['rows'])->toHaveCount(30);
});

it('keeps a closed month the same after a patient is corrected, and a live month changes', function () {
    seedSeptember($this);
    app(MonthService::class)->close('2026-09', $this->admin);

    $before = app(ReportSheetResolver::class)->resolve(['type' => 'monthly', 'month' => '2026-09']);

    // A correction made afterwards: A is a Female, and a visit's age and diagnosis change.
    $this->patientA->update(['sex' => 'Female']);
    Visit::query()->where('category', 'Admission')->first()->update(['age' => 20, 'diagnosis_id' => $this->asthma->id]);

    $after = app(ReportSheetResolver::class)->resolve(['type' => 'monthly', 'month' => '2026-09']);
    $live = app(ReportBuilder::class)->build('2026-09');

    expect($after['snapshot'])->not->toBeNull()
        ->and($after['report'])->toBe($before['report'])
        ->and($after['sections'])->toBe($before['sections'])
        ->and($after['sheet']['signature_note'])->toBeNull()
        // Counted live, the corrected data would show differently.
        ->and($live['summary']['sex'])->not->toBe($before['report']['summary']['sex'])
        ->and($live['summary']['age_sex'])->not->toBe($before['report']['summary']['age_sex']);
});

it('counts live and says there is no saved copy when a closed month holds a copy of an older version', function () {
    seedSeptember($this);
    MonthClosure::create(['period' => '2026-09', 'is_closed' => true, 'closed_by' => $this->admin->id, 'closed_at' => now()]);
    ReportSnapshot::create([
        'period' => '2026-09',
        'data' => ['version' => 3, 'categories' => ['ER' => ['patients' => 99, 'visits' => 99]], 'totals' => ['patients' => 99, 'visits' => 99]],
        'created_by' => $this->admin->id,
    ]);

    $props = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09&category=summary')->assertOk()->inertiaProps();

    expect($props['snapshot'])->toBeNull()
        ->and($props['sheet']['signature_note'])->toBe('Not final – no saved copy')
        ->and($props['report']['totals'])->toBe(['patients' => 5, 'visits' => 7])
        ->and($props['report']['version'])->toBe(4)
        ->and(array_column($props['sections']['summary'], 'key'))->toContain('daily', 'comparison');
});

// --- The same sections and numbers on screen, in Print, in the PDF and in Excel ---------

/** @return array<string, list<array<string, mixed>>> */
function screenSections(object $test, string $query = 'type=monthly&month=2026-09'): array
{
    return $test->actingAs($test->admin)->get('/reports?'.$query)->assertOk()->inertiaProps('sections');
}

/** The cells of a sheet as the Excel file holds them: the rows after the title block, up to the signatures, without blank rows. */
function sheetTableRows(Spreadsheet $book, string $sheet): array
{
    $rows = [];
    $inside = false;

    foreach ($book->getSheetByNameOrThrow($sheet)->toArray(null, false, false) as $cells) {
        while ($cells !== [] && in_array(end($cells), [null, ''], true)) {
            array_pop($cells);
        }

        if (isset($cells[0]) && str_starts_with((string) $cells[0], 'Generated ')) {
            $inside = true;

            continue;
        }

        if (isset($cells[0]) && str_starts_with((string) $cells[0], 'Prepared by')) {
            break;
        }

        if ($inside && $cells !== []) {
            $rows[] = $cells;
        }
    }

    return $rows;
}

/** What the Excel rows of a page should be: the tables of that page, in order. */
function expectedSheetRows(array $tables): array
{
    $rows = [];

    foreach ($tables as $table) {
        $rows[] = [$table['title']];
        $rows[] = array_column($table['columns'], 'label');

        foreach ($table['rows'] as $row) {
            $cells = array_map(fn ($cell) => $cell ?? '–', $row['cells']);

            if ($row['style'] === 'child') {
                $cells[0] = '    '.$cells[0];
            }

            $rows[] = $cells;
        }

        if ($table['note'] !== null) {
            $rows[] = [$table['note']];
        }
    }

    return $rows;
}

function excelBookOf(TestResponse $response): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());

    try {
        return IOFactory::load($path);
    } finally {
        unlink($path);
    }
}

it('gives the screen the tables of every page, equal to the hand count', function () {
    seedSeptember($this);

    $screen = screenSections($this);

    // The screen gets the same tables as JSON, where 40.0 is written 40.
    expect($screen)->toEqual(septemberPages())
        ->and(tableCells($screen, 'summary', 'totals')[3])->toBe(['Total (all categories)', 5, 7])
        ->and(tableCells($screen, 'ER', 'returning')[0])->toBe(['New (first visit ever in this period)', 2, 66.7]);
});

it('gives Print the same tables as the screen', function () {
    seedSeptember($this);

    $print = $this->actingAs($this->admin)->get('/reports/print?type=monthly&month=2026-09&scope=all')->assertOk()->inertiaProps();

    expect($print['sections'])->toEqual(screenSections($this))
        ->and($print['pages'])->toBe(['summary', 'OPD', 'ER', 'Admission']);
});

it('puts every table, row and figure of every page in the PDF page', function () {
    seedSeptember($this);
    $data = app(ReportSheetResolver::class)->resolve(['type' => 'monthly', 'month' => '2026-09']);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now(), 'pages' => ['summary', 'OPD', 'ER', 'Admission']])->render();

    foreach ($data['sections'] as $page => $tables) {
        foreach ($tables as $table) {
            if ($table['key'] === ReportSections::TOTALS) {
                continue;
            }

            expect($html)->toContain(e($table['title']));

            foreach ($table['rows'] as $row) {
                foreach ($row['cells'] as $index => $cell) {
                    expect($html)->toContain('>'.e(ReportSections::format($cell, $table['columns'][$index]['type'])).'</td>');
                }
            }

            if ($table['note'] !== null) {
                expect($html)->toContain(e($table['note']));
            }
        }
    }

    // Spot checks against the hand count.
    expect($html)->toContain('57.1%')
        ->toContain('04-Sep-2026 (Fri)')
        ->toContain('Busiest day: 04-Sep-2026 (Fri), 2 visits.')
        ->toContain('Full list on each category page.')
        ->toContain('+150.0%')
        ->toContain('Top 20 diagnoses (per visit, all categories)');

    // Each table is kept together on a page.
    expect($html)->toContain('.sec { page-break-inside: avoid; }');

    $this->actingAs($this->admin)->get('/reports/export/pdf?type=monthly&month=2026-09&scope=all')->assertOk();
});

it('shows the Summary page first, then OPD, ER and Admission, in the PDF', function () {
    seedSeptember($this);
    $data = app(ReportSheetResolver::class)->resolve(['type' => 'monthly', 'month' => '2026-09']);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now(), 'pages' => ['summary', 'OPD', 'ER', 'Admission']])->render();

    $positions = array_map(fn (string $page) => strpos($html, 'September 2026· '.$page), ['Summary', 'OPD', 'ER', 'Admission']);

    expect($positions)->each->not->toBeFalse()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('writes the same tables and figures in the Excel sheets as on the screen', function () {
    seedSeptember($this);

    $book = excelBookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk());
    $screen = screenSections($this);

    expect($book->getSheetNames())->toBe(['Summary', 'OPD', 'ER', 'Admission']);

    foreach (['Summary' => 'summary', 'OPD' => 'OPD', 'ER' => 'ER', 'Admission' => 'Admission'] as $sheet => $page) {
        expect(sheetTableRows($book, $sheet))->toEqual(expectedSheetRows($screen[$page]), "sheet {$sheet}");
    }
});

it('writes the new tables in the 7d style in Excel: numbers, percent format, dashes, landscape only when wide', function () {
    seedSeptember($this);

    $book = excelBookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk());
    $summary = $book->getSheetByNameOrThrow('Summary');
    $rows = $summary->toArray(null, false, false);
    $row = fn (string $label) => collect($rows)->search(fn ($r) => ($r[0] ?? null) === $label) + 1;

    $hypertension = $row('Hypertension');
    $headerRow = $row('Top 20 diagnoses (per visit, all categories)') + 1;

    expect($summary->getCell("B{$hypertension}")->getDataType())->toBe('n')
        ->and($summary->getStyle("C{$hypertension}")->getNumberFormat()->getFormatCode())->toBe('0.0"%"')
        ->and($summary->getStyle("C{$hypertension}")->getAlignment()->getHorizontal())->toBe('right')
        ->and($summary->getCell("C{$hypertension}")->getValue())->toEqual(57.1)
        ->and($summary->getStyle("A{$headerRow}")->getFill()->getStartColor()->getRGB())->toBe('1F3864');

    // The comparison row: Patients 5 now, 2 before, +3, +150.0%.
    $changeRow = collect($rows)->search(fn ($r) => ($r[0] ?? null) === 'Patients' && ($r[3] ?? null) === 3) + 1;

    expect($summary->getStyle("D{$changeRow}")->getNumberFormat()->getFormatCode())->toBe('+#,##0;-#,##0;0')
        ->and($summary->getStyle("E{$changeRow}")->getNumberFormat()->getFormatCode())->toBe('+0.0"%";-0.0"%";0.0"%"')
        ->and($summary->getCell("E{$changeRow}")->getValue())->toEqual(150);

    // Five columns of figures fit an A4 portrait page; the sheet is fitted one page wide.
    expect($summary->getPageSetup()->getOrientation())->toBe('portrait')
        ->and($summary->getPageSetup()->getFitToWidth())->toBe(1);
});

it('writes a dash for a change in percent that cannot be worked out, and keeps formulas out', function () {
    $book = excelBookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk());
    $summary = $book->getSheetByNameOrThrow('Summary');

    $dash = collect($summary->toArray(null, false, false))->first(fn ($r) => ($r[0] ?? null) === 'Patients' && ($r[4] ?? null) === '–');

    expect($dash)->not->toBeNull();

    foreach ($book->getAllSheets() as $sheet) {
        foreach ($sheet->getRowIterator() as $line) {
            foreach ($line->getCellIterator() as $cell) {
                expect($cell->isFormula())->toBeFalse();
            }
        }
    }
});

it('writes the diagnosis table of a category with a percent column and the same order as the screen', function () {
    seedSeptember($this);

    $book = excelBookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk());
    $er = $book->getSheetByNameOrThrow('ER');
    $rows = $er->toArray(null, false, false);
    $header = collect($rows)->search(fn ($r) => ($r[0] ?? null) === 'Diagnosis' && ($r[2] ?? null) === '% of visits');

    expect(array_slice($rows[$header], 0, 3))->toBe(['Diagnosis', 'Visits', '% of visits'])
        ->and(array_map(fn ($r) => array_slice($r, 0, 2), array_slice($rows, $header + 1, 4)))->toBe([['Asthma', 2], ['Dengue', 1], ['Hypertension', 1], ['Total', 4]]);
});

it('writes nothing to the audit log when a report is only looked at, and one entry for each export', function () {
    seedSeptember($this);

    $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')->assertOk();
    $this->actingAs($this->admin)->get('/reports/print?type=monthly&month=2026-09')->assertOk();

    expect(AuditLog::query()->where('action', 'report.exported')->count())->toBe(0);

    $this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk();

    expect(AuditLog::query()->where('action', 'report.exported')->count())->toBe(1);
});

// --- Printing always shows every panel open ----------------------------------------------

it('opens every folded panel before the screen is printed and folds it again afterwards', function () {
    $tables = file_get_contents(resource_path('js/features/reports/report-tables.tsx'));

    // Ctrl + P on the screen: the browser tells the page before and after it prints.
    expect($tables)->toContain('window.addEventListener("beforeprint", openAll)')
        ->toContain('window.addEventListener("afterprint", foldAgain)')
        ->toContain('querySelectorAll<HTMLDetailsElement>(')
        ->toContain('"details:not([open])"')
        ->toContain('panel.open = true')
        ->toContain('panel.open = false')
        // And the listeners are removed with the component.
        ->toContain('window.removeEventListener("beforeprint", openAll)')
        ->toContain('window.removeEventListener("afterprint", foldAgain)');
});

it('draws the print view plain, with no panel that can be folded, and only the screen folds', function () {
    $print = file_get_contents(resource_path('js/pages/reports/print.tsx'));
    $screen = file_get_contents(resource_path('js/pages/reports/index.tsx'));
    $sheet = file_get_contents(resource_path('js/features/reports/report-sheet.tsx'));
    $tables = file_get_contents(resource_path('js/features/reports/report-tables.tsx'));

    expect($print)->not->toContain('collapsible')
        ->and($screen)->toContain('collapsible')
        // A page is plain unless the screen asks for panels.
        ->and($sheet)->toContain('collapsible = false')
        // The plain table has a heading and no <details>; a plain section is never hidden in print.
        ->and($tables)->toContain('if (!collapsible) {')
        ->and($tables)->not->toContain('print:hidden');
});
