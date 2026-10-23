<?php

use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\ImportBatch;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Visit;
use App\Services\MonthService;
use App\Services\ReportCache;
use App\Services\ReportDataVersion;
use App\Services\TrashService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Step 7d-4, fix 1: a remembered report is kept while one small counter (the
 * "report data version", one row in settings) stays the same. Every change to
 * the data a report is made from raises it. A report request reads it with one
 * small query and never runs CHECKSUM TABLE.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    Storage::fake('local');

    $this->admin = userWithRole('admin');
    $this->branch = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->rank = Rank::factory()->for($this->branch)->create(['name' => 'CPL', 'sort_order' => 1]);
    $this->diagnosis = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->bracket = AgeBracket::create(['min_age' => 18, 'max_age' => 59]);
    $this->patient = Patient::factory()->create();
    $this->visit = Visit::factory()->create([
        'patient_id' => $this->patient->id,
        'visited_at' => Carbon::parse('2026-09-10 09:00', 'Asia/Manila'),
        'category' => 'ER',
        'age' => 30,
        'rank_id' => $this->rank->id,
        'branch_id' => $this->branch->id,
        'diagnosis_id' => $this->diagnosis->id,
        'encoded_by' => $this->admin->id,
    ]);
});

/** Run a change and say whether the version went up. */
function versionGoesUp(callable $change): bool
{
    $before = ReportDataVersion::current();
    $change();

    return ReportDataVersion::current() !== $before;
}

/** Another visit of the same patient, in September 2026. */
function anotherVisit(object $t): Visit
{
    return Visit::factory()->create([
        'patient_id' => $t->patient->id,
        'rank_id' => $t->rank->id,
        'branch_id' => $t->branch->id,
        'diagnosis_id' => $t->diagnosis->id,
        'encoded_by' => $t->admin->id,
        'visited_at' => Carbon::parse('2026-09-11 09:00', 'Asia/Manila'),
    ]);
}

it('starts from the settings row and goes up by one each time', function () {
    DB::table('settings')->where('key', ReportDataVersion::KEY)->delete();

    expect(ReportDataVersion::current())->toBe('0');

    ReportDataVersion::bump();
    ReportDataVersion::bump();

    expect(ReportDataVersion::current())->toBe('2')
        ->and(DB::table('settings')->where('key', ReportDataVersion::KEY)->count())->toBe(1);
});

it('goes up when each kind of data changes: create, update, Trash, restore and permanent delete', function (Closure $change) {
    expect(versionGoesUp(fn () => $change($this)))->toBeTrue();
})->with([
    'a visit is encoded' => [fn ($t) => anotherVisit($t)],
    'a visit is edited' => [fn ($t) => $t->visit->update(['category' => 'OPD'])],
    'a visit goes to Trash' => [fn ($t) => $t->visit->moveToTrash($t->admin, 'Duplicate entry')],
    'a visit comes back' => [function ($t) {
        $t->visit->moveToTrash($t->admin, 'Duplicate entry');
        $before = ReportDataVersion::current();
        $t->visit->restoreFromTrash();
        expect(ReportDataVersion::current())->not->toBe($before);
    }],
    'a visit is deleted for good' => [fn ($t) => $t->visit->forceDelete()],
    'a patient is added' => [fn () => Patient::factory()->create()],
    'a patient is edited' => [fn ($t) => $t->patient->update(['last_name' => 'Changed'])],
    'a branch is added' => [fn () => Branch::factory()->create(['name' => 'Navy', 'sort_order' => 2])],
    'a branch is renamed' => [fn ($t) => $t->branch->update(['name' => 'Philippine Army'])],
    'a rank is added' => [fn ($t) => Rank::factory()->for($t->branch)->create(['name' => 'SGT', 'sort_order' => 2])],
    'a rank goes to Trash' => [fn ($t) => Rank::factory()->for($t->branch)->create(['name' => 'SGT', 'sort_order' => 2])->moveToTrash($t->admin, 'Duplicate entry')],
    'a diagnosis is added' => [fn () => Diagnosis::factory()->create(['name' => 'Asthma'])],
    'a diagnosis is renamed' => [fn ($t) => $t->diagnosis->update(['name' => 'High blood pressure'])],
    'an age range is added' => [fn () => AgeBracket::create(['min_age' => 60, 'max_age' => 120])],
    'an age range is changed' => [fn ($t) => $t->bracket->update(['max_age' => 55])],
    'a month is closed' => [fn ($t) => app(MonthService::class)->close('2026-09', $t->admin)],
    'a month closure row is made' => [fn ($t) => MonthClosure::create(['period' => '2026-08', 'is_closed' => true, 'closed_by' => $t->admin->id, 'closed_at' => now()])],
    'a saved copy is made' => [fn ($t) => ReportSnapshot::create(['period' => '2026-08', 'data' => ['version' => 4], 'signature_block' => null, 'created_by' => $t->admin->id])],
    'an import batch is made' => [fn ($t) => ImportBatch::create(['file_name' => 'a.csv', 'original_rows' => 0, 'imported_count' => 0, 'skipped_count' => 0, 'imported_by' => $t->admin->id, 'created_at' => now()])],
]);

it('goes up on paths that skip model events: reopen, Delete month report, restoring it and deleting it for good', function () {
    $months = app(MonthService::class);
    $months->close('2026-09', $this->admin);

    // Reopen deletes the saved copies with one query.
    expect(versionGoesUp(fn () => $months->reopen('2026-09', $this->admin)))->toBeTrue();

    // A whole month to Trash is one query too.
    $trash = app(TrashService::class);
    $entry = null;
    expect(versionGoesUp(function () use ($trash, &$entry) {
        $entry = $trash->deleteMonthReport('2026-09', $this->admin, 'Encoded in the wrong month');
    }))->toBeTrue();

    expect(versionGoesUp(fn () => $trash->restore($entry->fresh(), $this->admin)))->toBeTrue();

    $entry = $trash->deleteMonthReport('2026-09', $this->admin, 'Encoded in the wrong month');
    expect(versionGoesUp(fn () => $trash->forceDelete($entry->fresh(), $this->admin)))->toBeTrue();
});

it('does not go up when a model is saved without any change, or when a change is rolled back', function () {
    expect(versionGoesUp(fn () => $this->visit->fresh()->save()))->toBeFalse();

    $before = ReportDataVersion::current();

    try {
        DB::transaction(function () {
            $this->branch->update(['name' => 'Rolled back']);
            throw new RuntimeException('stop');
        });
    } catch (RuntimeException) {
        // The change and the new number are rolled back together.
    }

    expect(ReportDataVersion::current())->toBe($before);
});

it('remembers a report while nothing changed and counts again after a change', function () {
    $cache = app(ReportCache::class);
    $from = Carbon::parse('2026-09-01', 'Asia/Manila');
    $to = Carbon::parse('2026-10-01', 'Asia/Manila');

    $counts = function () use ($cache, $from, $to): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $report = $cache->build($from, $to);
        $counted = count(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'ROW_NUMBER')));
        DB::disableQueryLog();

        return [$counted > 0, $report['totals']['visits']];
    };

    [$firstCounted, $first] = $counts();
    [$secondCounted, $second] = $counts();

    anotherVisit($this);
    [$thirdCounted, $third] = $counts();

    expect($firstCounted)->toBeTrue()->and($first)->toBe(1)
        ->and($secondCounted)->toBeFalse()->and($second)->toBe(1)
        ->and($thirdCounted)->toBeTrue()->and($third)->toBe(2);
});

it('runs no CHECKSUM TABLE on a report request, and reads the version with one small query', function () {
    $this->actingAs($this->admin);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/reports?month=2026-09')->assertOk();
    $this->get('/reports?month=2026-09')->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $checksums = array_filter($queries, fn (string $q): bool => stripos($q, 'CHECKSUM') !== false);
    $reads = array_filter($queries, fn (string $q): bool => str_contains($q, 'from `settings`') && str_contains($q, 'where `key` = ?') && ! str_contains($q, 'in ('));

    expect($checksums)->toBeEmpty()->and(count($reads))->toBeGreaterThanOrEqual(1);
});
