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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Quarter and year snapshots live in report_snapshots under the keys
 * "2026-Q3" and "2026" (months are "2026-09"). They must never be taken for
 * months: not in the month picker, not as a closed month, not by Close,
 * Reopen or Delete month report, not in Trash, the audit log or the records.
 * Only MonthService (writes), ReportSheetResolver and SignatureController
 * (read) touch the table.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $branch = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $rank = Rank::factory()->for($branch)->create(['name' => 'CPL', 'sort_order' => 1]);
    $diagnosis = Diagnosis::factory()->create(['name' => 'Hypertension']);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);

    foreach (['2026-07-10 09:00', '2026-08-10 09:00', '2026-09-10 09:00'] as $when) {
        $this->visit = Visit::factory()->create([
            'patient_id' => Patient::factory()->create()->id,
            'visited_at' => Carbon::parse($when, 'Asia/Manila'),
            'category' => 'ER',
            'age' => 30,
            'rank_id' => $rank->id,
            'branch_id' => $branch->id,
            'diagnosis_id' => $diagnosis->id,
            'encoded_by' => $this->encoder->id,
        ]);
    }
});

/** A snapshot row with a quarter or year key and no month closed (as if only that key existed). */
function keyOnlySnapshot(string $key, object $test): ReportSnapshot
{
    return ReportSnapshot::create([
        'period' => $key,
        'data' => ['version' => 3, 'categories' => [], 'totals' => ['patients' => 0, 'visits' => 0]],
        'signature_block' => null,
        'created_by' => $test->admin->id,
    ]);
}

// --- Real closes make quarter snapshots; nothing treats them as months -------------------------

it('keeps the quarter snapshot out of the month picker and out of the month status', function () {
    foreach (['2026-07', '2026-08', '2026-09'] as $period) {
        app(MonthService::class)->close($period, $this->admin);
    }

    expect(ReportSnapshot::query()->where('period', '2026-Q3')->exists())->toBeTrue();

    $props = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')->inertiaProps();

    foreach ($props['options']['months'] as $month) {
        expect($month['value'])->toMatch('/^\d{4}-(0[1-9]|1[0-2])$/');
    }

    expect($props['options']['years'])->each->toMatch('/^\d{4}$/')
        ->and($props['month_status'])->toMatchArray(['period' => '2026-09', 'closed' => true]);

    // A quarterly or annual report has no month status at all (no Close or Reopen buttons).
    expect($this->actingAs($this->admin)->get('/reports?type=quarterly&quarter=3&year=2026')->inertiaProps('month_status'))->toBeNull()
        ->and($this->actingAs($this->admin)->get('/reports?type=annual&year=2026')->inertiaProps('month_status'))->toBeNull();
});

it('does not make a month closed because a quarter or year snapshot exists', function (string $key) {
    keyOnlySnapshot($key, $this);

    expect(MonthClosure::isClosed('2026-07'))->toBeFalse();

    $props = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-07')->inertiaProps();

    expect($props['month_status']['closed'])->toBeFalse()
        ->and($props['snapshot'])->toBeNull()
        ->and($props['sheet']['signatures_final'])->toBeFalse()
        ->and($props['sheet']['signature_note'])->toBe('Not final – month still open');

    // Records: the July visit is not in a closed month, so it can still be changed.
    $row = $this->actingAs($this->admin)->get('/records/visits?from=2026-07-01&to=2026-07-31')->inertiaProps('visits.data.0');

    expect($row['month_closed'])->toBeFalse();
})->with(['2026-Q3', '2026']);

it('closes a month normally when only a quarter or year snapshot exists', function (string $key) {
    keyOnlySnapshot($key, $this);

    app(MonthService::class)->close('2026-07', $this->admin);

    expect(MonthClosure::isClosed('2026-07'))->toBeTrue()
        ->and(ReportSnapshot::query()->where('period', '2026-07')->exists())->toBeTrue();
})->with(['2026-Q3', '2026']);

it('says a month is not closed when only a quarter or year snapshot exists (Reopen)', function (string $key) {
    keyOnlySnapshot($key, $this);

    expect(fn () => app(MonthService::class)->reopen('2026-07', $this->admin))
        ->toThrow(ValidationException::class, 'July 2026 is not closed.');

    // The refused Reopen does not remove the other snapshot.
    expect(ReportSnapshot::query()->where('period', $key)->exists())->toBeTrue();
})->with(['2026-Q3', '2026']);

it('lets Close, Reopen and Delete month report take only months in the address', function (string $key) {
    foreach (['post' => "/months/{$key}/close", 'post ' => "/months/{$key}/reopen", 'delete' => "/months/{$key}"] as $method => $uri) {
        $this->actingAs($this->admin)->{trim($method)}($uri)->assertNotFound();
    }
})->with(['2026-Q3', '2026', '2026-Q3x', '2026-13', '2026-00']);

it('does not list quarter or year snapshots in Trash', function () {
    keyOnlySnapshot('2026-Q3', $this);
    keyOnlySnapshot('2026', $this);

    $items = $this->actingAs($this->admin)->get('/trash')->assertOk()->inertiaProps('items');

    expect($items['meta']['total'])->toBe(0)->and($items['data'])->toBe([]);

    // Trash only restores or deletes the known kinds; a snapshot is none of them.
    $this->actingAs($this->admin)->post('/trash/report-snapshots/1/restore')->assertNotFound();
    $this->actingAs($this->admin)->delete('/trash/report-snapshots/1')->assertNotFound();
});

it('writes no audit entry for a quarter or year snapshot, only month.closed for the months', function () {
    AuditLog::query()->delete();

    foreach (['2026-07', '2026-08', '2026-09'] as $period) {
        app(MonthService::class)->close($period, $this->admin);
    }

    expect(ReportSnapshot::query()->where('period', '2026-Q3')->exists())->toBeTrue();

    $entries = AuditLog::query()->orderBy('id')->get();

    expect($entries->pluck('action')->unique()->all())->toBe(['month.closed'])
        ->and($entries)->toHaveCount(3)
        ->and($entries->map(fn ($e) => $e->changes['period'])->all())->toBe(['2026-07', '2026-08', '2026-09'])
        ->and($entries->pluck('subject_type')->unique()->all())->toBe(['month_closures'])
        ->and(json_encode($entries->pluck('changes')))->not->toContain('Q3');

    app(MonthService::class)->reopen('2026-08', $this->admin);

    expect(AuditLog::query()->where('action', 'month.reopened')->count())->toBe(1);
});

it('shows only months in the audit log after a quarter snapshot is made', function () {
    foreach (['2026-07', '2026-08', '2026-09'] as $period) {
        app(MonthService::class)->close($period, $this->admin);
    }

    $rows = $this->actingAs($this->admin)->get('/audit-log?from=2026-10-01&to=2026-10-31&action=month.closed')->assertOk()->inertiaProps('entries.data');

    expect(collect($rows)->pluck('record')->sort()->values()->all())->toBe(['August 2026', 'July 2026', 'September 2026']);
});

it('does not touch the backup logic or any list: only a few classes use the snapshot table', function () {
    $allowed = [
        'app/Services/MonthService.php',
        'app/Services/ReportSheetResolver.php',
        'app/Http/Controllers/Reports/SignatureController.php',
        'app/Models/ReportSnapshot.php',
        // The morph map name used by the audit log subject lookup.
        'app/Providers/AppServiceProvider.php',
        // Only mentions the table in a comment.
        'app/Services/ReportBuilder.php',
        // Only checksums the table, to know when a remembered report is out of date. It never reads a snapshot.
        'app/Services/ReportCache.php',
    ];

    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (str_contains($source, 'ReportSnapshot') || str_contains($source, 'report_snapshots')) {
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        }
    }

    sort($found);
    sort($allowed);

    // A new reader of the table must be checked for quarter and year keys before it is added here.
    expect($found)->toBe($allowed);
});

it('keeps the backup screen working with quarter and year snapshots in the database', function () {
    keyOnlySnapshot('2026-Q3', $this);
    keyOnlySnapshot('2026', $this);

    $this->actingAs($this->admin)->get('/backups')->assertOk();
});
