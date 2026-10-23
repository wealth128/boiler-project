<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\HospitalSettings;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use App\Services\ReportSheetResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * Reports split by category (Step 6d-4): tabs OPD | ER | Admission | Summary,
 * one at a time; Print and PDF for "This category" or "All categories" (each
 * category on its own page with its own header and signature block); Excel
 * with a Summary sheet and one sheet per category. Display only: nothing is
 * counted differently. "Today" is 15-Oct-2026, so the default month is
 * September 2026.
 *
 * September 2026 has: ER 2 patients / 3 visits, OPD 1 patient / 1 visit,
 * Admission none.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $army = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->rank = Rank::factory()->for($army)->create(['name' => 'CPL', 'sort_order' => 1]);
    $this->branch = $army;
    $this->diagnosis = Diagnosis::factory()->create(['name' => 'Hypertension']);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);

    $ana = Patient::factory()->create();
    tabVisit($this, '2026-09-05 09:00', 'ER', $ana);
    tabVisit($this, '2026-09-06 09:00', 'ER', $ana);
    tabVisit($this, '2026-09-07 09:00', 'ER');
    tabVisit($this, '2026-09-08 09:00', 'OPD');

    foreach (['hospital_name' => 'AFP Medical Center', 'prepared_by' => 'Ana Reyes', 'noted_by' => 'COL Cruz'] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(HospitalSettings::class)->storeImage('prepared_signature', UploadedFile::fake()->image('a.png', 300, 100));
    app(HospitalSettings::class)->storeImage('noted_signature', UploadedFile::fake()->image('b.png', 300, 100));
});

function tabVisit(object $test, string $when, string $category, ?Patient $patient = null): Visit
{
    return Visit::factory()->create([
        'patient_id' => ($patient ?? Patient::factory()->create())->id,
        'visited_at' => Carbon::parse($when, 'Asia/Manila'),
        'category' => $category,
        'age' => 30,
        'rank_id' => $test->rank->id,
        'branch_id' => $test->branch->id,
        'diagnosis_id' => $test->diagnosis->id,
        'encoded_by' => $test->encoder->id,
    ]);
}

/** The PDF page's HTML for a query, built the way the PDF controller builds it. */
function tabPdfHtml(string $query): string
{
    parse_str($query, $params);

    $resolver = app(ReportSheetResolver::class);
    $data = $resolver->resolve($params);

    return view('reports.pdf', [
        ...$data,
        'generatedAt' => now(),
        'logoData' => null,
        'signatureData' => $resolver->signatureData($data),
        'pages' => $data['filters']->pages(),
    ])->render();
}

const SEP = 'type=monthly&month=2026-09';

// --- The tab in the URL ------------------------------------------------------------

it('opens the tab named in the URL, in any letter case', function (string $value, string $tab, ?string $category) {
    $props = $this->actingAs($this->admin)->get('/reports?'.SEP.'&category='.$value)->assertOk()->inertiaProps();

    expect($props['filters']['category'])->toBe($tab)
        ->and($props['sheet']['category'])->toBe($category);
})->with([
    'opd' => ['opd', 'opd', 'OPD'],
    'OPD' => ['OPD', 'opd', 'OPD'],
    'er' => ['er', 'er', 'ER'],
    'Er' => ['Er', 'er', 'ER'],
    'admission' => ['admission', 'admission', 'Admission'],
    'Admission' => ['Admission', 'admission', 'Admission'],
    'summary' => ['summary', 'summary', null],
    'SUMMARY' => ['SUMMARY', 'summary', null],
]);

it('opens the ER tab when nothing is chosen', function () {
    $props = $this->actingAs($this->admin)->get('/reports')->inertiaProps();

    expect($props['filters']['category'])->toBe('er')->and($props['sheet']['category'])->toBe('ER');
});

it('falls back to the ER tab for a category that is not one of the tabs', function (string $query) {
    $props = $this->actingAs($this->admin)->get('/reports?'.SEP.'&'.$query)->assertOk()->inertiaProps();

    expect($props['filters']['category'])->toBe('er')->and($props['sheet']['category'])->toBe('ER');
})->with([
    'unknown' => ['category=ICU'],
    'old "All"' => ['category='],
    'all' => ['category=all'],
    'array' => ['category[]=opd'],
]);

it('keeps the period choices when a tab is opened', function (string $query, array $expected) {
    $filters = $this->actingAs($this->admin)->get('/reports?'.$query)->inertiaProps('filters');

    expect($filters)->toMatchArray($expected);
})->with([
    'monthly' => ['type=monthly&month=2026-08&category=admission', ['type' => 'monthly', 'month' => '2026-08', 'category' => 'admission']],
    'quarterly' => ['type=quarterly&quarter=2&year=2026&category=opd', ['type' => 'quarterly', 'quarter' => '2', 'year' => '2026', 'category' => 'opd']],
    'annual' => ['type=annual&year=2026&category=summary', ['type' => 'annual', 'year' => '2026', 'category' => 'summary']],
    'custom' => ['type=custom&from=2026-09-01&to=2026-09-20&category=er', ['type' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-20', 'category' => 'er']],
]);

it('keeps the tab in the Print and PDF links and every period choice', function () {
    $query = 'type=quarterly&quarter=3&year=2026&category=opd';

    $print = $this->actingAs($this->admin)->get('/reports/print?'.$query)->assertOk()->inertiaProps();
    $this->actingAs($this->admin)->get('/reports/export/pdf?'.$query)->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="census-report_2026-Q3_opd.pdf"');

    expect($print['filters'])->toMatchArray(['type' => 'quarterly', 'quarter' => '3', 'year' => '2026', 'category' => 'opd'])
        ->and($print['pages'])->toBe(['OPD']);
});

// --- Each tab shows only its own category ---------------------------------------------

it('shows only the category of the tab (and only the summary on the Summary tab)', function (string $tab, string $present, array $absent) {
    $html = tabPdfHtml(SEP.'&category='.$tab);

    expect($html)->toContain($present);

    foreach ($absent as $text) {
        expect($html)->not->toContain($text);
    }
})->with([
    'ER' => ['er', '<h2>ER</h2>', ['<h2>OPD</h2>', '<h2>Admission</h2>', 'Total patients (all categories)']],
    'OPD' => ['opd', '<h2>OPD</h2>', ['<h2>ER</h2>', '<h2>Admission</h2>', 'Total patients (all categories)']],
    'Admission' => ['admission', '<h2>Admission</h2>', ['<h2>ER</h2>', '<h2>OPD</h2>', 'Total patients (all categories)']],
    'Summary' => ['summary', 'Total patients (all categories)', ['<h2>ER</h2>', '<h2>OPD</h2>', '<h2>Admission</h2>']],
]);

it('shows the numbers of the tab in its page and no other category\'s', function () {
    $er = tabPdfHtml(SEP.'&category=er');
    $opd = tabPdfHtml(SEP.'&category=opd');

    // ER: 2 patients, 3 visits. OPD: 1 patient, 1 visit.
    expect($er)->toContain('<b>2</b><span>Patients</span>')->toContain('<b>3</b><span>Visits</span>')
        ->and($opd)->toContain('<b>1</b><span>Patients</span>')->toContain('<b>1</b><span>Visits</span>')
        ->and($opd)->not->toContain('<b>3</b><span>Visits</span>');
});

it('says so when a category has no visits in the period', function () {
    $html = tabPdfHtml(SEP.'&category=admission');

    expect($html)->toContain('No visits encoded for Admission in this period.');
});

it('shows each category\'s numbers on the Summary tab', function () {
    $html = tabPdfHtml(SEP.'&category=summary');

    // Grand totals: 3 patients (Ana + one more ER, one OPD), 4 visits.
    expect($html)
        ->toContain('<b>3</b><span>Total patients (all categories)</span>')
        ->toContain('<b>4</b><span>Total visits</span>')
        ->toContain('<b>1</b><span>OPD patients</span>')
        ->toContain('<b>2</b><span>ER patients</span>')
        ->toContain('<b>0</b><span>Admission patients</span>');
});

// --- Totals are exactly the current report -----------------------------------------------

it('keeps the report numbers exactly as the report builder counts them for an open month', function (string $tab) {
    $props = $this->actingAs($this->admin)->get('/reports?'.SEP.'&category='.$tab)->inertiaProps();
    $built = app(ReportBuilder::class)->build('2026-09');

    expect($props['report']['totals'])->toEqual($built['totals'])
        ->and($props['report']['categories'])->toEqual($built['categories'])
        ->and($props['report']['totals'])->toEqual(['patients' => 3, 'visits' => 4]);
})->with(['opd', 'er', 'admission', 'summary']);

it('keeps the report numbers exactly as the saved copy for a closed month', function (string $tab) {
    app(MonthService::class)->close('2026-09', $this->admin);
    $saved = ReportSnapshot::query()->where('period', '2026-09')->sole()->data;

    $props = $this->actingAs($this->admin)->get('/reports?'.SEP.'&category='.$tab)->inertiaProps();

    expect($props['snapshot'])->not->toBeNull()
        ->and($props['report']['totals'])->toEqual($saved['totals'])
        ->and($props['report']['categories'])->toEqual($saved['categories']);
})->with(['opd', 'er', 'admission', 'summary']);

it('gives the same report whichever tab is open, and quarters and years too', function (string $period) {
    $reports = collect(['opd', 'er', 'admission', 'summary'])
        ->map(fn (string $tab) => $this->actingAs($this->admin)->get('/reports?'.$period.'&category='.$tab)->inertiaProps('report'));

    expect($reports->unique(fn (array $r) => json_encode($r)))->toHaveCount(1);
})->with([
    'month' => [SEP],
    'quarter' => ['type=quarterly&quarter=3&year=2026'],
    'year' => ['type=annual&year=2026'],
    'custom' => ['type=custom&from=2026-09-01&to=2026-09-30'],
]);

it('lists the categories in the usual order for a closed month too', function () {
    app(MonthService::class)->close('2026-09', $this->admin);

    $report = $this->actingAs($this->admin)->get('/reports?'.SEP)->inertiaProps('report');

    expect(array_keys($report['categories']))->toBe(['OPD', 'ER', 'Admission']);
});

// --- Viewer ---------------------------------------------------------------------------------

it('lets the Viewer (CO) use every tab, Print, PDF and Excel', function (string $tab) {
    $viewer = userWithRole('viewer');

    $this->actingAs($viewer)->get('/reports?'.SEP.'&category='.$tab)->assertOk();
    $this->actingAs($viewer)->get('/reports/print?'.SEP.'&category='.$tab)->assertOk();
    $this->actingAs($viewer)->get('/reports/print?'.SEP.'&category='.$tab.'&scope=all')->assertOk();
    $this->actingAs($viewer)->get('/reports/export/pdf?'.SEP.'&category='.$tab)->assertOk();
    $this->actingAs($viewer)->get('/reports/export/pdf?'.SEP.'&category='.$tab.'&scope=all')->assertOk();
    $this->actingAs($viewer)->get('/reports/export/excel?'.SEP.'&category='.$tab)->assertOk();
})->with(['opd', 'er', 'admission', 'summary']);

it('keeps the print view for Admin and Viewer only', function () {
    $this->actingAs($this->encoder)->get('/reports/print')->assertForbidden();
    $this->actingAs(userWithRole('system_admin'))->get('/reports/print')->assertForbidden();

    auth()->logout();
    $this->get('/reports/print')->assertRedirect(route('login'));
});

// --- Print view ---------------------------------------------------------------------------------

it('prints this category only, or the summary and every category', function (string $query, array $pages) {
    $props = $this->actingAs($this->admin)->get('/reports/print?'.SEP.'&'.$query)->assertOk()->inertiaProps();

    expect($props['pages'])->toBe($pages)
        ->and(array_keys($props['report']['categories']))->toBe(['OPD', 'ER', 'Admission'])
        ->and($props['sheet']['hospital_name'])->toBe('AFP Medical Center');
})->with([
    'this category: opd' => ['category=opd', ['OPD']],
    'this category: er' => ['category=er', ['ER']],
    'this category: admission' => ['category=admission', ['Admission']],
    'this page: summary' => ['category=summary', ['summary']],
    'nothing chosen is ER' => ['', ['ER']],
    'all categories' => ['category=er&scope=all', ['summary', 'OPD', 'ER', 'Admission']],
    'all categories, summary tab' => ['category=summary&scope=all', ['summary', 'OPD', 'ER', 'Admission']],
]);

// --- PDF ----------------------------------------------------------------------------------------

it('puts each category on its own page in the PDF for All categories', function () {
    $html = tabPdfHtml(SEP.'&category=er&scope=all');

    // Four pages: Summary, OPD, ER, Admission. Every page after the first starts a new sheet.
    expect(substr_count($html, 'class="page"'))->toBe(4)
        ->and(substr_count($html, 'page-break-before: always'))->toBe(3)
        ->and($html)->toContain('<h2>OPD</h2>')->toContain('<h2>ER</h2>')->toContain('<h2>Admission</h2>')
        ->toContain('Total patients (all categories)');

    // The categories come after the summary, in the usual order.
    expect(strpos($html, 'Total patients (all categories)'))->toBeLessThan(strpos($html, '<h2>OPD</h2>'))
        ->and(strpos($html, '<h2>OPD</h2>'))->toBeLessThan(strpos($html, '<h2>ER</h2>'))
        ->and(strpos($html, '<h2>ER</h2>'))->toBeLessThan(strpos($html, '<h2>Admission</h2>'));
});

it('gives every PDF page its own header and signature block', function () {
    $html = tabPdfHtml(SEP.'&category=er&scope=all');

    expect(substr_count($html, 'AFP Medical Center · Admission / Administrative Office'))->toBe(4)
        ->and(substr_count($html, '<h1>Monthly Patient Census Report</h1>'))->toBe(4)
        ->and(substr_count($html, 'Prepared by · Admission / Admin Office'))->toBe(4)
        ->and(substr_count($html, 'Noted by · Commanding Officer'))->toBe(4)
        ->and(substr_count($html, '<b>Ana Reyes</b>'))->toBe(4)
        ->and(substr_count($html, '<b>COL Cruz</b>'))->toBe(4)
        ->and(substr_count($html, 'Generated '))->toBe(4);
});

it('labels each PDF page with its category', function () {
    $html = tabPdfHtml(SEP.'&category=er&scope=all');

    foreach (['Summary', 'OPD', 'ER', 'Admission'] as $label) {
        expect($html)->toContain('September 2026· '.$label);
    }
});

it('prints a single page in the PDF for This category', function (string $tab) {
    $html = tabPdfHtml(SEP.'&category='.$tab);

    expect(substr_count($html, 'class="page"'))->toBe(1)
        ->and(substr_count($html, 'page-break-before'))->toBe(0)
        ->and(substr_count($html, 'Prepared by ·'))->toBe(1);
})->with(['opd', 'er', 'admission', 'summary']);

it('sends a real PDF for every choice', function (string $query) {
    $response = $this->actingAs($this->admin)->get('/reports/export/pdf?'.SEP.'&'.$query)->assertOk();

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
})->with(['category=opd', 'category=er', 'category=admission', 'category=summary', 'category=er&scope=all', 'scope=all']);

it('follows the same signature rules on every page: pictures only when the month is closed', function () {
    // Open month: no pictures, and the note on every page.
    $open = tabPdfHtml(SEP.'&category=er&scope=all');

    expect($open)->not->toContain('<img')
        ->and(substr_count($open, 'Not final – month still open'))->toBe(4);

    // Closed month: both pictures on every page, no note.
    app(MonthService::class)->close('2026-09', $this->admin);
    $closed = tabPdfHtml(SEP.'&category=er&scope=all');

    expect(substr_count($closed, '<img src="data:image/png;base64,'))->toBe(8)
        ->and($closed)->not->toContain('Not final');

    // A custom range is never final.
    $custom = tabPdfHtml('type=custom&from=2026-09-01&to=2026-09-30&scope=all');

    expect($custom)->not->toContain('<img')
        ->and(substr_count($custom, 'Not final – custom range'))->toBe(4);
});

it('writes what was taken to the audit log: the tab, or All', function () {
    $this->actingAs($this->admin)->get('/reports/export/pdf?'.SEP.'&category=opd')->assertOk();
    $this->actingAs($this->admin)->get('/reports/export/pdf?'.SEP.'&category=summary')->assertOk();
    $this->actingAs($this->admin)->get('/reports/export/pdf?'.SEP.'&category=er&scope=all')->assertOk();
    $this->actingAs($this->admin)->get('/reports/export/excel?'.SEP.'&category=er')->assertOk();

    $categories = AuditLog::query()->where('action', 'report.exported')->orderBy('id')->get()->map(fn ($e) => $e->changes['category'])->all();

    expect($categories)->toBe(['OPD', 'Summary', 'All', 'All']);
});

// --- Excel ----------------------------------------------------------------------------------------

it('has one sheet per category and a Summary sheet, for an open and a closed month', function () {
    $names = fn () => IOFactory::load(tabExcelPath($this->actingAs($this->admin)->get('/reports/export/excel?'.SEP.'&category=er')))->getSheetNames();

    expect($names())->toBe(['Summary', 'OPD', 'ER', 'Admission']);

    app(MonthService::class)->close('2026-09', $this->admin);

    expect($names())->toBe(['Summary', 'OPD', 'ER', 'Admission']);
});

it('has the same numbers in the Summary sheet as the Summary tab', function () {
    $path = tabExcelPath($this->actingAs($this->admin)->get('/reports/export/excel?'.SEP.'&category=opd'));
    $rows = IOFactory::load($path)->getSheetByNameOrThrow('Summary')->toArray(null, false, false);
    // The first row with each label: the Patients and visits table comes first on the sheet.
    $first = fn (string $label) => collect($rows)->first(fn ($r) => ($r[0] ?? null) === $label);

    expect(array_slice($first('Total (all categories)'), 1, 2))->toBe([3, 4])
        ->and(array_slice($first('OPD'), 1, 2))->toBe([1, 1])
        ->and(array_slice($first('ER'), 1, 2))->toBe([2, 3])
        ->and(array_slice($first('Admission'), 1, 2))->toBe([0, 0]);
});

/** Save an Excel response to a temporary file and return its path (removed at the end of the test run by the OS). */
function tabExcelPath(TestResponse $response): string
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());

    return $path;
}

it('has a Year list next to Month on a monthly report, and the Month list shows only that year', function () {
    $controls = file_get_contents(resource_path('js/features/reports/report-controls.tsx'));

    expect($controls)->toContain('const monthYear = values.month.slice(0, 4)')
        ->toContain('startsWith(`${monthYear}-`)')
        ->toContain('onChange={changeMonthYear}');
});
