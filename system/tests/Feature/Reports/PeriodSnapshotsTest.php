<?php

use App\Models\AgeBracket;
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
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Step 6x: closing a month twice, and quarterly / annual snapshots.
 *
 * - A second Close on the same month is refused ("... is already closed.")
 *   and leaves the saved copy and the frozen pictures alone.
 * - When closing a month makes every month of its quarter (or year) closed,
 *   a snapshot of that quarter (or year) is saved with its signature block
 *   frozen. Quarterly and annual reports use it. Reopening any month in it
 *   removes the quarter and year snapshots and their pictures.
 * - A quarter or year with no snapshot is counted live and is "Not final".
 * "Today" is 15-Oct-2026: July, August and September 2026 are closeable.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $branch = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->rank = Rank::factory()->for($branch)->create(['name' => 'CPL', 'sort_order' => 1]);
    $this->branch = $branch;
    $this->diagnosis = Diagnosis::factory()->create(['name' => 'Hypertension']);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);

    // July: 1 male ER. August: 1 female ER. September: 1 male OPD.
    $this->july = snapVisit($this, '2026-07-10 09:00', 'ER', 'Male');
    snapVisit($this, '2026-08-10 09:00', 'ER', 'Female');
    snapVisit($this, '2026-09-10 09:00', 'OPD', 'Male');

    foreach (['hospital_name' => 'AFP Medical Center', 'prepared_by' => 'Ana Reyes', 'noted_by' => 'COL Cruz'] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    $hospital = app(HospitalSettings::class);
    $hospital->storeImage('prepared_signature', UploadedFile::fake()->image('a.png', 300, 100));
    $hospital->storeImage('noted_signature', UploadedFile::fake()->image('b.png', 300, 100));
});

function snapVisit(object $test, string $when, string $category, string $sex): Visit
{
    return Visit::factory()->create([
        'patient_id' => Patient::factory()->create(['sex' => $sex])->id,
        'visited_at' => Carbon::parse($when, 'Asia/Manila'),
        'category' => $category,
        'age' => 30,
        'rank_id' => $test->rank->id,
        'branch_id' => $test->branch->id,
        'diagnosis_id' => $test->diagnosis->id,
        'encoded_by' => $test->encoder->id,
    ]);
}

function closeSnap(object $test, string ...$periods): void
{
    foreach ($periods as $period) {
        app(MonthService::class)->close($period, $test->admin);
    }
}

function snapshotOf(string $key): ?ReportSnapshot
{
    return ReportSnapshot::query()->where('period', $key)->first();
}

/** @return array<string, mixed> */
function snapSheet(object $test, string $query): array
{
    return $test->actingAs($test->admin)->get('/reports?'.$query)->assertOk()->inertiaProps('sheet');
}

const Q3 = 'type=quarterly&quarter=3&year=2026';

// --- Close twice ---------------------------------------------------------------------------

it('refuses a second close and leaves the saved copy and the frozen pictures alone', function () {
    closeSnap($this, '2026-09');

    $snapshot = snapshotOf('2026-09');
    $folder = Storage::disk('local')->allFiles('frozen-signatures/2026-09');
    $bytes = Storage::disk('local')->get('frozen-signatures/2026-09/noted.png');

    // Settings change in between, so a wrong second freeze would show.
    app(HospitalSettings::class)->storeImage('noted_signature', UploadedFile::fake()->image('new.png', 120, 40));

    expect(fn () => app(MonthService::class)->close('2026-09', $this->admin))
        ->toThrow(ValidationException::class, 'September 2026 is already closed.');

    $after = snapshotOf('2026-09');

    expect($after->id)->toBe($snapshot->id)
        ->and($after->created_at->equalTo($snapshot->created_at))->toBeTrue()
        ->and($after->signature_block)->toEqual($snapshot->signature_block)
        ->and(ReportSnapshot::query()->where('period', '2026-09')->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('frozen-signatures/2026-09'))->toBe($folder)
        ->and(Storage::disk('local')->get('frozen-signatures/2026-09/noted.png'))->toBe($bytes);
});

it('shows "September 2026 is already closed." when Close is pressed again', function () {
    closeSnap($this, '2026-09');

    $this->actingAs($this->admin)->from('/reports')->post('/months/2026-09/close')
        ->assertSessionHasErrors(['period' => 'September 2026 is already closed.']);

    expect(Storage::disk('local')->exists('frozen-signatures/2026-09/prepared.png'))->toBeTrue()
        ->and(snapshotOf('2026-09'))->not->toBeNull();
});

it('does not touch the quarter snapshot or its pictures on a second close', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    $quarter = snapshotOf('2026-Q3');
    $files = Storage::disk('local')->allFiles('frozen-signatures/2026-Q3');

    expect(fn () => app(MonthService::class)->close('2026-09', $this->admin))->toThrow(ValidationException::class);

    expect(snapshotOf('2026-Q3')->id)->toBe($quarter->id)
        ->and(Storage::disk('local')->allFiles('frozen-signatures/2026-Q3'))->toBe($files)
        ->and($files)->not->toBe([]);
});

it('can close again after a reopen (the lock is not left behind by a refused close)', function () {
    closeSnap($this, '2026-09');

    expect(fn () => app(MonthService::class)->close('2026-09', $this->admin))->toThrow(ValidationException::class);

    app(MonthService::class)->reopen('2026-09', $this->admin);
    closeSnap($this, '2026-09');

    expect(snapshotOf('2026-09'))->not->toBeNull();
});

it('still refuses to close a month that has not ended', function () {
    expect(fn () => app(MonthService::class)->close('2026-10', $this->admin))
        ->toThrow(ValidationException::class, "hasn't ended yet");
});

// --- Quarter snapshot ---------------------------------------------------------------------------

it('saves a quarter snapshot when its last month is closed, not before', function () {
    closeSnap($this, '2026-07', '2026-08');

    expect(snapshotOf('2026-Q3'))->toBeNull()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-Q3/prepared.png'))->toBeFalse();

    closeSnap($this, '2026-09');

    $quarter = snapshotOf('2026-Q3');

    expect($quarter)->not->toBeNull()
        ->and($quarter->created_by)->toBe($this->admin->id)
        ->and($quarter->signature_block)->toEqual([
            'prepared_by' => 'Ana Reyes',
            'prepared_by_title' => 'Admission / Admin Office',
            'noted_by' => 'COL Cruz',
            'noted_by_title' => 'Commanding Officer',
            'prepared_signature' => 'prepared.png',
            'noted_signature' => 'noted.png',
        ])
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-Q3/prepared.png'))->toBeTrue()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-Q3/noted.png'))->toBeTrue()
        // Not the year: October to December are not closed.
        ->and(snapshotOf('2026'))->toBeNull();
});

it('closes the quarter whichever of its months is closed last', function () {
    closeSnap($this, '2026-09', '2026-07', '2026-08');

    expect(snapshotOf('2026-Q3'))->not->toBeNull();
});

it('counts the quarter snapshot with the same numbers as a live count', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    $from = CarbonImmutable::create(2026, 7, 1, 0, 0, 0, 'Asia/Manila');
    $live = app(ReportBuilder::class)->build($from, $from->addMonths(3));
    $saved = snapshotOf('2026-Q3')->data;

    expect($saved['totals'])->toEqual($live['totals'])
        ->and($saved['categories'])->toEqual($live['categories'])
        ->and($saved['totals'])->toEqual(['patients' => 3, 'visits' => 3])
        ->and($saved['version'])->toBe(ReportBuilder::VERSION);
});

it('does not change a month snapshot when the quarter snapshot is made', function () {
    closeSnap($this, '2026-07', '2026-08');
    $july = snapshotOf('2026-07')->data;

    closeSnap($this, '2026-09');

    expect(snapshotOf('2026-07')->data)->toEqual($july);
});

it('uses the quarter snapshot on the Quarterly report, so numbers stay after a patient correction', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    $saved = snapshotOf('2026-Q3')->data;
    $maleBefore = collect($saved['categories']['ER']['sex'])->firstWhere('label', 'Male')['count'];

    // A correction: the July ER patient was recorded as Male by mistake.
    Patient::query()->whereKey($this->july->patient_id)->update(['sex' => 'Female']);

    $live = app(ReportBuilder::class)->build(
        CarbonImmutable::create(2026, 7, 1, 0, 0, 0, 'Asia/Manila'),
        CarbonImmutable::create(2026, 10, 1, 0, 0, 0, 'Asia/Manila'),
    );
    $liveMale = collect($live['categories']['ER']['sex'])->firstWhere('label', 'Male')['count'];

    // The live count moved, the saved copy did not.
    expect($liveMale)->not->toBe($maleBefore);

    $props = $this->actingAs($this->admin)->get('/reports?'.Q3)->inertiaProps();
    $shown = collect($props['report']['categories']['ER']['sex'])->firstWhere('label', 'Male')['count'];

    expect($shown)->toBe($maleBefore)
        ->and($props['report']['totals'])->toEqual($saved['totals'])
        ->and($props['snapshot'])->not->toBeNull()
        ->and($props['snapshot']['month_label'])->toBe('Q3 2026 (Jul–Sep)');
});

it('freezes the signature block of the quarter: later changes in Settings do not show', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');
    $oldNoted = Storage::disk('local')->get('frozen-signatures/2026-Q3/noted.png');

    Setting::updateOrCreate(['key' => 'noted_by'], ['value' => 'BGEN New Person']);
    app(HospitalSettings::class)->storeImage('noted_signature', UploadedFile::fake()->image('new.png', 120, 40));

    $sheet = snapSheet($this, Q3);

    expect($sheet['signatures_final'])->toBeTrue()
        ->and($sheet['signature_note'])->toBeNull()
        ->and($sheet['noted_by'])->toBe('COL Cruz')
        ->and($sheet['noted_signature_url'])->toStartWith('/reports/signatures/2026-Q3/noted?v=')
        ->and($sheet['prepared_signature_url'])->toStartWith('/reports/signatures/2026-Q3/prepared?v=');

    expect($this->actingAs($this->admin)->get($sheet['noted_signature_url'])->streamedContent())->toBe($oldNoted);
});

it('shows the frozen quarter block in the PDF', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');
    Setting::updateOrCreate(['key' => 'noted_by'], ['value' => 'BGEN New Person']);

    $resolver = app(ReportSheetResolver::class);
    $data = $resolver->resolve(['type' => 'quarterly', 'quarter' => '3', 'year' => '2026']);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now(), 'logoData' => null, 'signatureData' => $resolver->signatureData($data)])->render();

    expect($html)->toContain('<b>COL Cruz</b>')->not->toContain('BGEN New Person')
        ->and(substr_count($html, '<img src="data:image/png;base64,'))->toBe(2);

    $this->actingAs($this->admin)->get('/reports/export/pdf?'.Q3)->assertOk();
});

// --- Quarter not final ---------------------------------------------------------------------------

it('shows Not final and no pictures for a quarter with an open month, counted live', function () {
    closeSnap($this, '2026-07', '2026-09');

    $props = $this->actingAs($this->admin)->get('/reports?'.Q3)->inertiaProps();

    expect($props['sheet']['signatures_final'])->toBeFalse()
        ->and($props['sheet']['signature_note'])->toBe('Not final – month still open')
        ->and($props['sheet']['prepared_signature_url'])->toBeNull()
        ->and($props['sheet']['noted_signature_url'])->toBeNull()
        ->and($props['sheet']['prepared_by'])->toBe('Ana Reyes')
        ->and($props['snapshot'])->toBeNull()
        ->and($props['report']['totals'])->toEqual(['patients' => 3, 'visits' => 3]);
});

it('shows Not final and no pictures for a quarter whose months are closed but has no snapshot (older data)', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    // As if the months were closed before quarter snapshots existed.
    ReportSnapshot::query()->where('period', '2026-Q3')->delete();

    $props = $this->actingAs($this->admin)->get('/reports?'.Q3)->inertiaProps();

    expect($props['sheet']['signatures_final'])->toBeFalse()
        ->and($props['sheet']['signature_note'])->toBe('Not final – no saved copy')
        ->and($props['sheet']['prepared_signature_url'])->toBeNull()
        ->and($props['snapshot'])->toBeNull()
        ->and($props['report']['totals'])->toEqual(['patients' => 3, 'visits' => 3]);

    // The months themselves are still final.
    expect(snapSheet($this, 'type=monthly&month=2026-09')['signatures_final'])->toBeTrue();
});

it('shows Not final for a year with open months', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    $sheet = snapSheet($this, 'type=annual&year=2026');

    expect($sheet['signatures_final'])->toBeFalse()
        ->and($sheet['signature_note'])->toBe('Not final – month still open')
        ->and($sheet['prepared_signature_url'])->toBeNull();
});

// --- Reopen --------------------------------------------------------------------------------------

it('removes the quarter snapshot and its pictures when any month in it is reopened', function (string $reopen) {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    app(MonthService::class)->reopen($reopen, $this->admin);

    expect(snapshotOf('2026-Q3'))->toBeNull()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-Q3/prepared.png'))->toBeFalse()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-Q3/noted.png'))->toBeFalse()
        ->and(snapshotOf($reopen))->toBeNull();

    // The other months keep their own snapshots and pictures.
    foreach (array_diff(['2026-07', '2026-08', '2026-09'], [$reopen]) as $other) {
        expect(snapshotOf($other))->not->toBeNull()
            ->and(Storage::disk('local')->exists("frozen-signatures/{$other}/prepared.png"))->toBeTrue();
    }

    $sheet = snapSheet($this, Q3);

    expect($sheet['signatures_final'])->toBeFalse()
        ->and($sheet['signature_note'])->toBe('Not final – month still open');
})->with(['2026-07', '2026-08', '2026-09']);

it('saves a fresh quarter snapshot with the new block when the month is closed again', function () {
    closeSnap($this, '2026-07', '2026-08', '2026-09');
    app(MonthService::class)->reopen('2026-08', $this->admin);

    Setting::updateOrCreate(['key' => 'noted_by'], ['value' => 'BGEN New Person']);
    closeSnap($this, '2026-08');

    expect(snapshotOf('2026-Q3')->signature_block['noted_by'])->toBe('BGEN New Person')
        ->and(snap_sheet_name($this))->toBe('BGEN New Person');
});

function snap_sheet_name(object $test): string
{
    return snapSheet($test, Q3)['noted_by'];
}

// --- A year -----------------------------------------------------------------------------------------

it('saves the year snapshot on the twelfth close, uses it, and removes it on reopen', function () {
    $this->travelTo(Carbon::parse('2027-01-15 10:00', 'Asia/Manila'));

    foreach (range(1, 11) as $month) {
        closeSnap($this, sprintf('2026-%02d', $month));
    }

    expect(snapshotOf('2026'))->toBeNull();

    closeSnap($this, '2026-12');

    $year = snapshotOf('2026');
    $from = CarbonImmutable::create(2026, 1, 1, 0, 0, 0, 'Asia/Manila');
    $live = app(ReportBuilder::class)->build($from, $from->addYear());

    expect($year)->not->toBeNull()
        ->and($year->data['totals'])->toEqual($live['totals'])
        ->and($year->signature_block['prepared_by'])->toBe('Ana Reyes')
        ->and(Storage::disk('local')->exists('frozen-signatures/2026/prepared.png'))->toBeTrue()
        // The quarters too: Q1 to Q4 each when its last month closed.
        ->and(snapshotOf('2026-Q1'))->not->toBeNull()
        ->and(snapshotOf('2026-Q4'))->not->toBeNull();

    $props = $this->actingAs($this->admin)->get('/reports?type=annual&year=2026')->inertiaProps();

    expect($props['sheet']['signatures_final'])->toBeTrue()
        ->and($props['sheet']['prepared_signature_url'])->toStartWith('/reports/signatures/2026/prepared?v=')
        ->and($props['snapshot'])->not->toBeNull();

    app(MonthService::class)->reopen('2026-05', $this->admin);

    expect(snapshotOf('2026'))->toBeNull()
        ->and(snapshotOf('2026-Q2'))->toBeNull()
        ->and(snapshotOf('2026-Q1'))->not->toBeNull()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026/prepared.png'))->toBeFalse()
        ->and(Storage::disk('local')->exists('frozen-signatures/2026-Q2/prepared.png'))->toBeFalse()
        ->and(snap_sheet_year($this)['signature_note'])->toBe('Not final – month still open');
});

function snap_sheet_year(object $test): array
{
    return snapSheet($test, 'type=annual&year=2026');
}

// --- Serving the frozen quarter and year pictures ------------------------------------------------

it('lets Admin and Viewer load the frozen quarter pictures', function (string $role) {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    $this->actingAs(userWithRole($role))->get('/reports/signatures/2026-Q3/prepared')->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(userWithRole($role))->get('/reports/signatures/2026-Q3/noted')->assertOk();
})->with(['admin', 'viewer']);

it('does not let an encoder or a system admin load the frozen quarter pictures, or a signed-out visitor', function (string $role) {
    closeSnap($this, '2026-07', '2026-08', '2026-09');

    $this->actingAs(userWithRole($role))->get('/reports/signatures/2026-Q3/prepared')->assertForbidden();

    auth()->logout();
    $this->get('/reports/signatures/2026-Q3/prepared')->assertRedirect(route('login'));
})->with(['encoder', 'system_admin']);

it('gives a 404 for a quarter or year with no snapshot, and for a bad period', function () {
    closeSnap($this, '2026-07', '2026-08');

    foreach (['2026-Q3', '2026', '2026-Q5', '2026-13', '26-Q3', '2026-Q3x'] as $span) {
        $this->actingAs($this->admin)->get("/reports/signatures/{$span}/prepared")->assertNotFound();
    }

    closeSnap($this, '2026-09');
    $this->actingAs($this->admin)->get('/reports/signatures/2026-Q3/prepared')->assertOk();

    app(MonthService::class)->reopen('2026-09', $this->admin);
    $this->actingAs($this->admin)->get('/reports/signatures/2026-Q3/prepared')->assertNotFound();
});
