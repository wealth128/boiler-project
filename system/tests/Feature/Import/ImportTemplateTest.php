<?php

use App\Models\Branch;
use App\Models\Diagnosis;
use App\Services\Import\ImportColumns;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\ImportScenario;

/*
 * The downtime template (GET /records/import/template): four sheets, the
 * header row of the import, dropdowns from the lists as they are today,
 * and the file name. Times are Asia/Manila.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));
    $this->lists = ImportScenario::lists();
    $this->admin = userWithRole('admin');
});

/** Download the template as Admin and open it. */
function downloadedTemplate(object $test): Spreadsheet
{
    $response = $test->actingAs($test->admin)->get('/records/import/template')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, $response->getContent());

    return IOFactory::load($path);
}

/** One column of a sheet below its header, without the empty cells. */
function sheetColumn(Spreadsheet $book, string $sheet, string $column): array
{
    $ws = $book->getSheetByName($sheet);
    $values = [];

    for ($row = 2; $row <= $ws->getHighestRow(); $row++) {
        $value = $ws->getCell("{$column}{$row}")->getValue();

        if ($value !== null && $value !== '') {
            $values[] = $value;
        }
    }

    return $values;
}

it('downloads an .xlsx named with today\'s date, never cached', function () {
    $response = $this->actingAs($this->admin)->get('/records/import/template');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="census-downtime-template_2026-10-09.xlsx"');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('has the sheets How to fill, Visits, Example and Lists', function () {
    expect(downloadedTemplate($this)->getSheetNames())->toBe(['How to fill', 'Visits', 'Example', 'Lists']);
});

it('has the import header row on Visits and Example, ending each required column with a star', function () {
    $book = downloadedTemplate($this);
    $header = fn (string $sheet) => array_values(array_filter($book->getSheetByName($sheet)->rangeToArray('A1:Q1')[0]));

    expect($header('Visits'))->toBe(ImportColumns::headers())
        ->and($header('Example'))->toBe(ImportColumns::headers())
        ->and($header('Visits'))->toContain('Visit Date *', 'Written by *', 'Patient ID', 'Age')
        ->and($book->getSheetByName('Visits')->getFreezePane())->toBe('A2');
});

it('copies the current active lists into the Lists sheet', function () {
    $this->lists['navy']->update(['is_active' => false]);
    $this->lists['sgt']->moveToTrash($this->admin, 'No longer used');
    $this->lists['dengue']->moveToTrash($this->admin, 'No longer used');
    Diagnosis::factory()->create(['name' => 'Asthma']);
    Branch::factory()->create(['name' => 'Air Force', 'is_afp' => true, 'sort_order' => 3]);

    $book = downloadedTemplate($this);

    expect(sheetColumn($book, 'Lists', 'A'))->toBe(['Male', 'Female'])
        ->and(sheetColumn($book, 'Lists', 'B'))->toBe(['OPD', 'ER', 'Admission'])
        ->and(sheetColumn($book, 'Lists', 'C'))->toBe(['Army', 'Air Force', 'Others'])
        // Alphabetical, "Other (specify)" last; nothing in Trash.
        ->and(sheetColumn($book, 'Lists', 'D'))->toBe(['Asthma', 'Hypertension', 'Other (specify)'])
        // Every rank once, branch by branch (Navy is off, SGT is in Trash).
        ->and(sheetColumn($book, 'Lists', 'E'))->toBe(['CPL', 'Dependent', 'Other (specify)']);
});

it('lists the ranks of each branch in seniority order with Other (specify) last', function () {
    $book = downloadedTemplate($this);
    $ws = $book->getSheetByName('Lists');

    expect($ws->getCell('G1')->getValue())->toBe('Ranks: Army')
        // SGT was created first but CPL has the lower sort order here.
        ->and(sheetColumn($book, 'Lists', 'G'))->toBe(['CPL', 'SGT'])
        ->and($ws->getCell('H1')->getValue())->toBe('Ranks: Navy')
        ->and(sheetColumn($book, 'Lists', 'H'))->toBe(['ENS'])
        ->and($ws->getCell('I1')->getValue())->toBe('Ranks: Others')
        ->and(sheetColumn($book, 'Lists', 'I'))->toBe(['Dependent', 'Other (specify)']);
});

it('puts dropdowns on Sex, Branch, Rank / Type, Diagnosis and Category that read the Lists sheet', function () {
    $ws = downloadedTemplate($this)->getSheetByName('Visits');
    $validations = [];

    foreach ($ws->getDataValidationCollection() as $range => $validation) {
        $validations[$range] = [$validation->getType(), $validation->getFormula1()];
    }

    // Lists: A Sex (2), B Category (3), C Branch (3), D Diagnosis (3), E Rank / Type (all: CPL, SGT, ENS, Dependent, Other).
    expect($validations)->toBe([
        'G2:G2001' => ['list', '=Lists!$A$2:$A$3'],
        'O2:O2001' => ['list', '=Lists!$B$2:$B$4'],
        'J2:J2001' => ['list', '=Lists!$C$2:$C$4'],
        'M2:M2001' => ['list', '=Lists!$D$2:$D$4'],
        'K2:K2001' => ['list', '=Lists!$E$2:$E$6'],
    ]);
});

it('formats dates as YYYY-MM-DD and the other columns as text, for every row of the column', function () {
    $book = downloadedTemplate($this);
    $ws = $book->getSheetByName('Visits');
    $code = fn (string $column): string => $book->getCellXfByIndex($ws->getColumnDimension($column)->getXfIndex())->getNumberFormat()->getFormatCode();

    // Set on the whole column, so row 2 and row 2,001 (and any row between) are the same.
    expect($code('A'))->toBe('yyyy-mm-dd')
        ->and($code('H'))->toBe('yyyy-mm-dd')
        ->and($code('I'))->toBe('0')
        ->and($code('B'))->toBe('@')
        ->and($code('C'))->toBe('@')
        ->and($code('Q'))->toBe('@');
});

it('formats the sample values of the Example sheet too', function () {
    $ws = downloadedTemplate($this)->getSheetByName('Example');

    expect($ws->getStyle('A2')->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd')
        ->and($ws->getStyle('H5')->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd')
        ->and($ws->getStyle('C3')->getNumberFormat()->getFormatCode())->toBe('@');
});
it('has four example rows made from the lists', function () {
    $book = downloadedTemplate($this);
    $ws = $book->getSheetByName('Example');

    expect(sheetColumn($book, 'Example', 'D'))->toBe(['DELA CRUZ', 'SANTOS', 'REYES', 'GARCIA'])
        ->and($ws->getCell('J2')->getValue())->toBe('Army')
        ->and($ws->getCell('K2')->getValue())->toBe('CPL')
        ->and($ws->getCell('M2')->getValue())->toBe('Dengue Fever')
        ->and($ws->getCell('M3')->getValue())->toBe('Other (specify)')
        ->and($ws->getCell('N3')->getValue())->toBe('Migraine')
        ->and($ws->getCell('L4')->getValue())->toBe('Retired PNP');
});

it('says how to fill it and when the lists were copied', function () {
    $ws = downloadedTemplate($this)->getSheetByName('How to fill');
    $text = collect($ws->toArray(null, false, false, false))->flatten()->filter()->implode("\n");

    expect($text)->toContain('PATIENT VISITS')
        ->toContain('YYYY-MM-DD')
        ->toContain('Written by')
        ->toContain('save as CSV UTF-8')
        ->toContain('October 9, 2026');
});

it('writes a list name that starts with = as text, never as a formula', function () {
    Diagnosis::factory()->create(['name' => '=1+1']);

    $ws = downloadedTemplate($this)->getSheetByName('Lists');
    $types = [];

    for ($row = 2; $row <= $ws->getHighestRow(); $row++) {
        $cell = $ws->getCell("D{$row}");
        $types[(string) $cell->getValue()] = $cell->getDataType();
    }

    expect($types)->toHaveKey('=1+1')->and($types['=1+1'])->toBe('s');
});

it('builds the template from an empty system without failing', function () {
    foreach (App\Models\Diagnosis::all() as $diagnosis) {
        $diagnosis->forceDelete();
    }

    expect(downloadedTemplate($this)->getSheetNames())->toBe(['How to fill', 'Visits', 'Example', 'Lists']);
});
