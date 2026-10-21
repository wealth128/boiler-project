<?php

use App\Models\AuditLog;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Visit;
use Tests\Support\SecurityWorld;
use Tests\Support\Tamper;

/*
 * Step 7a, check 2 (Encode and Records): every form sent with the browser
 * checks removed. All requests are JSON, so a refusal is a plain 422 with
 * the field's message, or 403 / 404. Never a 500, and the database stays as
 * it was (SecurityWorld::state() before and after).
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

// --- Encode: save a new visit (POST /visits) --------------------------------

/** @return array<string, array{0: Closure, 1: string|null}> */
function secBadVisitForms(): array
{
    return [
    'nothing at all' => [fn ($w) => ['last_name' => null, 'first_name' => null, 'sex' => null, 'birthdate' => null, 'age' => null, 'branch_id' => null, 'rank_id' => null, 'diagnosis_id' => null, 'category' => null], 'last_name'],
    'last name is a list' => [fn ($w) => ['last_name' => ['a', 'b']], 'last_name'],
    'last name is an object' => [fn ($w) => ['last_name' => ['x' => 'y']], 'last_name'],
    'last name number' => [fn ($w) => ['last_name' => 12345], 'last_name'],
    'last name 61 characters' => [fn ($w) => ['last_name' => str_repeat('a', 61)], 'last_name'],
    'first name 61 characters' => [fn ($w) => ['first_name' => str_repeat('b', 61)], 'first_name'],
    'middle initial too long' => [fn ($w) => ['middle_initial' => 'ABC'], 'middle_initial'],
    'middle initial a digit' => [fn ($w) => ['middle_initial' => '7'], 'middle_initial'],
    'sex not in the list' => [fn ($w) => ['sex' => 'Other'], 'sex'],
    'sex lower case' => [fn ($w) => ['sex' => 'male'], 'sex'],
    'sex a list' => [fn ($w) => ['sex' => ['Male']], 'sex'],
    'birthdate not a date' => [fn ($w) => ['birthdate' => 'yesterday'], 'birthdate'],
    'birthdate impossible' => [fn ($w) => ['birthdate' => '2026-13-45'], 'birthdate'],
    'birthdate wrong format' => [fn ($w) => ['birthdate' => '01/05/1990'], 'birthdate'],
    'birthdate in the future' => [fn ($w) => ['birthdate' => '2027-01-01'], 'birthdate'],
    'age text' => [fn ($w) => ['age' => 'abc'], 'age'],
    'age negative' => [fn ($w) => ['age' => -1], 'age'],
    'age 121' => [fn ($w) => ['age' => 121], 'age'],
    'age with decimals' => [fn ($w) => ['age' => 36.5], 'age'],
    'age a list' => [fn ($w) => ['age' => [36]], 'age'],
    'branch text' => [fn ($w) => ['branch_id' => 'army'], 'branch_id'],
    'branch that does not exist' => [fn ($w) => ['branch_id' => 999999], 'branch_id'],
    'branch a list' => [fn ($w) => ['branch_id' => [1]], 'branch_id'],
    'rank that does not exist' => [fn ($w) => ['rank_id' => 999999], 'rank_id'],
    'rank of another branch' => [fn ($w) => ['rank_id' => $w->lists['ens']->id], 'rank_id'],
    'rank text' => [fn ($w) => ['rank_id' => "1 OR 1=1"], 'rank_id'],
    'diagnosis that does not exist' => [fn ($w) => ['diagnosis_id' => 999999], 'diagnosis_id'],
    'diagnosis a list' => [fn ($w) => ['diagnosis_id' => [1, 2]], 'diagnosis_id'],
    'category not in the list' => [fn ($w) => ['category' => 'ICU'], 'category'],
    'category a list' => [fn ($w) => ['category' => ['ER']], 'category'],
    'remarks 1001 characters' => [fn ($w) => ['remarks' => str_repeat('r', 1001)], 'remarks'],
    'remarks a list' => [fn ($w) => ['remarks' => ['x']], 'remarks'],
    'typed rank missing for Other' => [fn ($w) => ['branch_id' => $w->lists['others']->id, 'rank_id' => $w->lists['otherType']->id, 'rank_other' => null], 'rank_other'],
    'typed rank too long' => [fn ($w) => ['branch_id' => $w->lists['others']->id, 'rank_id' => $w->lists['otherType']->id, 'rank_other' => str_repeat('x', 61)], 'rank_other'],
    'typed diagnosis missing for Other' => [fn ($w) => ['diagnosis_id' => $w->lists['otherDiagnosis']->id, 'diagnosis_other' => null], 'diagnosis_other'],
    'typed diagnosis too long' => [fn ($w) => ['diagnosis_id' => $w->lists['otherDiagnosis']->id, 'diagnosis_other' => str_repeat('d', 121)], 'diagnosis_other'],
    'patient id text' => [fn ($w) => ['patient_id' => 'abc'], 'patient_id'],
    'patient id a list' => [fn ($w) => ['patient_id' => [1]], 'patient_id'],
    'patient that does not exist' => [fn ($w) => ['patient_id' => 999999], 'patient_id'],
    'patient in Trash' => [fn ($w) => ['patient_id' => $w->trashedPatient->id], 'patient_id'],
    'confirm flag not a boolean' => [fn ($w) => ['confirm_new_patient' => 'maybe'], 'confirm_new_patient'],
    ];
}

dataset('bad visit forms', secBadVisitForms());

it('refuses a tampered new visit with a message and saves nothing', function (Closure $tamper, ?string $field) {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/visits', $tamper($w) + $w->visitForm(), 422, $field);
})->with('bad visit forms');

it('refuses an empty form', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/visits', [], 422, 'last_name');
});

it('keeps an office-bound encoder to the category of their office', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $opd = App\Models\User::factory()->encoder(App\Enums\Office::OPD)->create();
    $this->actingAs($opd);

    Tamper::refused($this, 'POST', '/visits', ['category' => 'ER'] + $w->visitForm(), 422, 'category');
    Tamper::refused($this, 'POST', '/visits', ['category' => ['OPD']] + $w->visitForm(), 422, 'category');
});

it('refuses a new visit for a branch that is turned off', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $w->lists['navy']->update(['is_active' => false]);
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/visits', ['branch_id' => $w->lists['navy']->id, 'rank_id' => $w->lists['ens']->id] + $w->visitForm(), 422, 'branch_id');
});

it('refuses a rank or diagnosis that is in Trash', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $w->lists['sgt']->delete();
    $w->lists['dengue']->delete();
    $this->actingAs($w->actor);

    Tamper::refused($this, 'POST', '/visits', ['rank_id' => $w->lists['sgt']->id] + $w->visitForm(), 422, 'rank_id');
    Tamper::refused($this, 'POST', '/visits', ['diagnosis_id' => $w->lists['dengue']->id] + $w->visitForm(), 422, 'diagnosis_id');
});

it('refuses a duplicate new patient with the duplicate message', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);
    $this->json('POST', '/visits', $w->visitForm())->assertRedirect();

    Tamper::refused($this, 'POST', '/visits', $w->visitForm(['last_name' => 'DELA CRUZ', 'first_name' => 'juan']), 422, 'duplicate_patient');
});

it('refuses a new visit when this month is closed', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    MonthClosure::create(['period' => '2026-10', 'is_closed' => true, 'closed_by' => $w->admin()->id, 'closed_at' => now()]);
    $this->actingAs($w->actor);

    $before = SecurityWorld::state();
    $response = $this->json('POST', '/visits', $w->visitForm());

    expect($response->getStatusCode())->toBe(403)
        ->and($response->json('message'))->toContain('closed')
        ->and(SecurityWorld::state())->toBe($before);
});

it('ignores extra fields on a new visit: encoder, date, numbers and flags come from the server', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    $this->json('POST', '/visits', $w->visitForm([
        'encoded_by' => $w->otherUser->id,
        'updated_by' => $w->otherUser->id,
        'visited_at' => '2020-01-01 08:00:00',
        'created_at' => '2020-01-01 08:00:00',
        'visit_no' => 'V-HACKED',
        'patient_no' => 'P-HACKED',
        'id' => 999,
        'deleted_at' => now()->toDateTimeString(),
        'deleted_by' => $w->otherUser->id,
        'delete_reason' => 'x',
        'import_batch_id' => $w->batch->id,
        'written_by' => 'Someone Else',
        'month_deletion_id' => 5,
        'is_admin' => true,
        'role' => 'admin',
    ]))->assertRedirect();

    $visit = Visit::where('encoded_by', $w->actor->id)->latest('id')->first();
    $patient = Patient::where('last_name', 'Dela Cruz')->sole();

    expect($visit->encoded_by)->toBe($w->actor->id)
        ->and($visit->updated_by)->toBeNull()
        ->and($visit->visited_at->isToday())->toBeTrue()
        ->and($visit->visit_no)->not->toBe('V-HACKED')
        ->and($visit->deleted_at)->toBeNull()
        ->and($visit->import_batch_id)->toBeNull()
        ->and($visit->written_by)->toBeNull()
        ->and($visit->month_deletion_id)->toBeNull()
        ->and($patient->patient_no)->not->toBe('P-HACKED')
        ->and($patient->deleted_at)->toBeNull()
        ->and($patient->import_batch_id)->toBeNull()
        ->and($w->actor->fresh()->role->value)->toBe('encoder');
});

// --- Encode: change a visit (PUT /visits/{visit}) ---------------------------

it('refuses a tampered visit change with a message and changes nothing', function (Closure $tamper, ?string $field) {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', "/visits/{$w->visit->id}", $tamper($w) + $w->visitForm(['patient_id' => $w->visit->patient_id]), 422, $field);
})->with(collect(secBadVisitForms())->reject(fn ($row, $name) => str_contains($name, 'patient') || str_contains($name, 'duplicate') || str_contains($name, 'confirm') || str_contains($name, 'nothing') || str_contains($name, 'empty'))->all());

it('refuses to change a visit that belongs to someone else, is old, is closed or is in Trash', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    $form = $w->visitForm(['remarks' => 'tampered']);

    // Another encoder's visit.
    $theirs = Visit::factory()->create(['encoded_by' => $w->otherUser->id, 'visited_at' => now(), 'rank_id' => $w->rank->id, 'diagnosis_id' => $w->lists['hypertension']->id]);
    Tamper::refused($this, 'PUT', "/visits/{$theirs->id}", $form, 403);

    // Own visit from yesterday.
    $old = Visit::factory()->create(['encoded_by' => $w->actor->id, 'visited_at' => now()->subDay(), 'rank_id' => $w->rank->id, 'diagnosis_id' => $w->lists['hypertension']->id]);
    Tamper::refused($this, 'PUT', "/visits/{$old->id}", $form, 403);

    // A visit in a closed month: not even the Admin can change it.
    $closed = $w->closedMonthVisit();
    Tamper::refused($this, 'PUT', "/visits/{$closed->id}", $form, 403);

    // A visit in Trash, a visit that never existed, an id that is not a number.
    Tamper::refused($this, 'PUT', "/visits/{$w->trashedVisit->id}", $form, 404);
    Tamper::refused($this, 'PUT', '/visits/999999', $form, 404);
    Tamper::refused($this, 'PUT', '/visits/abc', $form, 404);
    Tamper::refused($this, 'PUT', '/visits/-1', $form, 404);
    Tamper::refused($this, 'PUT', '/visits/1%20OR%201=1', $form, 404);
});

it('refuses the Admin a change in a closed month', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $closed = $w->closedMonthVisit();
    $this->actingAs($w->actor);

    Tamper::refused($this, 'PUT', "/visits/{$closed->id}", $w->visitForm(['remarks' => 'tampered']), 403);
});

it('ignores extra fields on a visit change: the encoder, the date and the flags stay', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);
    $originalDate = $w->visit->visited_at->toDateTimeString();

    $this->json('PUT', "/visits/{$w->visit->id}", $w->visitForm([
        'patient_id' => $w->visit->patient_id,
        'remarks' => 'edited',
        'encoded_by' => $w->otherUser->id,
        'visited_at' => '2020-01-01 08:00:00',
        'visit_no' => 'V-HACKED',
        'deleted_at' => now()->toDateTimeString(),
        'import_batch_id' => $w->batch->id,
    ]))->assertRedirect();

    $visit = $w->visit->fresh();

    expect($visit->remarks)->toBe('edited')
        ->and($visit->encoded_by)->toBe($w->actor->id)
        ->and($visit->visited_at->toDateTimeString())->toBe($originalDate)
        ->and($visit->visit_no)->not->toBe('V-HACKED')
        ->and($visit->deleted_at)->toBeNull()
        ->and($visit->import_batch_id)->toBeNull();
});

it('never sends the browser to another site after a visit change (return_to)', function (string $returnTo) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $response = $this->put("/visits/{$w->visit->id}", $w->visitForm(['patient_id' => $w->visit->patient_id, 'return_to' => $returnTo]));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    $host = parse_url($location, PHP_URL_HOST);

    expect($host)->toBeIn([null, 'localhost'])
        ->and(parse_url($location, PHP_URL_PATH))->toBeIn(['/encode', '/records/visits']);
})->with([
    'another site' => 'https://evil.example/records/visits',
    'protocol-relative' => '//evil.example/records/visits',
    'at sign trick' => 'https://localhost@evil.example/records/visits',
    'backslashes' => '/\\evil.example/records/visits',
    'javascript' => 'javascript:alert(1)',
    'another page of ours' => '/users',
    'path trick' => '/records/visits/../../users',
    'list' => ['/records/visits'],
]);

it('shows the Encode screen only for a visit the user may edit, and ignores a bad return address', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    expect($this->get('/encode?edit=abc&return=https://evil.example')->getStatusCode())->toBeIn([200, 404]);
    expect($this->get('/encode?edit[]=1&return[]=x')->getStatusCode())->toBeLessThan(500);
    expect($this->get('/encode?edit='.$w->visit->id.'&return=https://evil.example/records/visits')->getStatusCode())->toBe(200);
    $this->get("/encode?edit={$w->trashedVisit->id}")->assertStatus(404);
    $this->get('/encode?edit=999999')->assertStatus(404);
});

// --- Patient search ---------------------------------------------------------

it('answers every odd patient search with JSON and never a server error', function (string $query) {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);

    $response = $this->getJson('/patients/search?'.$query);

    expect($response->getStatusCode())->toBeLessThan(500, $query)
        ->and($response->json())->toBeArray();
})->with([
    'a list' => 'q[]=a',
    'a keyed list' => 'q[a]=b',
    'nothing' => 'q=',
    'sql quote' => "q=%27%20OR%201%3D1--",
    'percent signs' => 'q=%25%25%25',
    'underscores' => 'q=____',
    'null byte' => 'q=%00',
    'very long' => 'q='.'aaaaaaaaaa'.'bbbbbbbbbb'.'cccccccccc'.'dddddddddd'.'eeeeeeeeee',
    'unicode' => 'q=%F0%9F%98%80',
    'two values' => 'q=a&q=b',
]);

// --- Records ---------------------------------------------------------------

it('refuses a visit delete with a bad reason and keeps the visit', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'DELETE', "/visits/{$w->visit->id}", $data, 422, $field);
})->with('bad delete reasons');

it('refuses a patient delete with a bad reason and keeps the patient', function (array $data, string $field) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    Tamper::refused($this, 'DELETE', "/patients/{$w->patient->id}", $data, 422, $field);
})->with('bad delete reasons');

it('refuses to delete a visit that is in Trash, missing, in a closed month, or named oddly', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    $reason = ['reason' => 'Duplicate entry'];

    Tamper::refused($this, 'DELETE', "/visits/{$w->trashedVisit->id}", $reason, 404);
    Tamper::refused($this, 'DELETE', '/visits/999999', $reason, 404);
    Tamper::refused($this, 'DELETE', '/visits/abc', $reason, 404);
    Tamper::refused($this, 'DELETE', "/patients/{$w->trashedPatient->id}", $reason, 404);
    Tamper::refused($this, 'DELETE', '/patients/999999', $reason, 404);

    $closed = $w->closedMonthVisit();
    $before = SecurityWorld::state();
    $response = $this->json('DELETE', "/visits/{$closed->id}", $reason);

    expect($response->getStatusCode())->toBeIn([403, 422])
        ->and($response->json('message'))->toContain('closed')
        ->and(SecurityWorld::state())->toBe($before);
});

it('refuses to move a patient who still has visits to Trash, or says why', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $before = SecurityWorld::state();
    $response = $this->json('DELETE', "/patients/{$w->visit->patient_id}", ['reason' => 'Duplicate patient']);

    // Either refused with a message, or moved to Trash together with a clear audit entry.
    expect($response->getStatusCode())->toBeLessThan(500);

    if ($response->getStatusCode() >= 400) {
        expect(SecurityWorld::state())->toBe($before);
    } else {
        expect(AuditLog::where('user_id', $w->actor->id)->exists())->toBeTrue();
    }
});

it('answers every odd list or filter on the Records and Trash pages without a server error', function (string $page, string $query) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $response = $this->get("{$page}?{$query}");

    expect($response->getStatusCode())->toBeLessThan(500, "{$page}?{$query}");
})->with(function () {
    $queries = [
        'q[]=a', 'q[a]=b', 'q=%27%20OR%201%3D1--', 'q=%25', 'q=%00', 'page=-1', 'page=0', 'page=abc', 'page[]=1', 'page=99999999999999999999',
        'per_page=999999', 'per_page=-5', 'per_page[]=1', 'per_page=abc', 'from=zzz', 'to=zzz', 'from[]=1', 'to[]=1', 'from=2026-13-45', 'from=9999-12-31&to=0001-01-01',
        'category[]=x', 'category=ICU', 'branch[]=1', 'branch=abc', 'branch=99999999999999999999', 'sort=;DROP TABLE visits', 'dir=sideways', 'type[]=x', 'type=../../etc/passwd',
        'file[]=x', 'file=../../.env', 'user[]=1', 'action[]=x', 'subject[]=x',
    ];

    foreach (['/records/visits', '/records/patients', '/trash', '/audit-log', '/backups', '/records/import', '/users', '/settings/lists'] as $page) {
        foreach ($queries as $query) {
            yield "{$page}?{$query}" => [$page, $query];
        }
    }
});
