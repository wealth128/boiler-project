<?php

use App\Http\Controllers\Audit\AuditRows;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\TablePaging;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Step 7g: the faster queries give exactly the rows the old ones gave. Each
 * old query is kept here as the reference.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
});

it('pages ids-first exactly like paginate(), ties and late pages included', function () {
    // Many visits share a time, so the order falls back to the id.
    foreach (range(1, 37) as $i) {
        Visit::factory()->create(['visited_at' => now()->subMinutes(intdiv($i, 4))]);
    }

    Visit::query()->limit(3)->update(['deleted_at' => now()]);
    $query = fn () => Visit::query()->with('patient')->orderByDesc('visited_at')->orderByDesc('id');

    foreach ([10, 20] as $perPage) {
        foreach (range(1, 5) as $page) {
            app()->instance('request', Request::create('/records/visits', 'GET', ['page' => (string) $page, 'per_page' => (string) $perPage]));

            $new = TablePaging::paginate($query(), request());
            $old = $query()->paginate($perPage);

            expect(collect($new->items())->pluck('id')->all())->toBe(collect($old->items())->pluck('id')->all())
                ->and($new->total())->toBe($old->total())
                ->and($new->lastPage())->toBe($old->lastPage())
                ->and($new->currentPage())->toBe($old->currentPage());

            foreach ($new->items() as $visit) {
                expect($visit->relationLoaded('patient'))->toBeTrue();
            }
        }
    }
});

it('finds the same returning patients as before, in the same order', function () {
    $names = [
        ['Dela Cruz', 'Juan'], ['Dela Cruz', 'Maria'], ['Delacruz', 'Ana'], ['De La Cruz', 'Pedro'], ['Dela', 'Cruz'],
        ['Santos', 'Maria'], ['Santos', 'Mark'], ['Santos-Reyes', 'Ana'], ['Santos', 'Ana Marie'], ['Sant', 'Os'],
        ['Reyes', 'Santos'], ['Ramos, Jr', 'Jose'], ['San_tos', 'Lito'], ['Ocampo', 'Ma. Luisa'],
    ];

    foreach ($names as [$last, $first]) {
        Patient::factory()->create(['last_name' => $last, 'first_name' => $first]);
    }

    Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Trashed', 'deleted_at' => now()]);

    $before = function (string $q): array {
        $prefix = addcslashes((string) preg_replace('/\s+/', ' ', $q), '\\%_').'%';

        return Patient::query()
            ->where(fn ($where) => $where->where('last_name', 'like', $prefix)
                ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$prefix])
                ->orWhereRaw("CONCAT(last_name, ', ', first_name) LIKE ?", [$prefix]))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->limit(10)
            ->pluck('id')
            ->all();
    };

    $this->actingAs(userWithRole('encoder'));

    foreach (['de', 'dela', 'dela ', 'dela cruz', 'dela cruz j', 'Dela  Cruz  M', 'delac', 'de la', 'santos', 'santos m', 'santos, m', 'santos,m', 'santos-', 'sant os', 'san_', 'sa%', 'ramos, jr', 'ramos, jr j', 'ocampo, ma.', 'reyes s', 'zz'] as $q) {
        $now = collect($this->getJson('/patients/search?q='.rawurlencode($q))->assertOk()->json('data'))->pluck('id')->all();

        expect($now)->toBe($before(trim($q)), "search \"{$q}\"");
    }
});

it('finds the same Audit log entries for a Record as before', function () {
    $admin = userWithRole('admin', ['username' => 'v.santos']);
    $visit = Visit::factory()->create();
    $patient = $visit->patient;
    $at = now()->subDay();

    $entry = fn (string $action, string $type, int $id, ?array $changes = null) => AuditLog::query()->insert([
        'user_id' => $admin->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id,
        'changes' => $changes === null ? null : json_encode($changes), 'ip_address' => '10.0.0.1', 'created_at' => $at,
    ]);

    $entry('visit.created', 'visits', $visit->id);
    $entry('visit.updated', 'visits', $visit->id);
    $entry('patient.created', 'patients', $patient->id);
    $entry('user.login', 'users', $admin->id);
    $entry('visit.purged', 'visits', 999999, ['label' => 'V-777777']);
    $entry('patient.purged', 'patients', 888888, ['label' => 'P-666666']);
    $entry('user.purged', 'users', 777777, ['label' => 'old.user']);
    $entry('diagnosis.purged', 'diagnoses', 555, ['label' => 'Dengue']);
    $entry('report.exported', 'users', $admin->id, ['label' => 'V-777777']);
    // Same id, other kind of record: never matches the visit.
    $entry('patient.updated', 'patients', $visit->id);

    $before = fn (string $record): array => AuditLog::query()
        ->where(function ($q) use ($record): void {
            $q->where(fn ($w) => $w->where('subject_type', 'visits')->whereIn('subject_id', Visit::withTrashed()->where('visit_no', $record)->select('id')))
                ->orWhere(fn ($w) => $w->where('subject_type', 'patients')->whereIn('subject_id', Patient::withTrashed()->where('patient_no', $record)->select('id')))
                ->orWhere(fn ($w) => $w->where('subject_type', 'users')->whereIn('subject_id', User::withTrashed()->where('username', $record)->select('id')))
                ->orWhere(fn ($w) => $w->where('action', 'like', '%.purged')->where('changes->label', $record));
        })
        ->orderBy('id')
        ->pluck('id')
        ->all();

    foreach ([$visit->visit_no, $patient->patient_no, 'v.santos', 'V-777777', 'P-666666', 'old.user', 'Dengue', 'nothing'] as $record) {
        $now = AuditRows::query(['from' => '', 'to' => '', 'user' => '', 'action' => '', 'record' => $record])->reorder()->orderBy('id')->pluck('id')->all();

        expect($now)->toBe($before($record), "record \"{$record}\"")
            ->and($now === [] && $record !== 'nothing')->toBeFalse();
    }
});

it('has the indexes the long lists use', function () {
    expect(Schema::hasIndex('visits', ['deleted_at', 'visited_at']))->toBeTrue()
        ->and(Schema::hasIndex('patients', ['deleted_at', 'last_name', 'first_name']))->toBeTrue()
        ->and(Schema::hasIndex('audit_logs', ['action', 'created_at']))->toBeTrue()
        // The cache clean-up (01:30) and Laravel's session clean-up delete by these.
        ->and(Schema::hasIndex('cache', ['expiration']))->toBeTrue()
        ->and(Schema::hasIndex('sessions', ['last_activity']))->toBeTrue();
});

it('reads Records page 1 through the new visits index', function () {
    Visit::factory()->count(3)->create();

    $plan = DB::select("EXPLAIN SELECT id FROM visits WHERE deleted_at IS NULL AND visited_at >= '2026-09-01' ORDER BY visited_at DESC, id DESC LIMIT 10");

    expect(collect($plan)->pluck('possible_keys')->implode(','))->toContain('visits_deleted_at_visited_at_index');
});
