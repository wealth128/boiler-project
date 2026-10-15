<?php

use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use Illuminate\Support\Carbon;

/*
 * Reports on screen (GET /reports) and the counting in ReportBuilder.
 * Rules: planning/mvp-scope.md sections 3-4 and 5a, planning/database-schema.md
 * section 4. Small known data, exact numbers. Times are Asia/Manila.
 * "Today" is 15-Oct-2026, so the default report is September 2026.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $this->army = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'sort_order' => 2]);
    $this->others = Branch::factory()->create(['name' => 'Others', 'is_afp' => false, 'sort_order' => 3]);

    $this->sgt = Rank::factory()->for($this->army)->create(['name' => 'SGT', 'sort_order' => 2]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL', 'sort_order' => 1]);
    $this->ens = Rank::factory()->for($this->navy)->create(['name' => 'ENS', 'sort_order' => 1]);
    $this->dependent = Rank::factory()->for($this->others)->create(['name' => 'Dependent', 'sort_order' => 1]);
    $this->otherRank = Rank::factory()->for($this->others)->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 9]);

    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->asthma = Diagnosis::factory()->create(['name' => 'Asthma']);
    $this->otherDiagnosis = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true]);

    $this->child = AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    $this->adult = AgeBracket::create(['min_age' => 18, 'max_age' => 59]);
});

/**
 * A visit. Army CPL, Hypertension, age 30, ER unless given. $patient is a
 * Patient, or null for a new one.
 *
 * @param  array<string, mixed>  $attributes
 */
function census(object $test, string $when, array $attributes = [], ?Patient $patient = null, string $category = 'ER'): Visit
{
    return Visit::factory()->create([
        'patient_id' => ($patient ?? Patient::factory()->create())->id,
        'visited_at' => Carbon::parse($when, 'Asia/Manila'),
        'category' => $category,
        'age' => 30,
        'rank_id' => $test->cpl->id,
        'branch_id' => $test->army->id,
        'diagnosis_id' => $test->hypertension->id,
        'encoded_by' => $test->encoder->id,
        ...$attributes,
    ]);
}

/** Lines as ['label' => count]. */
function asMap(array $lines): array
{
    return collect($lines)->pluck('count', 'label')->all();
}

/** @return array<string, mixed> */
function monthReport(string $period = '2026-09'): array
{
    return app(ReportBuilder::class)->build($period);
}

// --- Counting ------------------------------------------------------------------

it('counts the same patient twice in ER in one month as 1 patient and 2 visits', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-09-03 09:00', patient: $ana);
    census($this, '2026-09-20 14:00', patient: $ana);

    $er = monthReport()['categories']['ER'];

    expect($er['patients'])->toBe(1)
        ->and($er['visits'])->toBe(2);
});

it('counts a patient in both ER and Admission in the same month', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-09-03 09:00', patient: $ana, category: 'ER');
    census($this, '2026-09-04 09:00', patient: $ana, category: 'Admission');

    $report = monthReport();

    expect($report['categories']['ER'])->toMatchArray(['patients' => 1, 'visits' => 1])
        ->and($report['categories']['Admission'])->toMatchArray(['patients' => 1, 'visits' => 1])
        ->and($report['categories']['OPD'])->toMatchArray(['patients' => 0, 'visits' => 0])
        // Counted once across all categories, with every visit.
        ->and($report['totals'])->toBe(['patients' => 1, 'visits' => 2]);
});

it('counts ER on 31-Jan in January and Admission on 1-Feb in February', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-01-31 22:00', patient: $ana, category: 'ER');
    census($this, '2026-02-01 08:00', patient: $ana, category: 'Admission');

    $january = monthReport('2026-01');
    $february = monthReport('2026-02');

    expect($january['categories']['ER']['patients'])->toBe(1)
        ->and($january['categories']['Admission']['patients'])->toBe(0)
        ->and($february['categories']['ER']['patients'])->toBe(0)
        ->and($february['categories']['Admission']['patients'])->toBe(1);
});

it('counts a patient seen in January and February once in Q1', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-01-10 09:00', patient: $ana);
    census($this, '2026-02-10 09:00', patient: $ana);

    $props = $this->actingAs($this->admin)->get('/reports?type=quarterly&quarter=1&year=2026')->inertiaProps();

    expect($props['report']['categories']['ER'])->toMatchArray(['patients' => 1, 'visits' => 2])
        ->and($props['report']['totals'])->toBe(['patients' => 1, 'visits' => 2]);
});

it('counts a patient in two categories once in the All grand total', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-09-03 09:00', patient: $ana, category: 'ER');
    census($this, '2026-09-04 09:00', patient: $ana, category: 'OPD');
    census($this, '2026-09-05 09:00', category: 'OPD'); // another patient

    expect(monthReport()['totals'])->toBe(['patients' => 2, 'visits' => 3]);
});

it('leaves visits in Trash out of every count', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-09-03 09:00', patient: $ana);
    census($this, '2026-09-04 09:00', patient: $ana)->moveToTrash($this->admin, 'Duplicate entry');
    census($this, '2026-09-05 09:00')->moveToTrash($this->admin, 'Duplicate entry');

    $report = monthReport();

    expect($report['categories']['ER'])->toMatchArray(['patients' => 1, 'visits' => 1])
        ->and($report['totals'])->toBe(['patients' => 1, 'visits' => 1]);
});

it('takes sex from the patient, and age bracket and rank from the latest visit', function () {
    $ana = Patient::factory()->create(['sex' => 'Female']);
    $ben = Patient::factory()->create(['sex' => 'Male']);

    // Ana's latest visit is the 20th. It is saved FIRST (lower id), so this
    // also shows the latest is by time, not by id.
    census($this, '2026-09-20 10:00', ['age' => 30, 'diagnosis_id' => $this->asthma->id, 'rank_id' => $this->sgt->id], $ana);
    census($this, '2026-09-05 10:00', ['age' => 10, 'diagnosis_id' => $this->hypertension->id, 'rank_id' => $this->cpl->id], $ana);
    census($this, '2026-09-06 10:00', ['age' => 70], $ben);

    $er = monthReport()['categories']['ER'];

    expect($er['patients'])->toBe(2)
        ->and($er['visits'])->toBe(3)
        ->and(asMap($er['sex']))->toBe(['Male' => 1, 'Female' => 1])
        ->and(asMap($er['age']))->toBe(['0–17' => 0, '18–59' => 1, 'No bracket' => 1])
        // Diagnosis is per visit, so Ana's two diagnoses and Ben's both show.
        ->and(asMap($er['diagnoses']))->toBe(['Hypertension' => 2, 'Asthma' => 1])
        ->and(asMap($er['branches'][0]['ranks']))->toBe(['CPL' => 1, 'SGT' => 1]);
});

it('shows a No bracket line only when some age fits no bracket', function () {
    census($this, '2026-09-05 10:00', ['age' => 20]);

    expect(monthReport()['categories']['ER']['age'])->toBe([
        ['label' => '0–17', 'count' => 0],
        ['label' => '18–59', 'count' => 1],
    ]);

    census($this, '2026-09-06 10:00', ['age' => 75]);

    expect(monthReport()['categories']['ER']['age'])->toBe([
        ['label' => '0–17', 'count' => 0],
        ['label' => '18–59', 'count' => 1],
        ['label' => 'No bracket', 'count' => 1],
    ]);
});

it('lists only active age brackets, in From-age order', function () {
    AgeBracket::create(['min_age' => 60, 'max_age' => null]);
    $old = AgeBracket::create(['min_age' => 100, 'max_age' => 120]);
    $old->moveToTrash($this->admin, 'No longer used');
    census($this, '2026-09-05 10:00', ['age' => 64]);

    expect(array_column(monthReport()['categories']['ER']['age'], 'label'))->toBe(['0–17', '18–59', '60 & above']);
});

it('counts diagnoses per visit: a patient with 3 visits and 3 diagnoses shows all 3', function () {
    $ana = Patient::factory()->create();
    $cough = Diagnosis::factory()->create(['name' => 'Cough']);

    census($this, '2026-09-03 10:00', ['diagnosis_id' => $this->hypertension->id], $ana);
    census($this, '2026-09-10 10:00', ['diagnosis_id' => $this->asthma->id], $ana);
    census($this, '2026-09-20 10:00', ['diagnosis_id' => $cough->id], $ana);

    $er = monthReport()['categories']['ER'];

    // One patient, so the other tables count her once, but every visit's diagnosis is listed.
    expect($er['patients'])->toBe(1)
        ->and(asMap($er['diagnoses']))->toBe(['Asthma' => 1, 'Cough' => 1, 'Hypertension' => 1]);
});

it('adds the Diagnosis table up to Visits while Sex adds up to Patients', function () {
    $ana = Patient::factory()->create(['sex' => 'Female']);
    $ben = Patient::factory()->create(['sex' => 'Male']);

    census($this, '2026-09-03 10:00', patient: $ana);
    census($this, '2026-09-04 10:00', ['diagnosis_id' => $this->asthma->id], $ana);
    census($this, '2026-09-05 10:00', ['diagnosis_id' => $this->otherDiagnosis->id, 'diagnosis_other' => 'Snake bite'], $ana);
    census($this, '2026-09-06 10:00', patient: $ben);

    $er = monthReport()['categories']['ER'];

    expect($er['patients'])->toBe(2)
        ->and($er['visits'])->toBe(4)
        ->and(array_sum(array_column($er['diagnoses'], 'count')))->toBe(4)
        ->and(array_sum(array_column($er['sex'], 'count')))->toBe(2);
});

it('lists every diagnosis with no cut-off and no Others line', function () {
    foreach (range('A', 'L') as $letter) { // 12 diagnoses, one visit each
        census($this, '2026-09-05 10:00', ['diagnosis_id' => Diagnosis::factory()->create(['name' => "Dx {$letter}"])->id]);
    }

    $labels = array_column(monthReport()['categories']['ER']['diagnoses'], 'label');

    expect($labels)->toHaveCount(12)
        ->and($labels[0])->toBe('Dx A')
        ->and($labels[11])->toBe('Dx L')
        ->and($labels)->not->toContain('Others');
});

it('lists typed Other (specify) text by name, merged across case and spaces', function () {
    $other = fn (string $when, ?string $text) => census($this, $when, ['diagnosis_id' => $this->otherDiagnosis->id, 'diagnosis_other' => $text]);

    $other('2026-09-05 10:00', 'Snake bite');
    $other('2026-09-06 10:00', 'snake bite');
    $other('2026-09-07 10:00', '  SNAKE   BITE ');
    $other('2026-09-08 10:00', 'Dog bite');
    $other('2026-09-09 10:00', '   ');
    $other('2026-09-10 10:00', null);
    census($this, '2026-09-11 10:00'); // Hypertension

    $lines = monthReport()['categories']['ER']['diagnoses'];

    expect(array_column($lines, 'label'))->toBe(['Snake bite', 'Other (unspecified)', 'Dog bite', 'Hypertension'])
        ->and(array_column($lines, 'count'))->toBe([3, 2, 1, 1])
        ->and(array_sum(array_column($lines, 'count')))->toBe(7);
});

it('counts typed Other text that matches a listed diagnosis under that diagnosis', function () {
    $trashed = Diagnosis::factory()->create(['name' => 'Dengue Fever']);
    $other = fn (string $when, string $text) => census($this, $when, ['diagnosis_id' => $this->otherDiagnosis->id, 'diagnosis_other' => $text]);

    census($this, '2026-09-05 10:00'); // Hypertension, picked from the list
    $other('2026-09-06 10:00', 'hypertension');
    $other('2026-09-07 10:00', '  HYPERTENSION  ');
    $other('2026-09-08 10:00', 'Asthma'); // Asthma has no listed visit this month
    $other('2026-09-09 10:00', 'dengue   fever'); // matches a diagnosis that is now in Trash
    $trashed->moveToTrash($this->admin, 'No longer used');
    $other('2026-09-10 10:00', 'Snake bite');

    $lines = monthReport()['categories']['ER']['diagnoses'];

    expect(asMap($lines))->toBe(['Hypertension' => 3, 'Asthma' => 1, 'Dengue Fever' => 1, 'Snake bite' => 1])
        ->and(array_column($lines, 'label'))->toBe(array_values(array_unique(array_column($lines, 'label'))))
        ->and(array_sum(array_column($lines, 'count')))->toBe(6);
});

it('sorts diagnoses by count, highest first, then by name', function () {
    $dx = fn (string $name) => Diagnosis::factory()->create(['name' => $name])->id;
    $zeta = $dx('Zeta');
    $alpha = $dx('alpha'); // lower case: sorted ignoring case
    $beta = $dx('Beta');

    foreach ([$zeta, $zeta, $zeta, $beta, $alpha] as $i => $id) {
        census($this, '2026-09-05 1'.$i.':00', ['diagnosis_id' => $id]);
    }

    census($this, '2026-09-06 10:00', ['diagnosis_id' => $this->otherDiagnosis->id, 'diagnosis_other' => 'Aardvark bite']);
    census($this, '2026-09-06 11:00', ['diagnosis_id' => $this->otherDiagnosis->id, 'diagnosis_other' => 'Aardvark bite']);

    $lines = monthReport()['categories']['ER']['diagnoses'];

    expect(array_column($lines, 'label'))->toBe(['Zeta', 'Aardvark bite', 'alpha', 'Beta'])
        ->and(array_column($lines, 'count'))->toBe([3, 2, 1, 1]);
});

it('leaves visits in Trash out of the Diagnosis table', function () {
    census($this, '2026-09-05 10:00');
    census($this, '2026-09-06 10:00', ['diagnosis_id' => $this->asthma->id])->moveToTrash($this->admin, 'Duplicate entry');
    census($this, '2026-09-07 10:00', ['diagnosis_id' => $this->otherDiagnosis->id, 'diagnosis_other' => 'Snake bite'])->moveToTrash($this->admin, 'Duplicate entry');

    expect(asMap(monthReport()['categories']['ER']['diagnoses']))->toBe(['Hypertension' => 1]);
});

it('shows the Diagnosis total on the screen as the category Visits', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-09-05 10:00', patient: $ana);
    census($this, '2026-09-06 10:00', ['diagnosis_id' => $this->asthma->id], $ana);

    $er = $this->actingAs($this->admin)->get('/reports')->inertiaProps('report.categories.ER');

    expect($er['patients'])->toBe(1)
        ->and($er['visits'])->toBe(2)
        ->and(array_sum(array_column($er['diagnoses'], 'count')))->toBe(2);
});

it('lists branches in branch order and ranks in seniority order, with typed Other (specify) text after', function () {
    // Counts that would sort differently by size: SGT 2, CPL 1.
    census($this, '2026-09-05 10:00', ['rank_id' => $this->sgt->id]);
    census($this, '2026-09-05 11:00', ['rank_id' => $this->sgt->id]);
    census($this, '2026-09-05 12:00', ['rank_id' => $this->cpl->id]);
    census($this, '2026-09-05 13:00', ['rank_id' => $this->ens->id, 'branch_id' => $this->navy->id]);
    census($this, '2026-09-05 14:00', ['rank_id' => $this->dependent->id, 'branch_id' => $this->others->id]);
    census($this, '2026-09-05 15:00', ['rank_id' => $this->otherRank->id, 'branch_id' => $this->others->id, 'rank_other' => 'Veteran']);
    census($this, '2026-09-05 16:00', ['rank_id' => $this->otherRank->id, 'branch_id' => $this->others->id, 'rank_other' => 'Contractor']);

    $branches = monthReport()['categories']['ER']['branches'];

    expect($branches)->toBe([
        ['name' => 'Army', 'total' => 3, 'ranks' => [['label' => 'CPL', 'count' => 1], ['label' => 'SGT', 'count' => 2]]],
        ['name' => 'Navy', 'total' => 1, 'ranks' => [['label' => 'ENS', 'count' => 1]]],
        ['name' => 'Others', 'total' => 3, 'ranks' => [
            ['label' => 'Dependent', 'count' => 1],
            ['label' => 'Contractor', 'count' => 1],
            ['label' => 'Veteran', 'count' => 1],
        ]],
    ]);
});

it('leaves out branches and ranks with no patients', function () {
    census($this, '2026-09-05 10:00');

    $branches = monthReport()['categories']['ER']['branches'];

    expect(array_column($branches, 'name'))->toBe(['Army'])
        ->and(array_column($branches[0]['ranks'], 'label'))->toBe(['CPL']);
});

it('counts 23:59 on the last day of the month in that month, and midnight in the next', function () {
    census($this, '2026-09-30 23:59');
    census($this, '2026-10-01 00:00');
    census($this, '2026-09-01 00:00');
    census($this, '2026-08-31 23:59');

    expect(monthReport('2026-09')['categories']['ER']['patients'])->toBe(2)
        ->and(monthReport('2026-10')['categories']['ER']['patients'])->toBe(1);
});

it('shows a category with no patients as zero', function () {
    $opd = monthReport()['categories']['OPD'];

    expect($opd)->toBe([
        'patients' => 0,
        'visits' => 0,
        'sex' => [['label' => 'Male', 'count' => 0], ['label' => 'Female', 'count' => 0]],
        'age' => [['label' => '0–17', 'count' => 0], ['label' => '18–59', 'count' => 0]],
        'diagnoses' => [],
        'branches' => [],
    ]);
});

// --- The screen --------------------------------------------------------------

it('opens with last month and all categories when nothing is chosen', function () {
    census($this, '2026-09-10 09:00');
    census($this, '2026-10-02 09:00'); // this month: not in the default report

    $props = $this->actingAs($this->admin)->get('/reports')->assertOk()->inertiaProps();

    expect($props['filters'])->toBe([
        'type' => 'monthly', 'month' => '2026-09', 'quarter' => '3', 'year' => '2026',
        'from' => '2026-09-01', 'to' => '2026-09-30', 'category' => '',
    ])
        ->and($props['sheet']['period_label'])->toBe('September 2026')
        ->and($props['sheet']['title'])->toBe('Monthly Patient Census Report')
        ->and($props['sheet']['category'])->toBeNull()
        ->and(array_keys($props['report']['categories']))->toBe(['OPD', 'ER', 'Admission'])
        ->and($props['report']['totals'])->toBe(['patients' => 1, 'visits' => 1])
        ->and($props['month_status'])->toBe(['closed' => false])
        ->and($props['snapshot'])->toBeNull();
});

it('shows only the chosen category', function () {
    census($this, '2026-09-10 09:00', category: 'ER');
    census($this, '2026-09-10 09:00', category: 'OPD');

    $props = $this->actingAs($this->admin)->get('/reports?category=ER')->inertiaProps();

    expect(array_keys($props['report']['categories']))->toBe(['ER'])
        ->and($props['sheet']['category'])->toBe('ER')
        ->and($props['filters']['category'])->toBe('ER')
        ->and($props['report']['totals'])->toBe(['patients' => 1, 'visits' => 1]);
});

it('labels each period type', function (string $query, string $label, string $title) {
    $sheet = $this->actingAs($this->admin)->get("/reports?{$query}")->inertiaProps('sheet');

    expect($sheet['period_label'])->toBe($label)
        ->and($sheet['title'])->toBe($title);
})->with([
    'month' => ['type=monthly&month=2026-08', 'August 2026', 'Monthly Patient Census Report'],
    'quarter' => ['type=quarterly&quarter=3&year=2026', 'Q3 2026 (Jul–Sep)', 'Quarterly Patient Census Report'],
    'quarter 1' => ['type=quarterly&quarter=1&year=2026', 'Q1 2026 (Jan–Mar)', 'Quarterly Patient Census Report'],
    'year' => ['type=annual&year=2026', 'Calendar Year 2026', 'Annual Patient Census Report'],
    'custom' => ['type=custom&from=2026-09-01&to=2026-09-30', '01-Sep-2026 to 30-Sep-2026', 'Patient Census Report'],
]);

it('counts a custom range through the whole last day', function () {
    census($this, '2026-09-30 23:59');
    census($this, '2026-10-01 00:00');
    census($this, '2026-08-31 23:59');

    $report = $this->actingAs($this->admin)->get('/reports?type=custom&from=2026-09-01&to=2026-09-30')->inertiaProps('report');

    expect($report['categories']['ER']['patients'])->toBe(1)
        ->and($report['from'])->toBe('2026-09-01')
        ->and($report['to'])->toBe('2026-09-30');
});

it('counts an annual report across the calendar year', function () {
    $ana = Patient::factory()->create();
    census($this, '2026-01-01 00:00', patient: $ana);
    census($this, '2026-12-31 23:59', patient: $ana);
    census($this, '2025-12-31 23:59');

    $er = $this->actingAs($this->admin)->get('/reports?type=annual&year=2026')->inertiaProps('report.categories.ER');

    expect($er)->toMatchArray(['patients' => 1, 'visits' => 2]);
});

it('falls back to the defaults for bad URL values', function (string $query) {
    $props = $this->actingAs($this->admin)->get("/reports?{$query}")->assertOk()->inertiaProps();

    expect($props['filters'])->toBe([
        'type' => 'monthly', 'month' => '2026-09', 'quarter' => '3', 'year' => '2026',
        'from' => '2026-09-01', 'to' => '2026-09-30', 'category' => '',
    ])
        ->and($props['sheet']['period_label'])->toBe('September 2026');
})->with([
    'unknown type' => ['type=weekly'],
    'bad month' => ['type=monthly&month=garbage'],
    'month in the future' => ['type=monthly&month=2026-12'],
    'month before the lists start' => ['type=monthly&month=2025-12'],
    'month array' => ['type=monthly&month[]=2026-08'],
    'bad category' => ['category=XYZ'],
    'category array' => ['category[]=ER'],
    'custom without dates' => ['type=custom'],
    'custom without To' => ['type=custom&from=2026-09-01'],
    'custom To before From' => ['type=custom&from=2026-09-10&to=2026-09-01'],
    'custom not a date' => ['type=custom&from=2026-02-31&to=2026-03-05'],
    'custom wrong format' => ['type=custom&from=01-09-2026&to=30-09-2026'],
]);

it('falls back for a bad quarter, year or a quarter that has not started', function (string $query, string $label) {
    $sheet = $this->actingAs($this->admin)->get("/reports?{$query}")->assertOk()->inertiaProps('sheet');

    expect($sheet['period_label'])->toBe($label);
})->with([
    'quarter 9' => ['type=quarterly&quarter=9&year=2026', 'Q3 2026 (Jul–Sep)'],
    'quarter text' => ['type=quarterly&quarter=x&year=2026', 'Q3 2026 (Jul–Sep)'],
    'year too old' => ['type=quarterly&quarter=1&year=2025', 'Q1 2026 (Jan–Mar)'],
    'year in the future' => ['type=annual&year=2031', 'Calendar Year 2026'],
    'year text' => ['type=annual&year=abc', 'Calendar Year 2026'],
    'current quarter is fine' => ['type=quarterly&quarter=4&year=2026', 'Q4 2026 (Oct–Dec)'],
]);

it('lists months from January 2026 up to this month, newest first', function () {
    census($this, '2026-03-14 09:00'); // visits don't change where the list starts

    $options = $this->actingAs($this->admin)->get('/reports')->inertiaProps('options');

    expect(array_column($options['months'], 'value'))->toBe([
        '2026-10', '2026-09', '2026-08', '2026-07', '2026-06', '2026-05', '2026-04', '2026-03', '2026-02', '2026-01',
    ])
        ->and($options['months'][0]['label'])->toBe('October 2026')
        ->and($options['years'])->toBe(['2026'])
        ->and($options['categories'])->toBe(['OPD', 'ER', 'Admission']);
});

it('keeps January 2026 as the start when the date moves into 2027', function () {
    $this->travelTo(Carbon::parse('2027-02-10 10:00', 'Asia/Manila'));

    $options = $this->actingAs($this->admin)->get('/reports')->inertiaProps('options');

    expect($options['months'])->toHaveCount(14)
        ->and($options['months'][0]['value'])->toBe('2027-02')
        ->and($options['months'][13]['value'])->toBe('2026-01')
        ->and($options['years'])->toBe(['2027', '2026']);
});

it('puts the hospital name, office and signatures from the settings on the sheet', function () {
    $sheet = $this->actingAs($this->admin)->get('/reports')->inertiaProps('sheet');

    expect($sheet['hospital_name'])->toBe('[Hospital Name]')
        ->and($sheet['prepared_by'])->toBe('')
        ->and($sheet['noted_by'])->toBe('');

    foreach (['hospital_name' => 'AFP Medical Center', 'prepared_by' => 'Ana Reyes', 'noted_by' => 'COL Cruz', 'report_office' => 'Records Office'] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    $sheet = $this->actingAs($this->admin)->get('/reports')->inertiaProps('sheet');

    expect($sheet)->toMatchArray([
        'hospital_name' => 'AFP Medical Center',
        'office' => 'Records Office',
        'prepared_by' => 'Ana Reyes',
        'noted_by' => 'COL Cruz',
        'noted_by_title' => 'Commanding Officer',
    ]);
});

// --- Closed month: saved copy --------------------------------------------------

it('shows the saved copy of a closed month, even after a diagnosis is renamed', function () {
    census($this, '2026-09-10 09:00');
    app(MonthService::class)->close('2026-09', $this->admin);

    $this->hypertension->update(['name' => 'High blood pressure']);
    census($this, '2026-09-11 09:00'); // added after closing (e.g. by a restore): not in the copy

    $props = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')->inertiaProps();

    expect($props['snapshot'])->toBe([
        'month_label' => 'September 2026',
        'taken_at' => now()->toIso8601String(),
    ])
        ->and($props['month_status'])->toBe(['closed' => true])
        ->and($props['report']['categories']['ER']['patients'])->toBe(1)
        ->and(asMap($props['report']['categories']['ER']['diagnoses']))->toBe(['Hypertension' => 1])
        ->and($props['report']['totals'])->toEqual(['patients' => 1, 'visits' => 1]);

    // Quarterly is always live: new name, both visits.
    $live = $this->actingAs($this->admin)->get('/reports?type=quarterly&quarter=3&year=2026')->inertiaProps();

    expect($live['snapshot'])->toBeNull()
        ->and($live['month_status'])->toBeNull()
        ->and(asMap($live['report']['categories']['ER']['diagnoses']))->toBe(['High blood pressure' => 2]);
});

it('shows one category of a saved copy with that category as its totals', function () {
    census($this, '2026-09-10 09:00', category: 'ER');
    census($this, '2026-09-10 09:00', category: 'OPD');
    app(MonthService::class)->close('2026-09', $this->admin);

    $report = $this->actingAs($this->admin)->get('/reports?category=OPD&month=2026-09')->inertiaProps('report');

    expect(array_keys($report['categories']))->toBe(['OPD'])
        ->and($report['totals'])->toEqual(['patients' => 1, 'visits' => 1]);
});

it('counts a reopened month live again', function () {
    census($this, '2026-09-10 09:00');
    app(MonthService::class)->close('2026-09', $this->admin);
    app(MonthService::class)->reopen('2026-09', $this->admin);
    census($this, '2026-09-11 09:00');

    $props = $this->actingAs($this->admin)->get('/reports?month=2026-09')->inertiaProps();

    expect($props['snapshot'])->toBeNull()
        ->and($props['month_status'])->toBe(['closed' => false])
        ->and($props['report']['categories']['ER']['patients'])->toBe(2);
});

it('counts live when a closed month only has a saved copy in the old shape', function () {
    census($this, '2026-09-10 09:00');
    MonthClosure::create(['period' => '2026-09', 'is_closed' => true, 'closed_by' => $this->admin->id, 'closed_at' => now()]);
    ReportSnapshot::create([
        'period' => '2026-09',
        'data' => ['period' => '2026-09', 'categories' => ['ER' => ['patients' => 99, 'visits' => 99]]],
        'created_by' => $this->admin->id,
    ]);

    $props = $this->actingAs($this->admin)->get('/reports?month=2026-09')->inertiaProps();

    expect($props['snapshot'])->toBeNull()
        ->and($props['month_status'])->toBe(['closed' => true])
        ->and($props['report']['categories']['ER']['patients'])->toBe(1);
});

it('counts live when the saved copy is version 2 (Top 10 diagnoses)', function () {
    census($this, '2026-09-10 09:00');
    MonthClosure::create(['period' => '2026-09', 'is_closed' => true, 'closed_by' => $this->admin->id, 'closed_at' => now()]);
    ReportSnapshot::create([
        'period' => '2026-09',
        'data' => ['version' => 2, 'categories' => ['ER' => ['patients' => 99, 'visits' => 99]]],
        'created_by' => $this->admin->id,
    ]);

    $props = $this->actingAs($this->admin)->get('/reports?month=2026-09')->inertiaProps();

    expect(ReportBuilder::VERSION)->toBe(3)
        ->and($props['snapshot'])->toBeNull()
        ->and($props['report']['categories']['ER']['visits'])->toBe(1);
});

it('saves the full report when a month is closed', function () {
    census($this, '2026-09-10 09:00', ['age' => 20]);
    app(MonthService::class)->close('2026-09', $this->admin);

    $data = ReportSnapshot::where('period', '2026-09')->sole()->data;

    expect($data['version'])->toBe(ReportBuilder::VERSION)
        ->and($data['totals'])->toEqual(['patients' => 1, 'visits' => 1])
        ->and($data['categories'])->toHaveKeys(['OPD', 'ER', 'Admission'])
        ->and(asMap($data['categories']['ER']['age']))->toBe(['0–17' => 0, '18–59' => 1]);
});

// --- Access -------------------------------------------------------------------

it('lets Admin and Viewer open Reports', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/reports')->assertOk();
})->with(['admin', 'viewer']);

it('refuses Reports to Encoder and System Admin', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/reports')->assertForbidden();
})->with(['encoder', 'system_admin']);

it('sends a signed-out visitor to the login page', function () {
    $this->get('/reports')->assertRedirect('/login');
});
