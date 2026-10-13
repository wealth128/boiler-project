<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Patient Records, Visits tab (GET /records/visits) and moving a visit to
 * Trash from it (DELETE /visits/{visit}). Edit opens the Encode screen and
 * comes back here (?return=). Rules: planning/rbac.md,
 * planning/database-schema.md section 5. Times are Asia/Manila.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin', ['username' => 'admin.garcia']);
    $this->encoder = userWithRole('encoder', ['username' => 'er.cruz']);
    $this->army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'is_afp' => true, 'sort_order' => 2]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL']);
    $this->ens = Rank::factory()->for($this->navy)->create(['name' => 'ENS']);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->santos = Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'middle_initial' => 'L', 'sex' => 'Female']);
});

/**
 * A visit by er.cruz, Army CPL, Hypertension, ER, for Santos unless given.
 *
 * @param  array<string, mixed>  $attributes
 */
function recordsVisit(object $test, array $attributes = []): Visit
{
    return Visit::factory()->create([
        'patient_id' => $test->santos->id,
        'rank_id' => $test->cpl->id,
        'branch_id' => $test->army->id,
        'diagnosis_id' => $test->hypertension->id,
        'encoded_by' => $test->encoder->id,
        'category' => 'ER',
        'visited_at' => now()->setTime(9, 0),
        ...$attributes,
    ]);
}

function recordsCloseMonth(string $period, User $by): void
{
    MonthClosure::create(['period' => $period, 'is_closed' => true, 'closed_by' => $by->id, 'closed_at' => now()]);
}

/**
 * Visit numbers on the page, in order.
 *
 * @return list<string>
 */
function visitNumbersOn($response): array
{
    return collect($response->inertiaProps('visits.data'))->pluck('visit_no')->all();
}

// --- Who may open it -------------------------------------------------------

it('shows the Visits tab to Admin', function () {
    $visit = recordsVisit($this, ['remarks' => 'From ER, admitted to ward', 'age' => 35]);

    $this->actingAs($this->admin)
        ->get('/records/visits')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('records/visits')
            ->where('filters', ['from' => '2026-09-01', 'to' => '2026-10-09', 'category' => '', 'branch' => '', 'q' => ''])
            ->where('categories', ['OPD', 'ER', 'Admission'])
            ->where('branches', [['id' => $this->army->id, 'name' => 'Army'], ['id' => $this->navy->id, 'name' => 'Navy']])
            ->has('visits.data', 1)
            ->where('visits.data.0', [
                'id' => $visit->id,
                'visit_no' => $visit->visit_no,
                'visited_at' => $visit->visited_at->toIso8601String(),
                'patient_no' => $this->santos->patient_no,
                'patient_name' => 'Santos, Maria L.',
                'sex' => 'Female',
                'age' => 35,
                'branch' => 'Army',
                'rank' => 'CPL',
                'diagnosis' => 'Hypertension',
                'remarks' => 'From ER, admitted to ward',
                'category' => 'ER',
                'encoded_by' => 'er.cruz',
                'month_closed' => false,
            ])
            ->where('visits.meta.total', 1));
});

it('refuses the Visits tab to every other role', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/records/visits')->assertForbidden();
})->with(['encoder', 'viewer', 'system_admin']);

// --- Filters ---------------------------------------------------------------

it('starts at the first day of last month and ends today', function () {
    recordsVisit($this, ['visited_at' => Carbon::parse('2026-08-31 23:59', 'Asia/Manila')]);
    $first = recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-01 00:00', 'Asia/Manila')]);
    $today = recordsVisit($this, ['visited_at' => now()]);

    $response = $this->actingAs($this->admin)->get('/records/visits');

    expect(visitNumbersOn($response))->toBe([$today->visit_no, $first->visit_no]);
});

it('filters by From and To, counting the whole To day', function () {
    recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-09 23:59', 'Asia/Manila')]);
    $from = recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-10 00:00', 'Asia/Manila')]);
    $to = recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-20 23:59', 'Asia/Manila')]);
    recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-21 00:00', 'Asia/Manila')]);

    $response = $this->actingAs($this->admin)->get('/records/visits?from=2026-09-10&to=2026-09-20');

    expect(visitNumbersOn($response))->toBe([$to->visit_no, $from->visit_no]);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2026-09-10')
        ->where('filters.to', '2026-09-20'));
});

it('treats an empty From as no limit', function () {
    $old = recordsVisit($this, ['visited_at' => Carbon::parse('2025-01-15 10:00', 'Asia/Manila')]);

    $response = $this->actingAs($this->admin)->get('/records/visits?from=&to=2026-10-09');

    expect(visitNumbersOn($response))->toBe([$old->visit_no])
        ->and($response->inertiaProps('filters.from'))->toBe('');
});

it('uses the default range for a date that is not real', function () {
    $this->actingAs($this->admin)
        ->get('/records/visits?from=2026-02-30&to=nonsense')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2026-10-09'));
});

it('ignores filters sent as lists instead of text', function () {
    recordsVisit($this);

    $this->actingAs($this->admin)
        ->get('/records/visits?q[]=santos&category[]=ER&branch[]=1&from[]=2026-09-01')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['from' => '2026-09-01', 'to' => '2026-10-09', 'category' => '', 'branch' => '', 'q' => ''])
            ->has('visits.data', 1));
});

it('filters by category', function () {
    recordsVisit($this, ['category' => 'ER']);
    $opd = recordsVisit($this, ['category' => 'OPD']);

    $response = $this->actingAs($this->admin)->get('/records/visits?category=OPD');

    expect(visitNumbersOn($response))->toBe([$opd->visit_no]);
});

it('ignores a category that does not exist', function () {
    recordsVisit($this);

    $this->actingAs($this->admin)
        ->get('/records/visits?category=Dental')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category', '')
            ->has('visits.data', 1));
});

it('filters by branch', function () {
    recordsVisit($this);
    $navy = recordsVisit($this, ['branch_id' => $this->navy->id, 'rank_id' => $this->ens->id]);

    $response = $this->actingAs($this->admin)->get("/records/visits?branch={$this->navy->id}");

    expect(visitNumbersOn($response))->toBe([$navy->visit_no]);
});

it('searches by name, Patient ID or Visit ID', function (string $q, array $expected) {
    $reyes = Patient::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Jose']);
    $visits = [
        'santos' => recordsVisit($this, ['visited_at' => now()->setTime(8, 0)]),
        'reyes' => recordsVisit($this, ['patient_id' => $reyes->id, 'visited_at' => now()->setTime(9, 0)]),
    ];

    $q = strtr($q, [
        '{santos_no}' => $this->santos->patient_no,
        '{reyes_visit}' => $visits['reyes']->visit_no,
        '{reyes_visit_digits}' => (string) $visits['reyes']->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/records/visits?q='.urlencode($q));

    expect(visitNumbersOn($response))->toBe(array_map(fn ($key) => $visits[$key]->visit_no, $expected));
})->with([
    'last name' => ['santos', ['santos']],
    'part of a name' => ['eye', ['reyes']],
    'last and first name' => ['Reyes Jo', ['reyes']],
    'last, first' => ['Santos, Maria', ['santos']],
    'first and last name' => ['Maria Santos', ['santos']],
    'Patient ID' => ['{santos_no}', ['santos']],
    'Visit ID' => ['{reyes_visit}', ['reyes']],
    'Visit ID, short form' => ['v{reyes_visit_digits}', ['reyes']],
    'no match' => ['Zamora', []],
]);

it('treats % and _ in the search as plain text', function () {
    recordsVisit($this);

    $response = $this->actingAs($this->admin)->get('/records/visits?q='.urlencode('%'));

    expect(visitNumbersOn($response))->toBe([]);
});

it('never shows visits in Trash', function () {
    $kept = recordsVisit($this);
    recordsVisit($this)->moveToTrash($this->admin, 'Duplicate entry');

    $response = $this->actingAs($this->admin)->get('/records/visits');

    expect(visitNumbersOn($response))->toBe([$kept->visit_no])
        ->and($response->inertiaProps('visits.meta.total'))->toBe(1);
});

it('lists the newest visit first', function () {
    $early = recordsVisit($this, ['visited_at' => now()->setTime(7, 0)]);
    $late = recordsVisit($this, ['visited_at' => now()->setTime(11, 0)]);
    $middle = recordsVisit($this, ['visited_at' => now()->setTime(9, 0)]);

    $response = $this->actingAs($this->admin)->get('/records/visits');

    expect(visitNumbersOn($response))->toBe([$late->visit_no, $middle->visit_no, $early->visit_no]);
});

it('shows the typed text for "Other (specify)" rank and diagnosis', function () {
    $otherRank = Rank::factory()->for($this->army)->create(['name' => 'Other (specify)', 'is_other' => true]);
    $otherDiagnosis = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true]);
    recordsVisit($this, [
        'rank_id' => $otherRank->id,
        'rank_other' => 'Cadet',
        'diagnosis_id' => $otherDiagnosis->id,
        'diagnosis_other' => 'Snake bite',
    ]);

    $this->actingAs($this->admin)
        ->get('/records/visits')
        ->assertInertia(fn (Assert $page) => $page
            ->where('visits.data.0.rank', 'Cadet')
            ->where('visits.data.0.diagnosis', 'Other: Snake bite'));
});

// --- Paging ----------------------------------------------------------------

it('shows 50 visits per page with page numbers', function () {
    foreach (range(1, 51) as $minute) {
        recordsVisit($this, ['visited_at' => now()->setTime(8, 0)->addMinutes($minute)]);
    }

    $this->actingAs($this->admin)
        ->get('/records/visits')
        ->assertInertia(fn (Assert $page) => $page
            ->has('visits.data', 50)
            ->where('visits.meta', [
                'current_page' => 1, 'last_page' => 2, 'per_page' => 50,
                'total' => 51, 'from' => 1, 'to' => 50,
            ]));

    $this->actingAs($this->admin)
        ->get('/records/visits?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('visits.data', 1)
            ->where('visits.meta.current_page', 2)
            ->where('visits.meta.from', 51)
            // The oldest one comes last.
            ->where('visits.data.0.visited_at', now()->setTime(8, 1)->toIso8601String()));
});

it('goes to the last page when the page number is past the end', function () {
    recordsVisit($this);

    $this->actingAs($this->admin)
        ->get('/records/visits?category=ER&page=5')
        ->assertRedirect('/records/visits?category=ER&page=1');
});

// --- Edit --------------------------------------------------------------------

it('opens Edit on the Encode screen with the way back to Records', function () {
    $visit = recordsVisit($this);
    $records = '/records/visits?from=2026-09-01&to=2026-10-09&category=ER&page=2';

    $this->actingAs($this->admin)
        ->get('/encode?edit='.$visit->id.'&return='.urlencode($records))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('encode/index')
            ->where('editing.id', $visit->id)
            ->where('returnTo', $records));
});

it('ignores a way back that is not Patient Records', function (string $return) {
    $visit = recordsVisit($this);

    $this->actingAs($this->admin)
        ->get('/encode?edit='.$visit->id.'&return='.urlencode($return))
        ->assertInertia(fn (Assert $page) => $page->where('returnTo', null));
})->with([
    'another site' => ['https://example.com/records/visits'],
    'another site, no scheme' => ['//example.com/records/visits'],
    'another page' => ['/users'],
    'a lookalike path' => ['/records/visits/../users'],
]);

it('goes back to Records with the same filters after Save changes', function () {
    $visit = recordsVisit($this);
    $records = '/records/visits?from=2026-09-01&to=2026-10-09&q=santos&page=2';

    $this->actingAs($this->admin)
        ->put("/visits/{$visit->id}", [
            'patient_id' => $this->santos->id,
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'middle_initial' => 'L',
            'sex' => 'Female',
            'birthdate' => $this->santos->birthdate->format('Y-m-d'),
            'age' => 40,
            'branch_id' => $this->army->id,
            'rank_id' => $this->cpl->id,
            'diagnosis_id' => $this->hypertension->id,
            'category' => 'OPD',
            'remarks' => '',
            'return_to' => $records,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect($records);

    expect($visit->fresh()->category->value)->toBe('OPD');
});

it('goes back to Encode after Save changes when not opened from Records', function () {
    $visit = recordsVisit($this);

    $this->actingAs($this->admin)
        ->put("/visits/{$visit->id}", [
            'patient_id' => $this->santos->id,
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'sex' => 'Female',
            'birthdate' => $this->santos->birthdate->format('Y-m-d'),
            'age' => 40,
            'branch_id' => $this->army->id,
            'rank_id' => $this->cpl->id,
            'diagnosis_id' => $this->hypertension->id,
            'category' => 'ER',
            'return_to' => 'https://example.com/',
        ])
        ->assertRedirect('/encode');
});

it("doesn't send an encoder to Records after Save changes", function () {
    $visit = recordsVisit($this, ['visited_at' => now()]);

    $this->actingAs($this->encoder)
        ->put("/visits/{$visit->id}", [
            'patient_id' => $this->santos->id,
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'sex' => 'Female',
            'birthdate' => $this->santos->birthdate->format('Y-m-d'),
            'age' => 40,
            'branch_id' => $this->army->id,
            'rank_id' => $this->cpl->id,
            'diagnosis_id' => $this->hypertension->id,
            'category' => 'ER',
            'return_to' => '/records/visits',
        ])
        ->assertRedirect('/encode');
});

// --- Move to Trash -----------------------------------------------------------

it('moves a visit to Trash with a reason and comes back to the same page', function () {
    $visit = recordsVisit($this);
    $records = '/records/visits?category=ER&page=1';

    $this->actingAs($this->admin)->from($records)
        // A typed text is ignored unless the reason is "Other (specify)".
        ->delete("/visits/{$visit->id}", ['reason' => 'Duplicate entry', 'reason_other' => 'ignored text here'])
        ->assertSessionHasNoErrors()
        ->assertRedirect($records)
        ->assertSessionHas('inertia.flash_data.toast.message', "Moved {$visit->visit_no} to Trash");

    $trashed = Visit::onlyTrashed()->findOrFail($visit->id);

    expect($trashed->deleted_by)->toBe($this->admin->id)
        ->and($trashed->delete_reason)->toBe('Duplicate entry')
        ->and(AuditLog::where('action', 'visit.deleted')->where('subject_id', $visit->id)->where('user_id', $this->admin->id)->exists())->toBeTrue();
});

it('needs a reason from the visit list', function (array $data) {
    $visit = recordsVisit($this);

    $this->actingAs($this->admin)->from('/records/visits')
        ->delete("/visits/{$visit->id}", $data)
        ->assertRedirect('/records/visits')
        ->assertSessionHasErrors(['reason' => 'Choose a reason from the list.']);

    expect($visit->fresh()->trashed())->toBeFalse()
        ->and(AuditLog::where('action', 'visit.deleted')->exists())->toBeFalse();
})->with([
    'no reason' => [[]],
    'empty' => [['reason' => '']],
    'free text' => [['reason' => 'Mistake']],
    'from the patient list' => [['reason' => 'Duplicate patient record']],
    'from the user list' => [['reason' => 'Resigned / separated']],
    'a list instead of text' => [['reason' => ['Duplicate entry']]],
    'different spelling' => [['reason' => 'duplicate entry']],
]);

it('needs 10 to 200 characters for "Other (specify)"', function (string $text) {
    $visit = recordsVisit($this);

    $this->actingAs($this->admin)->from('/records/visits')
        ->delete("/visits/{$visit->id}", ['reason' => 'Other (specify)', 'reason_other' => $text])
        ->assertRedirect('/records/visits')
        ->assertSessionHasErrors(['reason_other' => 'Describe the reason in 10 to 200 characters.']);

    expect($visit->fresh()->trashed())->toBeFalse();
})->with([
    'nothing typed' => [''],
    '9 characters' => ['Too short'],
    '9 characters after trimming' => ['   Too short   '],
    '201 characters' => [str_repeat('x', 201)],
]);

it('saves "Other (specify)" as "Other: <text>"', function () {
    $visit = recordsVisit($this);

    $this->actingAs($this->admin)->from('/records/visits')
        ->delete("/visits/{$visit->id}", ['reason' => 'Other (specify)', 'reason_other' => '  Patient   asked to remove  '])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/records/visits');

    expect(Visit::onlyTrashed()->findOrFail($visit->id)->delete_reason)->toBe('Other: Patient asked to remove')
        ->and(AuditLog::where('action', 'visit.deleted')->sole()->changes)->toBe(['reason' => 'Other: Patient asked to remove']);
});

it('accepts exactly 10 and 200 characters for "Other (specify)"', function (int $length) {
    $visit = recordsVisit($this);

    $this->actingAs($this->admin)
        ->delete("/visits/{$visit->id}", ['reason' => 'Other (specify)', 'reason_other' => str_repeat('a', $length)])
        ->assertSessionHasNoErrors();

    expect(Visit::onlyTrashed()->findOrFail($visit->id)->delete_reason)->toBe('Other: '.str_repeat('a', $length));
})->with([10, 200]);

it('offers the visit reasons, with "Other (specify)" last', function () {
    $this->actingAs($this->admin)
        ->get('/records/visits')
        ->assertInertia(fn (Assert $page) => $page->where('deleteReasons', [
            ['value' => 'Duplicate entry', 'other' => false],
            ['value' => 'Wrong patient selected', 'other' => false],
            ['value' => 'Encoded by mistake', 'other' => false],
            ['value' => 'Test / training entry', 'other' => false],
            ['value' => 'Other (specify)', 'other' => true],
        ]));
});

it("doesn't go back to another site after a delete", function () {
    $visit = recordsVisit($this);

    $this->actingAs($this->admin)->from('https://example.com/records/visits')
        ->delete("/visits/{$visit->id}", ['reason' => 'Duplicate entry'])
        ->assertRedirect('/records/visits');
});

it("doesn't let an encoder move a visit to Trash, even their own", function () {
    $visit = recordsVisit($this, ['visited_at' => now()]);

    $this->actingAs($this->encoder)
        ->delete("/visits/{$visit->id}", ['reason' => 'Encoded by mistake'])
        ->assertForbidden();

    expect($visit->fresh()->trashed())->toBeFalse();
});

// --- Closed month --------------------------------------------------------------

it('marks visits in a closed month, so they show no Edit or Delete', function () {
    recordsCloseMonth('2026-09', $this->admin);
    recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-15 10:00', 'Asia/Manila')]);
    recordsVisit($this, ['visited_at' => now()]);

    $this->actingAs($this->admin)
        ->get('/records/visits')
        ->assertInertia(fn (Assert $page) => $page
            ->where('visits.data.0.month_closed', false)
            ->where('visits.data.1.month_closed', true));
});

it("can't edit a visit in a closed month", function () {
    recordsCloseMonth('2026-09', $this->admin);
    $visit = recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-15 10:00', 'Asia/Manila')]);

    $this->actingAs($this->admin)
        ->get("/encode?edit={$visit->id}&return=".urlencode('/records/visits'))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->put("/visits/{$visit->id}", ['last_name' => 'Changed'])
        ->assertForbidden();
});

it("can't move a visit in a closed month to Trash, and says why in the row", function () {
    // The month was closed after Admin opened Records, so Delete was still shown.
    recordsCloseMonth('2026-09', $this->admin);
    $visit = recordsVisit($this, ['visited_at' => Carbon::parse('2026-09-15 10:00', 'Asia/Manila')]);

    $this->actingAs($this->admin)->from('/records/visits')
        ->delete("/visits/{$visit->id}", ['reason' => 'Duplicate entry'])
        ->assertRedirect('/records/visits')
        ->assertSessionHasErrors(['item' => 'September 2026 is closed. The Admin must reopen it first.']);

    expect($visit->fresh()->trashed())->toBeFalse()
        ->and(AuditLog::where('action', 'visit.deleted')->exists())->toBeFalse();
});

it('returns 404 for a visit that is already in Trash', function () {
    $visit = recordsVisit($this);
    $visit->moveToTrash($this->admin, 'Duplicate entry');

    $this->actingAs($this->admin)
        ->delete("/visits/{$visit->id}", ['reason' => 'Duplicate entry'])
        ->assertNotFound();
});
