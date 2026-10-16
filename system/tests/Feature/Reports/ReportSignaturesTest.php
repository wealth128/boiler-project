<?php

use App\Models\Setting;
use App\Services\HospitalSettings;
use App\Services\MonthService;
use App\Services\ReportSheetResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * Signature pictures show only on a final report: a closed month, or a
 * quarter or year whose months are all closed. Open months and custom
 * ranges keep the names and titles over a blank line, with a small note.
 * Excel never has pictures. "Today" is 15-Oct-2026.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');

    foreach (['hospital_name' => 'AFP Medical Center', 'prepared_by' => 'Ana Reyes', 'noted_by' => 'COL Cruz'] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    $hospital = app(HospitalSettings::class);
    $hospital->storeImage('prepared_signature', UploadedFile::fake()->image('a.png', 300, 100));
    $hospital->storeImage('noted_signature', UploadedFile::fake()->image('b.png', 300, 100));
});

/** Close the given months ("2026-07"...) as the Admin. */
function closeMonths(object $test, string ...$periods): void
{
    foreach ($periods as $period) {
        app(MonthService::class)->close($period, $test->admin);
    }
}

/**
 * The sheet of a report screen.
 *
 * @return array<string, mixed>
 */
function sheetFor(object $test, string $query, string $role = 'admin'): array
{
    $user = $role === 'admin' ? $test->admin : userWithRole($role);

    return $test->actingAs($user)->get('/reports?'.$query)->assertOk()->inertiaProps('sheet');
}

const NOT_FINAL_OPEN = 'Not final – month still open';
const NOT_FINAL_CUSTOM = 'Not final – custom range';

// --- Screen (and so Print) -----------------------------------------------------------

it('shows the signature pictures for a closed month', function () {
    closeMonths($this, '2026-09');

    $sheet = sheetFor($this, 'type=monthly&month=2026-09');

    expect($sheet['signatures_final'])->toBeTrue()
        ->and($sheet['signature_note'])->toBeNull()
        ->and($sheet['prepared_signature_url'])->toStartWith('/reports/signatures/2026-09/prepared?v=')
        ->and($sheet['noted_signature_url'])->toStartWith('/reports/signatures/2026-09/noted?v=');
});

it('shows the signature pictures for a quarter when every month in it is closed', function () {
    closeMonths($this, '2026-07', '2026-08', '2026-09');

    $sheet = sheetFor($this, 'type=quarterly&quarter=3&year=2026');

    expect($sheet['signatures_final'])->toBeTrue()
        ->and($sheet['signature_note'])->toBeNull()
        ->and($sheet['prepared_signature_url'])->not->toBeNull()
        ->and($sheet['noted_signature_url'])->not->toBeNull();
});

it('hides the pictures for an open month and shows the note with the names and titles', function () {
    $sheet = sheetFor($this, 'type=monthly&month=2026-09');

    expect($sheet['signatures_final'])->toBeFalse()
        ->and($sheet['signature_note'])->toBe(NOT_FINAL_OPEN)
        ->and($sheet['prepared_signature_url'])->toBeNull()
        ->and($sheet['noted_signature_url'])->toBeNull()
        ->and($sheet['prepared_by'])->toBe('Ana Reyes')
        ->and($sheet['noted_by'])->toBe('COL Cruz')
        ->and($sheet['prepared_by_title'])->toBe('Admission / Admin Office')
        ->and($sheet['noted_by_title'])->toBe('Commanding Officer');
});

it('hides the pictures for the current month, which is still running', function () {
    $sheet = sheetFor($this, 'type=monthly&month=2026-10');

    expect($sheet['signature_note'])->toBe(NOT_FINAL_OPEN)
        ->and($sheet['prepared_signature_url'])->toBeNull();
});

it('hides the pictures for a quarter with one open month', function () {
    closeMonths($this, '2026-07', '2026-09');

    $sheet = sheetFor($this, 'type=quarterly&quarter=3&year=2026');

    expect($sheet['signatures_final'])->toBeFalse()
        ->and($sheet['signature_note'])->toBe(NOT_FINAL_OPEN)
        ->and($sheet['prepared_signature_url'])->toBeNull()
        ->and($sheet['noted_signature_url'])->toBeNull();
});

it('hides the pictures for a year while any month is open', function () {
    closeMonths($this, '2026-01', '2026-02', '2026-03');

    $sheet = sheetFor($this, 'type=annual&year=2026');

    expect($sheet['signature_note'])->toBe(NOT_FINAL_OPEN)
        ->and($sheet['prepared_signature_url'])->toBeNull();
});

it('hides the pictures for a custom range, even over a closed month', function () {
    closeMonths($this, '2026-09');

    $sheet = sheetFor($this, 'type=custom&from=2026-09-01&to=2026-09-30');

    expect($sheet['signatures_final'])->toBeFalse()
        ->and($sheet['signature_note'])->toBe(NOT_FINAL_CUSTOM)
        ->and($sheet['prepared_signature_url'])->toBeNull()
        ->and($sheet['noted_signature_url'])->toBeNull()
        ->and($sheet['prepared_by'])->toBe('Ana Reyes');
});

it('shows the pictures again after a closed month is reopened and hides them', function () {
    closeMonths($this, '2026-09');
    expect(sheetFor($this, 'type=monthly&month=2026-09')['prepared_signature_url'])->not->toBeNull();

    app(MonthService::class)->reopen('2026-09', $this->admin);

    $sheet = sheetFor($this, 'type=monthly&month=2026-09');

    expect($sheet['prepared_signature_url'])->toBeNull()
        ->and($sheet['signature_note'])->toBe(NOT_FINAL_OPEN);
});

it('applies the same rule for the Viewer (CO)', function () {
    closeMonths($this, '2026-09');

    expect(sheetFor($this, 'type=monthly&month=2026-09', 'viewer')['prepared_signature_url'])->not->toBeNull()
        ->and(sheetFor($this, 'type=monthly&month=2026-08', 'viewer')['signature_note'])->toBe(NOT_FINAL_OPEN);
});

it('has no pictures at all when none are saved, and still shows the note on an open month', function () {
    app(HospitalSettings::class)->removeImage('prepared_signature');
    app(HospitalSettings::class)->removeImage('noted_signature');
    closeMonths($this, '2026-09');

    $closed = sheetFor($this, 'type=monthly&month=2026-09');
    $open = sheetFor($this, 'type=monthly&month=2026-08');

    expect($closed['prepared_signature_url'])->toBeNull()->and($closed['signature_note'])->toBeNull()
        ->and($open['signature_note'])->toBe(NOT_FINAL_OPEN);
});

// --- PDF -------------------------------------------------------------------------------

/** The PDF page's HTML for a report, built the way the PDF controller builds it. */
function pdfHtml(string $query): string
{
    parse_str($query, $params);

    $data = app(ReportSheetResolver::class)->resolve($params);

    return view('reports.pdf', [
        ...$data,
        'generatedAt' => now(),
        'logoData' => null,
        'signatureData' => app(ReportSheetResolver::class)->signatureData($data),
    ])->render();
}

it('puts the signature pictures in the PDF of a closed month and no note', function () {
    closeMonths($this, '2026-09');

    $this->actingAs($this->admin)->get('/reports/export/pdf?type=monthly&month=2026-09')->assertOk();

    $html = pdfHtml('type=monthly&month=2026-09');

    expect(substr_count($html, '<img src="data:image/png;base64,'))->toBe(2)
        ->and($html)->not->toContain('Not final');
});

it('puts the pictures in the PDF of a quarter with all months closed', function () {
    closeMonths($this, '2026-07', '2026-08', '2026-09');

    expect(substr_count(pdfHtml('type=quarterly&quarter=3&year=2026'), '<img src="data:image/png;base64,'))->toBe(2);
});

it('leaves the pictures out of the PDF and prints the note for an open month, a one-open quarter and a custom range', function (string $query, string $note) {
    closeMonths($this, '2026-07', '2026-09');

    $this->actingAs($this->admin)->get('/reports/export/pdf?'.$query)->assertOk();

    $html = pdfHtml($query);

    expect($html)->not->toContain('<img')
        ->toContain($note)
        ->toContain('Ana Reyes')->toContain('Prepared by · Admission / Admin Office')
        ->toContain('COL Cruz')->toContain('Noted by · Commanding Officer');
})->with([
    'open month' => ['type=monthly&month=2026-08', NOT_FINAL_OPEN],
    'quarter with an open month' => ['type=quarterly&quarter=3&year=2026', NOT_FINAL_OPEN],
    'custom range' => ['type=custom&from=2026-09-01&to=2026-09-30', NOT_FINAL_CUSTOM],
]);

// --- Excel -----------------------------------------------------------------------------

it('never puts pictures in the Excel file, even for a final report', function () {
    closeMonths($this, '2026-09');

    $response = $this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());

    try {
        $book = IOFactory::load($path);

        foreach ($book->getAllSheets() as $sheet) {
            expect($sheet->getDrawingCollection())->toHaveCount(0);
        }
    } finally {
        unlink($path);
    }
});
