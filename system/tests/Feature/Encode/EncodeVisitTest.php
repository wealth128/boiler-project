<?php

use App\Enums\Office;
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
 * Encode screen: Save & New (POST /visits) and Save changes
 * (PUT /visits/{visit}). Rules: planning/mvp-scope.md section 2,
 * planning/rbac.md, planning/database-schema.md. Times are Asia/Manila.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));

    $this->army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'is_afp' => true, 'sort_order' => 2]);
    $this->others = Branch::factory()->create(['name' => 'Others', 'is_afp' => false, 'sort_order' => 5]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL']);
    $this->ens = Rank::factory()->for($this->navy)->create(['name' => 'ENS']);
    $this->dependent = Rank::factory()->for($this->others)->create(['name' => 'Dependent']);
    $this->otherType = Rank::factory()->for($this->others)->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 99]);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->otherDiagnosis = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 999]);
});

/**
 * A filled-in encoding form for a new patient.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function encodeForm(object $test, array $overrides = []): array
{
    return [
        'patient_id' => null,
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_initial' => 'm.',
        'sex' => 'Male',
        'birthdate' => '1990-05-01',
        'age' => 36,
        'branch_id' => $test->army->id,
        'rank_id' => $test->cpl->id,
        'rank_other' => null,
        'diagnosis_id' => $test->hypertension->id,
        'diagnosis_other' => null,
        'category' => 'ER',
        'remarks' => 'From ER, admitted to ward',
        ...$overrides,
    ];
}

function encodeCloseMonth(string $period): void
{
    MonthClosure::create([
        'period' => $period,
        'is_closed' => true,
        'closed_by' => userWithRole('admin')->id,
        'closed_at' => now(),
    ]);
}

// --- Save & New -------------------------------------------------------------

it('saves a new patient and their visit', function () {
    $encoder = userWithRole('encoder');

    $this->actingAs($encoder)->from('/encode')
        ->post('/visits', encodeForm($this))
        ->assertRedirect('/encode')
        ->assertSessionHasNoErrors();

    $patient = Patient::sole();
    $visit = Visit::sole();

    expect($patient->patient_no)->toBe(Patient::formatNumber($patient->id))
        ->and($patient->last_name)->toBe('Dela Cruz')
        ->and($patient->middle_initial)->toBe('M')
        ->and($patient->sex->value)->toBe('Male')
        ->and($patient->birthdate->format('Y-m-d'))->toBe('1990-05-01')
        ->and($visit->patient_id)->toBe($patient->id)
        ->and($visit->visit_no)->toBe(Visit::formatNumber($visit->id))
        ->and($visit->age)->toBe(36)
        ->and($visit->branch_id)->toBe($this->army->id)
        ->and($visit->rank_id)->toBe($this->cpl->id)
        ->and($visit->diagnosis_id)->toBe($this->hypertension->id)
        ->and($visit->category->value)->toBe('ER')
        ->and($visit->remarks)->toBe('From ER, admitted to ward')
        ->and($visit->encoded_by)->toBe($encoder->id)
        ->and($visit->updated_by)->toBeNull()
        ->and($visit->visited_at->equalTo(now()))->toBeTrue();

    expect(AuditLog::where('action', 'patient.created')->where('subject_id', $patient->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'visit.created')->where('subject_id', $visit->id)->exists())->toBeTrue();
});

it('shows "Saved P-… · name · category" after saving', function () {
    $response = $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['category' => 'Admission']));

    $patient = Patient::sole();

    $response->assertSessionHas('inertia.flash_data.toast.message', "Saved {$patient->patient_no} · Dela Cruz, Juan M. · Admission");
});

it('ignores a time or encoder sent by the browser', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['visited_at' => '2020-01-01 08:00', 'encoded_by' => 999]));

    $visit = Visit::sole();

    expect($visit->visited_at->format('Y-m-d H:i'))->toBe('2026-10-09 14:00')
        ->and($visit->encoded_by)->not->toBe(999);
});

it('keeps the same Patient ID for a returning patient and corrects their details', function () {
    $patient = Patient::factory()->create(['last_name' => 'Dela Kruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    $old = Visit::factory()->create(['patient_id' => $patient->id, 'visited_at' => now()->subMonth()]);
    $patientNo = $patient->fresh()->patient_no;

    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['patient_id' => $patient->id, 'last_name' => 'Dela Cruz']))
        ->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(1)
        ->and(Visit::count())->toBe(2)
        ->and(Visit::latest('id')->first()->patient_id)->toBe($patient->id)
        ->and($patient->fresh()->patient_no)->toBe($patientNo)
        ->and($patient->fresh()->last_name)->toBe('Dela Cruz')
        // The correction shows on the older visit too: same patient row.
        ->and($old->fresh()->patient->last_name)->toBe('Dela Cruz');

    expect(AuditLog::where('action', 'patient.updated')->where('subject_id', $patient->id)->exists())->toBeTrue();
});

it('does not add a visit to a patient in Trash', function () {
    $patient = Patient::factory()->create();
    $patient->moveToTrash(userWithRole('admin'), 'Duplicate');

    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['patient_id' => $patient->id]))
        ->assertSessionHasErrors('patient_id');

    expect(Visit::count())->toBe(0);
});

it('saves nothing when one part fails', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['diagnosis_id' => 999999]))
        ->assertSessionHasErrors('diagnosis_id');

    expect(Patient::count())->toBe(0)->and(Visit::count())->toBe(0);
});

// --- Field checks -----------------------------------------------------------

it('uses the prototype messages for empty required fields', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', ['category' => 'ER'])
        ->assertSessionHasErrors([
            'last_name' => 'Enter the last name.',
            'first_name' => 'Enter the first name.',
            'sex' => 'Choose Male or Female.',
            'birthdate' => 'Enter the birthdate.',
            'age' => 'Enter an age from 0 to 120.',
            'branch_id' => 'Choose the branch of service.',
            'rank_id' => 'Choose the rank.',
            'diagnosis_id' => 'Choose a diagnosis.',
        ]);
});

it('asks for the patient type when the branch is Others', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['branch_id' => $this->others->id, 'rank_id' => null]))
        ->assertSessionHasErrors(['rank_id' => 'Choose the patient type.']);
});

it('rejects a birthdate in the future', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['birthdate' => '2026-10-10']))
        ->assertSessionHasErrors(['birthdate' => "The birthdate can't be in the future."]);

    $this->post('/visits', encodeForm($this, ['birthdate' => '2026-10-09', 'age' => 0]))
        ->assertSessionHasNoErrors();
});

it('accepts ages from 0 to 120 only', function (mixed $age, bool $ok) {
    $response = $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['age' => $age]));

    $ok ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors(['age' => 'Enter an age from 0 to 120.']);
})->with([
    [0, true], [120, true], [-1, false], [121, false], ['34.5', false], ['abc', false],
]);

it('saves M.I. uppercase without periods, and allows at most 2 letters', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['middle_initial' => 'd.c.']))
        ->assertSessionHasNoErrors();

    expect(Patient::sole()->middle_initial)->toBe('DC');

    $this->post('/visits', encodeForm($this, ['middle_initial' => 'abc']))
        ->assertSessionHasErrors(['middle_initial' => 'Enter up to 2 letters.']);
    $this->post('/visits', encodeForm($this, ['middle_initial' => '1']))
        ->assertSessionHasErrors(['middle_initial' => 'Enter up to 2 letters.']);
    $this->post('/visits', encodeForm($this, ['first_name' => 'Pedro', 'middle_initial' => '']))
        ->assertSessionHasNoErrors();
});

// --- Lists ----------------------------------------------------------------------

it('rejects a rank from another branch', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['branch_id' => $this->army->id, 'rank_id' => $this->ens->id]))
        ->assertSessionHasErrors(['rank_id' => 'Choose a rank from the Army list.']);

    expect(Visit::count())->toBe(0);
});

it('requires the text when "Other (specify)" is chosen', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, [
            'branch_id' => $this->others->id,
            'rank_id' => $this->otherType->id,
            'rank_other' => '',
            'diagnosis_id' => $this->otherDiagnosis->id,
            'diagnosis_other' => '  ',
        ]))
        ->assertSessionHasErrors([
            'rank_other' => 'Type the patient type.',
            'diagnosis_other' => 'Type the diagnosis.',
        ]);

    expect(Visit::count())->toBe(0);
});

it('saves the "Other (specify)" text, and only for that entry', function () {
    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, [
            'branch_id' => $this->others->id,
            'rank_id' => $this->otherType->id,
            'rank_other' => 'Contractor',
            'diagnosis_id' => $this->otherDiagnosis->id,
            'diagnosis_other' => 'Heat exhaustion',
        ]))
        ->assertSessionHasNoErrors();

    expect(Visit::sole())
        ->rank_other->toBe('Contractor')
        ->diagnosis_other->toBe('Heat exhaustion');

    // Text left over from switching back to a normal entry is dropped.
    $this->post('/visits', encodeForm($this, ['first_name' => 'Pedro', 'rank_other' => 'Leftover', 'diagnosis_other' => 'Leftover']))
        ->assertSessionHasNoErrors();

    expect(Visit::latest('id')->first())
        ->rank_other->toBeNull()
        ->diagnosis_other->toBeNull();
});

it('rejects list items that were removed or switched off', function () {
    $admin = userWithRole('admin');
    $this->cpl->moveToTrash($admin, 'Wrong');
    $this->hypertension->moveToTrash($admin, 'Wrong');
    $this->navy->update(['is_active' => false]);

    $this->actingAs(userWithRole('encoder'));

    $this->post('/visits', encodeForm($this))
        ->assertSessionHasErrors([
            'rank_id' => 'This rank was removed from the list. Choose another.',
            'diagnosis_id' => 'This diagnosis was removed from the list. Choose another.',
        ]);

    $this->post('/visits', encodeForm($this, ['branch_id' => $this->navy->id, 'rank_id' => $this->ens->id]))
        ->assertSessionHasErrors(['branch_id' => 'This branch is turned off. Choose another.']);

    expect(Visit::count())->toBe(0);
});

it('keeps a removed item on a visit that is edited without changing it', function () {
    $admin = userWithRole('admin');
    $encoder = userWithRole('encoder');
    $visit = Visit::factory()->create([
        'encoded_by' => $encoder->id,
        'visited_at' => now()->setTime(8, 0),
        'rank_id' => $this->cpl->id,
        'branch_id' => $this->army->id,
        'diagnosis_id' => $this->hypertension->id,
        'category' => 'ER',
    ]);
    $this->cpl->moveToTrash($admin, 'Renamed');
    $this->hypertension->moveToTrash($admin, 'Renamed');
    $this->army->update(['is_active' => false]);

    $this->actingAs($encoder)
        ->put("/visits/{$visit->id}", encodeForm($this, ['remarks' => 'Corrected']))
        ->assertSessionHasNoErrors();

    expect($visit->fresh()->remarks)->toBe('Corrected');

    // The edit page still offers them, so the form can show them.
    $this->get("/encode?edit={$visit->id}")->assertInertia(fn (Assert $page) => $page
        ->where('editing.rank_id', $this->cpl->id)
        ->where('ranks', fn ($ranks) => collect($ranks)->contains('id', $this->cpl->id))
        ->where('diagnoses', fn ($list) => collect($list)->contains('id', $this->hypertension->id))
        ->where('branches', fn ($list) => collect($list)->contains('id', $this->army->id)));

    // A new visit is not offered them.
    $this->get('/encode')->assertInertia(fn (Assert $page) => $page
        ->where('ranks', fn ($ranks) => ! collect($ranks)->contains('id', $this->cpl->id))
        ->where('diagnoses', fn ($list) => ! collect($list)->contains('id', $this->hypertension->id))
        ->where('branches', fn ($list) => ! collect($list)->contains('id', $this->army->id)));
});

// --- Category -------------------------------------------------------------------

it("locks a non-ER encoder to their office's category", function () {
    $opd = User::factory()->encoder(Office::OPD)->create();

    $this->actingAs($opd)
        ->post('/visits', encodeForm($this, ['category' => 'ER']))
        ->assertSessionHasErrors(['category' => 'The category is set by your office (OPD).']);

    expect(Visit::count())->toBe(0);

    $this->post('/visits', encodeForm($this, ['category' => 'OPD']))->assertSessionHasNoErrors();
    expect(Visit::sole()->category->value)->toBe('OPD');
});

it('lets ER encoders and Admin choose any category', function (string $role) {
    $this->actingAs(userWithRole($role));

    foreach (['OPD', 'ER', 'Admission'] as $category) {
        $this->post('/visits', encodeForm($this, ['first_name' => "Juan {$category}", 'category' => $category]))->assertSessionHasNoErrors();
    }

    expect(Visit::pluck('category')->map->value->all())->toBe(['OPD', 'ER', 'Admission']);
})->with(['encoder', 'admin']);

it('rejects an unknown category', function () {
    $this->actingAs(userWithRole('admin'))
        ->post('/visits', encodeForm($this, ['category' => 'Ward']))
        ->assertSessionHasErrors('category');
});

// --- Save changes -----------------------------------------------------------------

it('lets an encoder edit their own visit the same day', function () {
    $encoder = userWithRole('encoder');
    $patient = Patient::factory()->create(['first_name' => 'Jaun']);
    $visit = Visit::factory()->create([
        'patient_id' => $patient->id,
        'encoded_by' => $encoder->id,
        'visited_at' => now()->setTime(8, 0),
        'category' => 'ER',
    ]);

    $this->actingAs($encoder)->from("/encode?edit={$visit->id}")
        ->put("/visits/{$visit->id}", encodeForm($this, ['first_name' => 'Juan', 'age' => 40, 'category' => 'Admission']))
        ->assertRedirect('/encode')
        ->assertSessionHasNoErrors();

    $visit->refresh();

    expect($visit->age)->toBe(40)
        ->and($visit->category->value)->toBe('Admission')
        ->and($visit->updated_by)->toBe($encoder->id)
        ->and($visit->encoded_by)->toBe($encoder->id)
        ->and($visit->visited_at->format('H:i'))->toBe('08:00')
        ->and($visit->patient_id)->toBe($patient->id)
        ->and($patient->fresh()->first_name)->toBe('Juan');

    expect(AuditLog::where('action', 'visit.updated')->where('subject_id', $visit->id)->exists())->toBeTrue();
});

it("blocks an encoder from editing yesterday's visit or someone else's", function () {
    $encoder = userWithRole('encoder');
    $yesterday = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()->subDay()]);
    $others = Visit::factory()->create(['visited_at' => now()]);

    $this->actingAs($encoder);

    $this->put("/visits/{$yesterday->id}", encodeForm($this))->assertForbidden();
    $this->put("/visits/{$others->id}", encodeForm($this))->assertForbidden();
    $this->get("/encode?edit={$yesterday->id}")->assertForbidden();
});

it('lets Admin edit any visit', function () {
    $visit = Visit::factory()->create(['visited_at' => now()->subMonths(2)]);

    $this->actingAs(userWithRole('admin'))
        ->put("/visits/{$visit->id}", encodeForm($this, ['remarks' => 'Fixed by Admin']))
        ->assertSessionHasNoErrors();

    expect($visit->fresh()->remarks)->toBe('Fixed by Admin');
});

it('blocks every edit while the month is closed', function (string $role) {
    encodeCloseMonth('2026-10');
    $user = userWithRole($role);
    $visit = Visit::factory()->create(['encoded_by' => $user->id, 'visited_at' => now()->setTime(8, 0), 'remarks' => null]);

    $this->actingAs($user)
        ->put("/visits/{$visit->id}", encodeForm($this, ['remarks' => 'Too late']))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('message', 'October 2026 is closed. The Admin must reopen it first.'));

    expect($visit->fresh()->remarks)->toBeNull();

    $this->get("/encode?edit={$visit->id}")->assertForbidden();

    // "My entries today" hides Edit on it.
    $this->get('/encode')->assertInertia(fn (Assert $page) => $page
        ->where('todayEntries.0.id', $visit->id)
        ->where('todayEntries.0.can_edit', false));
})->with(['encoder', 'admin']);

it('can not edit a visit in Trash', function () {
    $encoder = userWithRole('encoder');
    $visit = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()]);
    $visit->moveToTrash(userWithRole('admin'), 'Duplicate');

    $this->actingAs($encoder)->put("/visits/{$visit->id}", encodeForm($this))->assertNotFound();
    $this->get("/encode?edit={$visit->id}")->assertNotFound();
});

// --- Who can use it -----------------------------------------------------------------

it('gives System Admin and the Viewer 403 on every Encode route', function (string $role) {
    $user = userWithRole($role);
    $visit = Visit::factory()->create(['encoded_by' => $user->id, 'visited_at' => now()]);

    $this->actingAs($user);

    $this->get('/encode')->assertForbidden();
    $this->getJson('/patients/search?q=dela')->assertForbidden();
    $this->post('/visits', encodeForm($this))->assertForbidden();
    $this->put("/visits/{$visit->id}", encodeForm($this))->assertForbidden();

    expect(Visit::count())->toBe(1)->and(Patient::count())->toBe(1);
})->with(['system_admin', 'viewer']);

// --- The page -------------------------------------------------------------------------

it('shows the form with active lists, the category rule and a Patient ID preview', function () {
    $last = Patient::factory()->create();
    $this->dependent->moveToTrash(userWithRole('admin'), 'Old');

    $this->actingAs(User::factory()->encoder(Office::OPD)->create())
        ->get('/encode')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('encode/index')
            ->where('office', 'OPD')
            ->where('defaultCategory', 'OPD')
            ->where('canChooseCategory', false)
            ->where('categories', ['OPD', 'ER', 'Admission'])
            ->where('nextPatientNo', Patient::formatNumber($last->id + 1))
            ->where('editing', null)
            ->where('branches.0.name', 'Army')
            ->where('ranks', fn ($ranks) => ! collect($ranks)->contains('id', $this->dependent->id))
            ->has('diagnoses', 2));
});

it("lists only this user's visits from today, newest first, without Trash", function () {
    $encoder = userWithRole('encoder');
    $early = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()->setTime(7, 5)]);
    $late = Visit::factory()->create([
        'encoded_by' => $encoder->id,
        'visited_at' => now()->setTime(9, 55),
        'diagnosis_id' => $this->otherDiagnosis->id,
        'diagnosis_other' => 'Heat exhaustion',
    ]);
    $trashed = Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()->setTime(8, 0)]);
    $trashed->moveToTrash(userWithRole('admin'), 'Duplicate');
    Visit::factory()->create(['encoded_by' => $encoder->id, 'visited_at' => now()->subDay()]);
    Visit::factory()->create(['visited_at' => now()]);

    $this->actingAs($encoder)->get('/encode')->assertInertia(fn (Assert $page) => $page
        ->has('todayEntries', 2)
        ->where('todayEntries.0.id', $late->id)
        ->where('todayEntries.0.diagnosis', 'Other: Heat exhaustion')
        ->where('todayEntries.0.can_edit', true)
        ->where('todayEntries.1.id', $early->id));
});

it('loads a visit into the form for editing', function () {
    $encoder = userWithRole('encoder');
    $patient = Patient::factory()->create(['last_name' => 'Reyes', 'middle_initial' => 'L', 'birthdate' => '1985-02-03']);
    $visit = Visit::factory()->create([
        'patient_id' => $patient->id,
        'encoded_by' => $encoder->id,
        'visited_at' => now()->setTime(8, 40),
        'branch_id' => $this->others->id,
        'rank_id' => $this->otherType->id,
        'rank_other' => 'Contractor',
        'age' => 41,
    ]);

    $this->actingAs($encoder)->get("/encode?edit={$visit->id}")->assertInertia(fn (Assert $page) => $page
        ->where('editing.id', $visit->id)
        ->where('editing.visit_no', $visit->fresh()->visit_no)
        ->where('editing.patient.patient_no', $patient->fresh()->patient_no)
        ->where('editing.patient.birthdate', '1985-02-03')
        ->where('editing.age', 41)
        ->where('editing.rank_other', 'Contractor'));
});

it('answers 404 for a bad edit value', function () {
    $this->actingAs(userWithRole('admin'));

    $this->get('/encode?edit=abc')->assertNotFound();
    $this->get('/encode?edit=999999')->assertNotFound();
});

// --- Duplicate patient guard --------------------------------------------------------

it('stops a new patient whose name and birthdate are already on file', function () {
    $existing = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);

    $this->actingAs(userWithRole('encoder'))
        // Upper / lower case and extra spaces don't hide a match.
        ->post('/visits', encodeForm($this, ['last_name' => 'DELA CRUZ', 'first_name' => ' juan ']))
        ->assertSessionHasErrors(['duplicate_patient' => 'A patient with this name and birthdate already exists.']);

    expect(Patient::count())->toBe(1)->and(Visit::count())->toBe(0);

    // "Use this patient": the same form with the existing patient saves fine.
    $this->post('/visits', encodeForm($this, ['patient_id' => $existing->id]))
        ->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(1)->and(Visit::sole()->patient_id)->toBe($existing->id);
});

it('saves a different person with the same name and birthdate when the encoder confirms', function () {
    Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);

    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['confirm_new_patient' => true]))
        ->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(2)->and(Visit::count())->toBe(1);
});

it('does not count a different birthdate, a different first name, or a patient in Trash as a duplicate', function () {
    Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-02']);
    Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juana', 'birthdate' => '1990-05-01']);
    $trashed = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    $trashed->moveToTrash(userWithRole('admin'), 'Duplicate');

    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this))
        ->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(1);
});

it('waits for the other field errors before checking for a duplicate', function () {
    Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);

    $this->actingAs(userWithRole('encoder'))
        ->post('/visits', encodeForm($this, ['birthdate' => '']))
        ->assertSessionHasErrors('birthdate')
        ->assertSessionDoesntHaveErrors('duplicate_patient');
});
