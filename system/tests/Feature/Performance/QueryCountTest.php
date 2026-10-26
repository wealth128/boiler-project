<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Step 7g: the long lists and the Encode screen read a fixed number of
 * queries, whatever the number of rows on the page (no query per row), and a
 * late page reads as many as the first one. The bounds are the counts measured
 * when this was written, with a little room; a new query per row breaks them.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');
    $this->army = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL']);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
});

/**
 * The queries a call runs.
 *
 * @return list<string>
 */
function perfQueries(callable $call): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $call();
    } finally {
        DB::disableQueryLog();
    }

    return array_column(DB::getQueryLog(), 'query');
}

/** @param  array<string, mixed>  $attributes */
function perfVisits(object $test, int $count, array $attributes = []): void
{
    for ($i = 0; $i < $count; $i++) {
        Visit::factory()->create([
            'visited_at' => now()->subMinutes(5 + $i),
            'branch_id' => $test->army->id,
            'rank_id' => $test->cpl->id,
            'diagnosis_id' => $test->hypertension->id,
            'encoded_by' => $test->encoder->id,
            'category' => 'ER',
            ...$attributes,
        ]);
    }
}

it('reads Records > Visits in the same few queries for 10 or 50 rows and for a late page', function () {
    perfVisits($this, 60);
    $this->actingAs($this->admin);

    $this->get('/records/visits')->assertOk(); // fills the one-minute caches of the shared props
    $ten = perfQueries(fn () => $this->get('/records/visits?per_page=10')->assertOk());
    $fifty = perfQueries(fn () => $this->get('/records/visits?per_page=50')->assertOk());
    $late = perfQueries(fn () => $this->get('/records/visits?from=&to=&page=6')->assertOk());
    $search = perfQueries(fn () => $this->get('/records/visits?from=&to=&q=a')->assertOk());

    expect(count($fifty))->toBe(count($ten))
        ->and(count($late))->toBe(count($ten))
        // A name search first finds the matching patients: one query more, whatever the rows.
        ->and(count($search))->toBe(count($ten) + 1)
        ->and(count($ten))->toBeLessThanOrEqual(12);
});

it('reads Records > Patients in the same few queries for 10 or 50 rows and for a late page', function () {
    perfVisits($this, 60);
    $this->actingAs($this->admin);

    $this->get('/records/patients')->assertOk();
    $ten = perfQueries(fn () => $this->get('/records/patients?per_page=10')->assertOk());
    $fifty = perfQueries(fn () => $this->get('/records/patients?per_page=50')->assertOk());
    $late = perfQueries(fn () => $this->get('/records/patients?page=6')->assertOk());

    expect(count($fifty))->toBe(count($ten))
        ->and(count($late))->toBe(count($ten))
        ->and(count($ten))->toBeLessThanOrEqual(8);
});

it('reads the Audit log in the same few queries for 10 or 50 entries and for a late page', function () {
    perfVisits($this, 30);
    $visits = Visit::query()->pluck('id');
    $patients = Patient::query()->pluck('id');

    foreach (range(1, 60) as $i) {
        AuditLog::query()->insert([
            'user_id' => $this->admin->id,
            'action' => $i % 2 === 0 ? 'visit.updated' : 'patient.created',
            'subject_type' => $i % 2 === 0 ? 'visits' : 'patients',
            'subject_id' => $i % 2 === 0 ? $visits[$i % 30] : $patients[$i % 30],
            'changes' => json_encode(['old' => ['diagnosis_id' => $this->hypertension->id], 'new' => ['diagnosis_id' => $this->hypertension->id]]),
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $this->actingAs($this->admin);

    $this->get('/audit-log')->assertOk();
    $ten = perfQueries(fn () => $this->get('/audit-log?from=&to=&per_page=10')->assertOk());
    $fifty = perfQueries(fn () => $this->get('/audit-log?from=&to=&per_page=50')->assertOk());
    $late = perfQueries(fn () => $this->get('/audit-log?from=&to=&page=6')->assertOk());

    expect(count($fifty))->toBe(count($ten))
        ->and(count($late))->toBe(count($ten))
        ->and(count($ten))->toBeLessThanOrEqual(15);
});

it('reads Trash in the same few queries for 10 or 50 rows, also for patients that still have visits', function () {
    perfVisits($this, 60, ['deleted_at' => now()->subHour(), 'deleted_by' => null, 'delete_reason' => 'Duplicate entry']);

    // Patients in Trash whose visits are in Trash too: "Delete permanently" is blocked for each.
    Patient::query()->limit(30)->update(['deleted_at' => now()->subMinutes(30), 'delete_reason' => 'Duplicate patient record']);

    $this->actingAs($this->admin);

    $this->get('/trash')->assertOk();
    $ten = perfQueries(fn () => $this->get('/trash?per_page=10')->assertOk());
    $fifty = perfQueries(fn () => $this->get('/trash?per_page=50')->assertOk());
    $late = perfQueries(fn () => $this->get('/trash?per_page=10&page=7')->assertOk());

    // One query per kind of record on the page (the 50 rows hold patients and visits, the first 10 only patients), never one per row.
    expect(count($fifty))->toBeLessThanOrEqual(count($ten) + 1)
        ->and(count($late))->toBeLessThanOrEqual(count($ten) + 1)
        ->and(count($fifty))->toBeLessThanOrEqual(14);
});

it('opens Encode in the same few queries with 2 or 25 of my visits today', function () {
    perfVisits($this, 2);
    $this->actingAs($this->encoder)->get('/encode')->assertOk();
    $few = perfQueries(fn () => $this->get('/encode')->assertOk());

    perfVisits($this, 23);
    $many = perfQueries(fn () => $this->get('/encode')->assertOk());

    expect(count($many))->toBe(count($few))
        ->and(count($few))->toBeLessThanOrEqual(10);
});

it('saves a visit (Save & New) in a bounded number of queries', function () {
    $patient = Patient::factory()->create();
    perfVisits($this, 1, ['patient_id' => $patient->id]);

    $queries = perfQueries(fn () => $this->actingAs($this->encoder)->from('/encode')->post('/visits', [
        'patient_id' => $patient->id,
        'last_name' => $patient->last_name,
        'first_name' => $patient->first_name,
        'middle_initial' => null,
        'sex' => $patient->sex->value,
        'birthdate' => $patient->birthdate->format('Y-m-d'),
        'age' => 40,
        'branch_id' => $this->army->id,
        'rank_id' => $this->cpl->id,
        'rank_other' => null,
        'diagnosis_id' => $this->hypertension->id,
        'diagnosis_other' => null,
        'category' => 'ER',
        'remarks' => null,
    ])->assertRedirect('/encode')->assertSessionHasNoErrors());

    expect(count($queries))->toBeLessThanOrEqual(25);
});

it('answers the patient search in the same few queries for 1 or 10 matches', function () {
    Patient::factory()->create(['last_name' => 'Zamora', 'first_name' => 'Ana']);

    foreach (range(1, 12) as $i) {
        Patient::factory()->create(['last_name' => 'Santos', 'first_name' => "Juan {$i}"]);
    }

    $this->actingAs($this->encoder)->getJson('/patients/search?q=xy')->assertOk();

    $one = perfQueries(fn () => $this->getJson('/patients/search?q=zam')->assertOk()->assertJsonCount(1, 'data'));
    $ten = perfQueries(fn () => $this->getJson('/patients/search?q=san')->assertOk()->assertJsonCount(10, 'data'));

    expect(count($ten))->toBe(count($one))
        ->and(count($one))->toBeLessThanOrEqual(5);
});
