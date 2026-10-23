<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\HospitalSettings;
use App\Services\MonthService;
use App\Services\ReportSheetResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/*
 * Report exports: GET /reports/export/pdf and /reports/export/excel
 * (planning/mvp-scope.md section 4, planning/security.md section 2 rules 7-8).
 * Small known data, exact numbers. "Today" is 15-Oct-2026, so the default
 * report is September 2026.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $this->army = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL', 'sort_order' => 1]);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);

    AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);
});

/**
 * A visit on the given day. Army CPL, Hypertension, age 30, ER unless given.
 *
 * @param  array<string, mixed>  $attributes
 */
function exportVisit(object $test, string $when, array $attributes = [], string $category = 'ER'): Visit
{
    return Visit::factory()->create([
        'patient_id' => Patient::factory()->create()->id,
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

/** The Excel file of a response, read back. */
function workbookOf(TestResponse $response): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());

    try {
        return IOFactory::load($path);
    } finally {
        unlink($path);
    }
}

/**
 * Rows of a sheet as plain arrays of cell values (formulas are not calculated).
 *
 * @return list<list<mixed>>
 */
function sheetRows(Spreadsheet $book, string $title): array
{
    return $book->getSheetByNameOrThrow($title)->toArray(null, false, false);
}

/** The number next to a label in a sheet, e.g. "Patients" => 3. */
function valueNextTo(array $rows, string $label): mixed
{
    foreach ($rows as $row) {
        if (($row[0] ?? null) === $label) {
            return $row[1];
        }
    }

    return null;
}

// --- Content type and file name --------------------------------------------------

it('sends a PDF with the right type and file name', function (string $query, string $file) {
    exportVisit($this, '2026-09-10 09:00');

    $response = $this->actingAs($this->admin)->get('/reports/export/pdf?'.$query)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.$file.'.pdf"')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
})->with([
    // The last part is the tab (ER when none is chosen), or "all" for every page.
    'monthly' => ['type=monthly&month=2026-09', 'census-report_2026-09_er'],
    'quarterly' => ['type=quarterly&quarter=3&year=2026&category=ER', 'census-report_2026-Q3_er'],
    'annual' => ['type=annual&year=2026&category=Admission', 'census-report_2026_admission'],
    'summary tab' => ['type=monthly&month=2026-09&category=summary', 'census-report_2026-09_summary'],
    'every page' => ['type=monthly&month=2026-09&category=opd&scope=all', 'census-report_2026-09_all'],
    'custom' => ['type=custom&from=2026-09-01&to=2026-09-30', 'census-report_2026-09-01_to_2026-09-30_er'],
    'nothing chosen' => ['', 'census-report_2026-09_er'],
]);

it('sends an Excel file with the right type and file name', function (string $query, string $file) {
    exportVisit($this, '2026-09-10 09:00');

    $response = $this->actingAs($this->admin)->get('/reports/export/excel?'.$query)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.$file.'.xlsx"')
        ->and(substr($response->getContent(), 0, 2))->toBe('PK');
})->with([
    // Excel always holds every category, so its name always ends in "all".
    'monthly' => ['type=monthly&month=2026-09', 'census-report_2026-09_all'],
    'quarterly' => ['type=quarterly&quarter=3&year=2026&category=ER', 'census-report_2026-Q3_all'],
    'annual' => ['type=annual&year=2026&category=OPD', 'census-report_2026_all'],
    'custom' => ['type=custom&from=2026-09-01&to=2026-09-30', 'census-report_2026-09-01_to_2026-09-30_all'],
]);

it('falls back to the defaults for bad URL values, like the screen', function () {
    $response = $this->actingAs($this->admin)
        ->get('/reports/export/excel?type=nonsense&month=2020-01&category=ICU&from=x&to=y')
        ->assertOk();

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="census-report_2026-09_all.xlsx"');
});

// --- Excel content matches the screen ------------------------------------------------

it('has a Summary sheet and one sheet per category, with the same numbers as the screen', function () {
    exportVisit($this, '2026-09-05 09:00', category: 'ER');
    exportVisit($this, '2026-09-06 09:00', category: 'ER');
    exportVisit($this, '2026-09-07 09:00', category: 'OPD');

    $screen = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')->inertiaProps('report');

    $book = workbookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09'));

    expect($book->getSheetNames())->toBe(['Summary', 'OPD', 'ER', 'Admission', 'Chart data']);

    $summary = sheetRows($book, 'Summary');

    expect(collect($summary)->pluck(0)->all())->toContain('[Hospital Name]', 'Admission / Administrative Office', 'Monthly Patient Census Report', 'September 2026')
        ->and(valueNextTo($summary, 'Total (all categories)'))->toBe($screen['totals']['patients'])
        ->and(collect($summary)->first(fn ($r) => $r[0] === 'Total (all categories)')[2])->toBe($screen['totals']['visits']);

    foreach ($screen['categories'] as $name => $data) {
        $sheet = sheetRows($book, $name);
        $row = collect($summary)->first(fn ($r) => $r[0] === $name);

        expect($row[1])->toBe($data['patients'])
            ->and($row[2])->toBe($data['visits'])
            ->and(valueNextTo($sheet, 'Patients'))->toBe($data['patients'])
            ->and(valueNextTo($sheet, 'Visits'))->toBe($data['visits']);
    }

    // Numbers are stored as numbers, not text.
    $er = $book->getSheetByName('ER');
    $patientsRow = collect(sheetRows($book, 'ER'))->search(fn ($r) => $r[0] === 'Patients') + 1;

    expect($er->getCell('B'.$patientsRow)->getDataType())->toBe('n');
});

it('writes a Summary sheet and one sheet per category, whichever tab is open', function (string $tab) {
    exportVisit($this, '2026-09-05 09:00', category: 'ER');

    $book = workbookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09&category='.$tab));

    expect($book->getSheetNames())->toBe(['Summary', 'OPD', 'ER', 'Admission', 'Chart data'])
        ->and(collect(sheetRows($book, 'Summary'))->pluck(0)->all())->toContain('September 2026');
})->with(['opd', 'er', 'admission', 'summary', 'nonsense']);

it('lists every diagnosis in both files, not just ten', function () {
    foreach (range(1, 14) as $n) {
        $diagnosis = Diagnosis::factory()->create(['name' => sprintf('Diagnosis %02d', $n)]);
        exportVisit($this, '2026-09-10 09:00', ['diagnosis_id' => $diagnosis->id]);
    }

    $book = workbookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09&category=ER'));
    $labels = collect(sheetRows($book, 'ER'))->pluck(0)->filter(fn ($v) => is_string($v) && str_starts_with($v, 'Diagnosis '))->values();

    // 14 lines, plus the "Diagnosis (per visit)" heading.
    expect($labels->reject(fn ($v) => $v === 'Diagnosis (per visit)'))->toHaveCount(14);

    // The PDF page is built from the same report; every line is in its HTML.
    $data = app(ReportSheetResolver::class)->resolve(['type' => 'monthly', 'month' => '2026-09', 'category' => 'ER']);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now()])->render();

    foreach (range(1, 14) as $n) {
        expect($html)->toContain(sprintf('Diagnosis %02d', $n));
    }

    $this->actingAs($this->admin)->get('/reports/export/pdf?type=monthly&month=2026-09&category=ER')->assertOk();
});

it('puts the header, signatures and generated time on the sheet from the settings', function () {
    foreach ([
        'hospital_name' => 'AFP Medical Center',
        'prepared_by' => 'SSG Dela Cruz',
        'noted_by' => 'COL Reyes',
    ] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    $data = app(ReportSheetResolver::class)->resolve([]);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now()])->render();

    expect($html)->toContain('AFP Medical Center')
        ->toContain('Monthly Patient Census Report')
        ->toContain('September 2026')
        ->toContain('Prepared by · Admission / Admin Office')
        ->toContain('Noted by · Commanding Officer')
        ->toContain('SSG Dela Cruz')
        ->toContain('COL Reyes')
        ->toContain('Generated 15-Oct-2026 10:00');

    $summary = sheetRows(workbookOf($this->actingAs($this->admin)->get('/reports/export/excel')), 'Summary');

    expect(collect($summary)->pluck(0)->all())->toContain('Generated 15-Oct-2026 10:00 by '.$this->admin->name)
        ->and(collect($summary)->firstWhere(0, 'Prepared by · Admission / Admin Office')[1])->toBe('SSG Dela Cruz');
});

// --- Closed month ------------------------------------------------------------------

it('exports the saved copy of a closed month', function () {
    exportVisit($this, '2026-09-10 09:00');
    app(MonthService::class)->close('2026-09', $this->admin);

    // Added after closing (for example by a restore): not in the saved copy.
    exportVisit($this, '2026-09-11 09:00');
    $this->hypertension->update(['name' => 'High blood pressure']);

    $book = workbookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09'));
    $summary = sheetRows($book, 'Summary');
    $er = sheetRows($book, 'ER');

    expect(valueNextTo($summary, 'Total (all categories)'))->toBe(1)
        ->and(valueNextTo($er, 'Hypertension'))->toBe(1)
        ->and(valueNextTo($er, 'High blood pressure'))->toBeNull()
        ->and(collect($summary)->pluck(0)->first(fn ($v) => str_starts_with((string) $v, 'Saved copy')))->toStartWith('Saved copy, taken when September 2026 was closed on 15-Oct-2026 10:00');

    $data = app(ReportSheetResolver::class)->resolve(['type' => 'monthly', 'month' => '2026-09']);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now()])->render();

    expect($html)->toContain('Saved copy, taken when September 2026 was closed')
        ->toContain('Hypertension');
});

// --- Formula injection guard ---------------------------------------------------------

it('stores a text cell that starts with =, +, - or @ as plain text', function (string $text) {
    $diagnosis = Diagnosis::factory()->create(['name' => $text]);
    exportVisit($this, '2026-09-10 09:00', ['diagnosis_id' => $diagnosis->id]);

    $book = workbookOf($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09&category=ER'));
    $cell = collect($book->getSheetByName('ER')->getRowIterator())
        ->map(fn ($row) => $book->getSheetByName('ER')->getCell('A'.$row->getRowIndex()))
        ->first(fn ($c) => $c->getValue() === $text);

    expect($cell)->not->toBeNull()
        ->and($cell->getDataType())->toBe('s')
        ->and($cell->getValue())->toBe($text);
})->with(['=1+1', '=HYPERLINK("http://x","y")', '+SUM(A1)', '-2+3', '@SUM(A1)']);

// --- Audit -------------------------------------------------------------------------

it('writes report.exported to the audit log with the format, period and category', function () {
    $this->actingAs($this->admin)->get('/reports/export/pdf?type=quarterly&quarter=3&year=2026&category=ER')->assertOk();
    $this->actingAs($this->admin)->get('/reports/export/excel?type=custom&from=2026-09-01&to=2026-09-30')->assertOk();

    $entries = AuditLog::query()->where('action', 'report.exported')->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->user_id)->toBe($this->admin->id)
        ->and($entries[0]->changes)->toBe(['format' => 'pdf', 'period' => '2026-Q3', 'category' => 'ER'])
        ->and($entries[1]->changes)->toBe(['format' => 'excel', 'period' => '2026-09-01 to 2026-09-30', 'category' => 'All']);
});

it('writes no audit entry when the download is refused', function () {
    $this->actingAs($this->encoder)->get('/reports/export/pdf')->assertForbidden();

    expect(AuditLog::query()->where('action', 'report.exported')->count())->toBe(0);
});

// --- Files are not kept --------------------------------------------------------------

it('leaves no export file in public or storage', function () {
    $before = fn () => collect([public_path(), storage_path('app')])
        ->flatMap(fn ($dir) => File::allFiles($dir))
        ->map(fn ($f) => $f->getPathname())
        ->sort()->values()->all();

    $files = $before();

    $this->actingAs($this->admin)->get('/reports/export/pdf')->assertOk();
    $this->actingAs($this->admin)->get('/reports/export/excel')->assertOk();

    expect($before())->toBe($files);
});

// --- Access --------------------------------------------------------------------------

it('lets Admin and Viewer download both files', function (string $role, string $route) {
    $this->actingAs(userWithRole($role))->get($route)->assertOk();
})->with(['admin', 'viewer'])->with(['/reports/export/pdf', '/reports/export/excel']);

it('refuses both files to Encoder and System Admin', function (string $role, string $route) {
    $this->actingAs(userWithRole($role))->get($route)->assertForbidden();
})->with(['encoder', 'system_admin'])->with(['/reports/export/pdf', '/reports/export/excel']);

it('sends a signed-out visitor to the login page', function (string $route) {
    $this->get($route)->assertRedirect('/login');
})->with(['/reports/export/pdf', '/reports/export/excel']);

// --- PDF safety ------------------------------------------------------------------------

it('keeps dompdf remote resources and PHP execution off', function () {
    expect(config('dompdf.options.enable_remote'))->toBeFalse()
        ->and(config('dompdf.options.enable_php'))->toBeFalse();

    // The controller also sets both when it builds the PDF.
    $source = file_get_contents(app_path('Http/Controllers/Reports/ReportExportController.php'));

    expect($source)->toContain("'isRemoteEnabled' => false")->toContain("'isPhpEnabled' => false");
});

// --- Presentable workbook (Step 7d) -------------------------------------------------

/** The row number of the first row whose column A holds a text, on a sheet. */
function rowOf(Spreadsheet $book, string $sheet, string $text): int
{
    $worksheet = $book->getSheetByNameOrThrow($sheet);

    foreach ($worksheet->toArray(null, false, false) as $index => $cells) {
        if (($cells[0] ?? null) === $text) {
            return $index + 1;
        }
    }

    throw new RuntimeException("No cell with \"{$text}\" on {$sheet}.");
}

function exportBook(object $test, string $query = 'type=monthly&month=2026-09'): Spreadsheet
{
    return workbookOf($test->actingAs($test->admin)->get('/reports/export/excel?'.$query)->assertOk());
}

// The sections of each page and their figures are checked against a hand count, on screen,
// in the PDF and in Excel, in ReportContentTest. Here: the look of the workbook.

it('puts the title block on every sheet', function (string $sheet) {
    Setting::query()->updateOrCreate(['key' => 'hospital_name'], ['value' => 'AFP Medical Center']);
    exportVisit($this, '2026-09-05 09:00');

    $labels = collect(sheetRows(exportBook($this), $sheet))->pluck(0)->filter()->values();
    $title = $sheet === 'Summary' ? 'Monthly Patient Census Report' : 'Monthly Patient Census Report – '.$sheet;

    expect($labels->take(6)->all())->toBe([
        'AFP Medical Center',
        'Admission / Administrative Office',
        $title,
        'September 2026',
        'Not final – month still open',
        'Generated 15-Oct-2026 10:00 by '.$this->admin->name,
    ]);
})->with(['Summary', 'OPD', 'ER', 'Admission']);

it('says Final for a closed month and shows the other period labels', function () {
    exportVisit($this, '2026-09-05 09:00');
    app(MonthService::class)->close('2026-09', $this->admin);

    expect(collect(sheetRows(exportBook($this), 'ER'))->pluck(0))->toContain('Final')
        ->and(collect(sheetRows(exportBook($this, 'type=quarterly&year=2026&quarter=3'), 'ER'))->pluck(0)->all())->toContain('Q3 2026 (Jul–Sep)')
        ->and(collect(sheetRows(exportBook($this, 'type=custom&from=2026-10-01&to=2026-10-15'), 'ER'))->pluck(0)->all())
        ->toContain('01-Oct-2026 to 15-Oct-2026', 'Not final – custom range');
});

it('writes Prepared by and Noted by at the bottom of every sheet, as text only', function (string $sheet) {
    Setting::query()->updateOrCreate(['key' => 'prepared_by'], ['value' => 'SSG Dela Cruz']);
    Setting::query()->updateOrCreate(['key' => 'noted_by'], ['value' => 'COL Reyes']);

    $book = exportBook($this);
    $worksheet = $book->getSheetByNameOrThrow($sheet);
    $prepared = rowOf($book, $sheet, 'Prepared by · Admission / Admin Office');
    $noted = rowOf($book, $sheet, 'Noted by · Commanding Officer');

    expect($worksheet->getCell('B'.$prepared)->getValue())->toBe('SSG Dela Cruz')
        ->and($worksheet->getCell('B'.$noted)->getValue())->toBe('COL Reyes')
        ->and($noted)->toBe($worksheet->getHighestDataRow())
        // The signature line over the name.
        ->and($worksheet->getStyle('B'.$noted)->getBorders()->getTop()->getBorderStyle())->toBe('thin')
        ->and($worksheet->getDrawingCollection())->toHaveCount(0);
})->with(['Summary', 'OPD', 'ER', 'Admission']);

it('styles the tables: navy bold header, borders, zebra, bold totals, number format', function () {
    exportVisit($this, '2026-09-05 09:00');
    $book = exportBook($this);
    $er = $book->getSheetByNameOrThrow('ER');

    $header = 'A'.rowOf($book, 'ER', 'Branch / Rank');
    $visits = rowOf($book, 'ER', 'Visits');
    $total = 'A'.rowOf($book, 'ER', 'Total');
    $first = 'A'.rowOf($book, 'ER', 'Patients');

    expect($er->getStyle($header)->getFont()->getBold())->toBeTrue()
        ->and($er->getStyle($header)->getFont()->getColor()->getRGB())->toBe('FFFFFF')
        ->and($er->getStyle($header)->getFill()->getStartColor()->getRGB())->toBe('1F3864')
        ->and($er->getStyle($header)->getBorders()->getBottom()->getBorderStyle())->toBe('thin')
        // Zebra: the second row of a table is shaded, the first is not.
        ->and($er->getStyle('A'.$visits)->getFill()->getStartColor()->getRGB())->toBe('F2F5FA')
        ->and($er->getStyle($first)->getFill()->getFillType())->toBe('none')
        ->and($er->getStyle($total)->getFont()->getBold())->toBeTrue()
        ->and($er->getStyle('B'.$visits)->getNumberFormat()->getFormatCode())->toBe('#,##0')
        ->and($er->getStyle('B'.$visits)->getAlignment()->getHorizontal())->toBe('right')
        ->and($er->getStyle('A'.$visits)->getAlignment()->getHorizontal())->toBe('left')
        ->and($er->getStyle('A'.$visits)->getAlignment()->getWrapText())->toBeTrue();

    $title = 'A'.rowOf($book, 'ER', '[Hospital Name]');
    expect($er->getStyle($title)->getFont()->getBold())->toBeTrue()
        ->and($er->getStyle($title)->getFont()->getSize())->toBeGreaterThan(12);
});

it('sets up the page: A4, one page wide, 1.5 cm margins, footer, frozen title block', function (string $sheet) {
    Setting::query()->updateOrCreate(['key' => 'hospital_name'], ['value' => 'AFP Medical Center']);
    $worksheet = exportBook($this)->getSheetByNameOrThrow($sheet);
    $setup = $worksheet->getPageSetup();

    expect($setup->getPaperSize())->toBe(PageSetup::PAPERSIZE_A4)
        ->and($setup->getOrientation())->toBe('portrait')
        ->and($setup->getFitToPage())->toBeTrue()
        ->and($setup->getFitToWidth())->toBe(1)
        ->and($setup->getFitToHeight())->toBe(0)
        ->and(round($worksheet->getPageMargins()->getLeft() * 2.54, 2))->toBe(1.5)
        ->and(round($worksheet->getPageMargins()->getBottom() * 2.54, 2))->toBe(1.5)
        ->and($worksheet->getHeaderFooter()->getOddFooter())->toBe('&LAFP Medical Center&RPage &P of &N')
        ->and($worksheet->getFreezePane())->toBe('A8')
        ->and($setup->isRowsToRepeatAtTopSet())->toBeTrue();
})->with(['Summary', 'OPD', 'ER', 'Admission']);

it('draws the logo on every sheet when one is set, and none when not', function () {
    Storage::fake('local');
    $without = exportBook($this);

    app(HospitalSettings::class)->storeImage('logo', UploadedFile::fake()->image('logo.png', 200, 100));
    $with = exportBook($this);

    foreach (['Summary', 'OPD', 'ER', 'Admission'] as $sheet) {
        expect($without->getSheetByNameOrThrow($sheet)->getDrawingCollection())->toHaveCount(0)
            ->and($with->getSheetByNameOrThrow($sheet)->getDrawingCollection())->toHaveCount(1)
            ->and($with->getSheetByNameOrThrow($sheet)->getDrawingCollection()[0]->getCoordinates())->toBe('A1');
    }

    // The signature pictures never go into Excel.
    app(HospitalSettings::class)->storeImage('prepared_signature', UploadedFile::fake()->image('s.png', 200, 100));

    expect(exportBook($this)->getSheetByNameOrThrow('ER')->getDrawingCollection())->toHaveCount(1);
});

it('has no formula in any cell', function () {
    $diagnosis = Diagnosis::factory()->create(['name' => '=1+1']);
    exportVisit($this, '2026-09-05 09:00', ['diagnosis_id' => $diagnosis->id]);

    foreach (exportBook($this)->getAllSheets() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                expect($cell->isFormula())->toBeFalse();
            }
        }
    }
});
