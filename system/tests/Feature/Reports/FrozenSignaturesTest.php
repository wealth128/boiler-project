<?php

use App\Models\ReportSnapshot;
use App\Services\HospitalSettings;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use App\Services\ReportSheetResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
 * The signature block is frozen when a month is closed: names, titles and a
 * private copy of the two signature pictures are saved with the month's
 * snapshot. A closed month keeps showing them even after Settings change.
 * Reopening removes them with the snapshot. Quarters and years, and months
 * closed before the block was kept, use the current settings.
 * "Today" is 15-Oct-2026.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');
    $this->hospital = app(HospitalSettings::class);

    useSignatures($this, 'Ana Reyes', 'COL Cruz', 300, 100);
});

/**
 * Put these names and two signature pictures (of the given size) in Settings.
 */
function useSignatures(object $test, string $prepared, string $noted, int $width, int $height): void
{
    $test->hospital->save([
        'hospital_name' => 'AFP Medical Center',
        'prepared_by' => $prepared,
        'prepared_by_title' => 'Admission Clerk',
        'noted_by' => $noted,
        'noted_by_title' => 'Chief of Hospital',
    ]);

    $test->hospital->storeImage('prepared_signature', UploadedFile::fake()->image('a.png', $width, $height));
    $test->hospital->storeImage('noted_signature', UploadedFile::fake()->image('b.png', $width, $height));
}

function closeMonthsFor(object $test, string ...$periods): void
{
    foreach ($periods as $period) {
        app(MonthService::class)->close($period, $test->admin);
    }
}

/** @return array<string, mixed> */
function frozenSheet(object $test, string $query): array
{
    return $test->actingAs($test->admin)->get('/reports?'.$query)->assertOk()->inertiaProps('sheet');
}

/** The PDF page's HTML, built the way the PDF controller builds it. */
function frozenPdfHtml(string $query): string
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

const SEPTEMBER = 'type=monthly&month=2026-09';

// --- Closing freezes the block --------------------------------------------------------

it('saves the names, titles and picture copies with the snapshot when a month is closed', function () {
    closeMonthsFor($this, '2026-09');

    $block = ReportSnapshot::query()->where('period', '2026-09')->sole()->signature_block;

    // MySQL JSON does not keep the key order, so compare without the order.
    expect($block)->toEqual([
        'prepared_by' => 'Ana Reyes',
        'prepared_by_title' => 'Admission Clerk',
        'noted_by' => 'COL Cruz',
        'noted_by_title' => 'Chief of Hospital',
        'prepared_signature' => 'prepared.png',
        'noted_signature' => 'noted.png',
    ])->and(Storage::disk('local')->exists('frozen-signatures/2026-09/prepared.png'))->toBeTrue()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-09/noted.png'))->toBeTrue()
        ->and(file_exists(public_path('frozen-signatures')))->toBeFalse()
        ->and(Storage::disk('local')->path('frozen-signatures'))->not->toStartWith(public_path());
});

it('does not change any report number', function () {
    closeMonthsFor($this, '2026-09');

    $snapshot = ReportSnapshot::query()->where('period', '2026-09')->sole();

    expect($snapshot->data)->toEqual(app(ReportBuilder::class)->build('2026-09'))
        ->and(array_keys($snapshot->data))->not->toContain('signature_block');
});

it('keeps the old names and picture on the closed month after Settings change (screen)', function () {
    closeMonthsFor($this, '2026-09');
    $oldNoted = Storage::disk('local')->get('frozen-signatures/2026-09/noted.png');

    useSignatures($this, 'Ana Reyes', 'BGEN New Person', 200, 80);

    $sheet = frozenSheet($this, SEPTEMBER);

    expect($sheet['noted_by'])->toBe('COL Cruz')
        ->and($sheet['signatures_final'])->toBeTrue()
        ->and($sheet['noted_signature_url'])->toStartWith('/reports/signatures/2026-09/noted?v=')
        ->and($sheet['prepared_signature_url'])->toStartWith('/reports/signatures/2026-09/prepared?v=')
        // The picture behind that address is the old one, not the new one.
        ->and(Storage::disk('local')->get('frozen-signatures/2026-09/noted.png'))->toBe($oldNoted)
        ->and(Storage::disk('local')->get('signature-noted.png'))->not->toBe($oldNoted);

    $this->actingAs($this->admin)->get($sheet['noted_signature_url'])->assertOk();
    expect($this->actingAs($this->admin)->get($sheet['noted_signature_url'])->streamedContent())->toBe($oldNoted);
});

it('keeps the old names, titles and picture in the PDF of the closed month', function () {
    closeMonthsFor($this, '2026-09');
    $oldNoted = Storage::disk('local')->get('frozen-signatures/2026-09/noted.png');

    useSignatures($this, 'New Preparer', 'BGEN New Person', 200, 80);
    $this->hospital->save(['noted_by_title' => 'Deputy Commander', 'prepared_by' => 'New Preparer']);

    $this->actingAs($this->admin)->get('/reports/export/pdf?'.SEPTEMBER)->assertOk();

    $html = frozenPdfHtml(SEPTEMBER);

    expect($html)->toContain('COL Cruz')->toContain('Noted by · Chief of Hospital')
        ->toContain('Ana Reyes')
        ->not->toContain('BGEN New Person')->not->toContain('Deputy Commander')->not->toContain('New Preparer')
        ->toContain(base64_encode($oldNoted))
        ->not->toContain(base64_encode(Storage::disk('local')->get('signature-noted.png')));
});

it('keeps the frozen names in the Excel file of the closed month too', function () {
    closeMonthsFor($this, '2026-09');
    useSignatures($this, 'New Preparer', 'BGEN New Person', 200, 80);

    expect(frozenSheet($this, SEPTEMBER))->toMatchArray(['prepared_by' => 'Ana Reyes', 'noted_by' => 'COL Cruz']);
});

it('shows the new names and pictures after the month is reopened and closed again', function () {
    closeMonthsFor($this, '2026-09');
    useSignatures($this, 'New Preparer', 'BGEN New Person', 200, 80);

    app(MonthService::class)->reopen('2026-09', $this->admin);
    closeMonthsFor($this, '2026-09');

    $sheet = frozenSheet($this, SEPTEMBER);

    expect($sheet['noted_by'])->toBe('BGEN New Person')
        ->and($sheet['prepared_by'])->toBe('New Preparer')
        ->and(Storage::disk('local')->get('frozen-signatures/2026-09/noted.png'))
        ->toBe(Storage::disk('local')->get('signature-noted.png'));

    expect(frozenPdfHtml(SEPTEMBER))->toContain('BGEN New Person')->not->toContain('COL Cruz');
});

it('removes the frozen copy with the snapshot when the month is reopened', function () {
    closeMonthsFor($this, '2026-09');

    app(MonthService::class)->reopen('2026-09', $this->admin);

    expect(ReportSnapshot::query()->where('period', '2026-09')->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-09/prepared.png'))->toBeFalse()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-09/noted.png'))->toBeFalse()
        ->and(Storage::disk('local')->directories('frozen-signatures'))->toBe([]);
});

it('keeps the copies of one month apart from another month', function () {
    closeMonthsFor($this, '2026-08');
    useSignatures($this, 'Ana Reyes', 'BGEN New Person', 200, 80);
    closeMonthsFor($this, '2026-09');

    app(MonthService::class)->reopen('2026-09', $this->admin);

    expect(frozenSheet($this, 'type=monthly&month=2026-08')['noted_by'])->toBe('COL Cruz')
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-08/noted.png'))->toBeTrue();
});

it('freezes "no picture" too: a signature added later does not appear on the closed month', function () {
    $this->hospital->removeImage('prepared_signature');
    $this->hospital->removeImage('noted_signature');
    closeMonthsFor($this, '2026-09');

    useSignatures($this, 'Ana Reyes', 'COL Cruz', 200, 80);

    $sheet = frozenSheet($this, SEPTEMBER);

    expect($sheet['prepared_signature_url'])->toBeNull()
        ->and($sheet['noted_signature_url'])->toBeNull()
        ->and($sheet['prepared_by'])->toBe('Ana Reyes')
        // No pictures at close: nothing was copied, and nothing is made later.
        ->and(Storage::disk('local')->directories('frozen-signatures'))->toBe([]);
});

// --- Older months and longer periods ------------------------------------------------------

it('uses the current settings for a month closed before the block was kept', function () {
    closeMonthsFor($this, '2026-09');

    // As if it was closed before this change: no frozen block.
    ReportSnapshot::query()->where('period', '2026-09')->update(['signature_block' => null]);

    useSignatures($this, 'Ana Reyes', 'BGEN New Person', 200, 80);

    $sheet = frozenSheet($this, SEPTEMBER);

    expect($sheet['noted_by'])->toBe('BGEN New Person')
        ->and($sheet['noted_signature_url'])->toStartWith('/settings/hospital/signature/noted?v=')
        ->and(frozenPdfHtml(SEPTEMBER))->toContain('BGEN New Person');
});

it('uses the block frozen with the quarter snapshot for a quarter with all months closed', function () {
    // Step 6x: the quarter has its own snapshot, saved when its last month closed
    // (PeriodSnapshotsTest covers it in full).
    closeMonthsFor($this, '2026-07', '2026-08', '2026-09');

    useSignatures($this, 'Ana Reyes', 'BGEN New Person', 200, 80);

    $sheet = frozenSheet($this, 'type=quarterly&quarter=3&year=2026');

    expect($sheet['signatures_final'])->toBeTrue()
        ->and($sheet['noted_by'])->toBe('COL Cruz')
        ->and($sheet['noted_signature_url'])->toStartWith('/reports/signatures/2026-Q3/noted?v=')
        ->and(frozenPdfHtml('type=quarterly&quarter=3&year=2026'))->toContain('COL Cruz')->not->toContain('BGEN New Person');
});

it('shows the current names (no picture) on an open month and a custom range', function () {
    closeMonthsFor($this, '2026-09');
    useSignatures($this, 'Ana Reyes', 'BGEN New Person', 200, 80);

    $open = frozenSheet($this, 'type=monthly&month=2026-08');
    $custom = frozenSheet($this, 'type=custom&from=2026-09-01&to=2026-09-30');

    expect($open['noted_by'])->toBe('BGEN New Person')->and($open['noted_signature_url'])->toBeNull()
        ->and($custom['noted_by'])->toBe('BGEN New Person')->and($custom['noted_signature_url'])->toBeNull();
});

// --- Who can load the frozen pictures --------------------------------------------------------

it('lets Admin and Viewer (CO) load the frozen pictures', function (string $role) {
    closeMonthsFor($this, '2026-09');

    $this->actingAs(userWithRole($role))->get('/reports/signatures/2026-09/prepared')->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(userWithRole($role))->get('/reports/signatures/2026-09/noted')->assertOk();
})->with(['admin', 'viewer']);

it('does not let an encoder or a system admin load the frozen pictures', function (string $role) {
    closeMonthsFor($this, '2026-09');

    $this->actingAs(userWithRole($role))->get('/reports/signatures/2026-09/prepared')->assertForbidden();
    $this->actingAs(userWithRole($role))->get('/reports/signatures/2026-09/noted')->assertForbidden();
})->with(['encoder', 'system_admin']);

it('sends a signed-out visitor to the login page instead of the frozen picture', function () {
    closeMonthsFor($this, '2026-09');

    $this->get('/reports/signatures/2026-09/prepared')->assertRedirect(route('login'));
    $this->get('/reports/signatures/2026-09/noted')->assertRedirect(route('login'));
});

it('gives a 404 when the month is not closed, the person is unknown or the picture is gone', function () {
    closeMonthsFor($this, '2026-09');

    $this->actingAs($this->admin)->get('/reports/signatures/2026-08/prepared')->assertNotFound();
    $this->actingAs($this->admin)->get('/reports/signatures/2026-09/someone')->assertNotFound();
    $this->actingAs($this->admin)->get('/reports/signatures/not-a-month/prepared')->assertNotFound();

    app(MonthService::class)->reopen('2026-09', $this->admin);

    $this->actingAs($this->admin)->get('/reports/signatures/2026-09/prepared')->assertNotFound();
});

it('does not serve the frozen pictures from public/ or the storage link', function () {
    closeMonthsFor($this, '2026-09');

    foreach (['/frozen-signatures/2026-09/prepared.png', '/storage/frozen-signatures/2026-09/prepared.png', '/storage/private/frozen-signatures/2026-09/prepared.png'] as $uri) {
        expect($this->actingAs($this->admin)->get($uri)->status())->toBeIn([403, 404]);
    }
});
