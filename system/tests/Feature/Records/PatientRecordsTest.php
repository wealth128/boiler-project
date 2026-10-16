<?php

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Patient Records, Patients tab (GET /records/patients) and moving a
 * patient to Trash from it (DELETE /patients/{patient}). A patient goes to
 * Trash only after all their visits are in Trash (TrashPolicy).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
});

/**
 * Patient IDs on the page, in order.
 *
 * @return list<string>
 */
function patientNumbersOn($response): array
{
    return collect($response->inertiaProps('patients.data'))->pluck('patient_no')->all();
}

// --- Who may open it -------------------------------------------------------

it('shows the Patients tab to Admin', function () {
    $patient = Patient::factory()->create([
        'last_name' => 'Santos', 'first_name' => 'Maria', 'middle_initial' => 'L',
        'sex' => 'Female', 'birthdate' => '1990-05-01',
    ]);
    Visit::factory()->count(2)->for($patient)->create();

    $this->actingAs($this->admin)
        ->get('/records/patients')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('records/patients')
            ->where('filters', ['q' => ''])
            ->where('patients.data', [[
                'id' => $patient->id,
                'patient_no' => $patient->patient_no,
                'patient_name' => 'Santos, Maria L.',
                'sex' => 'Female',
                'birthdate' => '1990-05-01',
                'visit_count' => 2,
            ]])
            ->where('patients.meta.total', 1));
});

it('refuses the Patients tab to every other role', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/records/patients')->assertForbidden();
})->with(['encoder', 'viewer', 'system_admin']);

// --- List ------------------------------------------------------------------

it('sorts patients by last name, then first name', function () {
    $reyes = Patient::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Jose']);
    $aquinoMaria = Patient::factory()->create(['last_name' => 'Aquino', 'first_name' => 'Maria']);
    $aquinoAna = Patient::factory()->create(['last_name' => 'Aquino', 'first_name' => 'Ana']);

    $response = $this->actingAs($this->admin)->get('/records/patients');

    expect(patientNumbersOn($response))->toBe([$aquinoAna->patient_no, $aquinoMaria->patient_no, $reyes->patient_no]);
});

it('never shows patients in Trash', function () {
    $kept = Patient::factory()->create();
    Patient::factory()->create()->moveToTrash($this->admin, 'Duplicate patient');

    $response = $this->actingAs($this->admin)->get('/records/patients');

    expect(patientNumbersOn($response))->toBe([$kept->patient_no]);
});

it('counts only visits that are not in Trash', function () {
    $patient = Patient::factory()->create();
    Visit::factory()->count(2)->for($patient)->create();
    Visit::factory()->for($patient)->create()->moveToTrash($this->admin, 'Duplicate entry');

    $this->actingAs($this->admin)
        ->get('/records/patients')
        ->assertInertia(fn (Assert $page) => $page->where('patients.data.0.visit_count', 2));
});

it('searches by name or Patient ID', function (string $q, array $expected) {
    $patients = [
        'santos' => Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria']),
        'reyes' => Patient::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Jose']),
    ];

    $q = strtr($q, ['{reyes_no}' => $patients['reyes']->patient_no]);

    $response = $this->actingAs($this->admin)->get('/records/patients?q='.urlencode($q));

    expect(patientNumbersOn($response))->toBe(array_map(fn ($key) => $patients[$key]->patient_no, $expected));
})->with([
    'last name' => ['santos', ['santos']],
    'first name' => ['jose', ['reyes']],
    'last and first name' => ['Reyes Jo', ['reyes']],
    'Patient ID' => ['{reyes_no}', ['reyes']],
    'a Visit ID' => ['V-000001', []],
    'no match' => ['Zamora', []],
]);

it('shows 10 patients per page by default, with page numbers', function () {
    foreach (range(1, 25) as $n) {
        Patient::factory()->create(['last_name' => sprintf('Patient%02d', $n)]);
    }

    $this->actingAs($this->admin)
        ->get('/records/patients')
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 10)
            ->where('patients.meta', ['current_page' => 1, 'last_page' => 3, 'per_page' => 10, 'total' => 25, 'from' => 1, 'to' => 10]));

    $this->actingAs($this->admin)
        ->get('/records/patients?page=3')
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 5)
            ->where('patients.data.4.patient_name', fn (string $name) => str_starts_with($name, 'Patient25, ')));
});

it('shows 20 or 50 patients per page when asked, and 10 for any other value', function (string $query, int $rows, int $perPage) {
    foreach (range(1, 60) as $n) {
        Patient::factory()->create(['last_name' => sprintf('Patient%02d', $n)]);
    }

    $this->actingAs($this->admin)
        ->get('/records/patients'.$query)
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', $rows)
            ->where('patients.meta.per_page', $perPage));
})->with([
    '20' => ['?per_page=20', 20, 20],
    '50' => ['?per_page=50', 50, 50],
    '10' => ['?per_page=10', 10, 10],
    '999' => ['?per_page=999', 10, 10],
    '0' => ['?per_page=0', 10, 10],
    'negative' => ['?per_page=-20', 10, 10],
    'text' => ['?per_page=abc', 10, 10],
    'decimal' => ['?per_page=20.5', 10, 10],
    'empty' => ['?per_page=', 10, 10],
    'a list' => ['?per_page[]=20', 10, 10],
]);

it('keeps the rows per page and the search when it goes to the last page', function () {
    Patient::factory()->create(['last_name' => 'Zamora']);

    $this->actingAs($this->admin)
        ->get('/records/patients?q=zamora&per_page=20&page=4')
        ->assertRedirect('/records/patients?q=zamora&per_page=20&page=1');
});

// --- Move to Trash -----------------------------------------------------------

it('keeps the rows per page after moving a patient to Trash', function () {
    $patient = Patient::factory()->create();

    $this->actingAs($this->admin)->from('/records/patients?q=x&per_page=20&page=2')
        ->delete("/patients/{$patient->id}", ['reason' => 'Duplicate patient record'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/records/patients?q=x&per_page=20&page=2');
});

it('moves a patient with no active visits to Trash', function () {
    $patient = Patient::factory()->create();
    Visit::factory()->for($patient)->create()->moveToTrash($this->admin, 'Duplicate entry');

    $this->actingAs($this->admin)->from('/records/patients?q=x')
        ->delete("/patients/{$patient->id}", ['reason' => 'Duplicate patient record'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/records/patients?q=x')
        ->assertSessionHas('inertia.flash_data.toast.message', "Moved {$patient->patient_no} to Trash");

    $trashed = Patient::onlyTrashed()->findOrFail($patient->id);

    expect($trashed->deleted_by)->toBe($this->admin->id)
        ->and($trashed->delete_reason)->toBe('Duplicate patient record')
        ->and(AuditLog::where('action', 'patient.deleted')->where('subject_id', $patient->id)->exists())->toBeTrue();
});

it('needs a reason from the patient list', function (array $data) {
    $patient = Patient::factory()->create();

    $this->actingAs($this->admin)->from('/records/patients')
        ->delete("/patients/{$patient->id}", $data)
        ->assertRedirect('/records/patients')
        ->assertSessionHasErrors(['reason' => 'Choose a reason from the list.']);

    expect($patient->fresh()->trashed())->toBeFalse();
})->with([
    'empty' => [['reason' => '']],
    'free text' => [['reason' => 'Duplicate patient']],
    'from the visit list' => [['reason' => 'Wrong patient selected']],
]);

it('needs at least 10 characters for "Other (specify)" on a patient', function () {
    $patient = Patient::factory()->create();

    $this->actingAs($this->admin)->from('/records/patients')
        ->delete("/patients/{$patient->id}", ['reason' => 'Other (specify)', 'reason_other' => 'Too short'])
        ->assertSessionHasErrors(['reason_other' => 'Describe the reason in 10 to 200 characters.']);

    expect($patient->fresh()->trashed())->toBeFalse();
});

it('saves "Other (specify)" on a patient as "Other: <text>"', function () {
    $patient = Patient::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/patients/{$patient->id}", ['reason' => 'Other (specify)', 'reason_other' => 'Merged into another record'])
        ->assertSessionHasNoErrors();

    expect(Patient::onlyTrashed()->findOrFail($patient->id)->delete_reason)->toBe('Other: Merged into another record');
});

it('offers the patient reasons', function () {
    $this->actingAs($this->admin)
        ->get('/records/patients')
        ->assertInertia(fn (Assert $page) => $page->where('deleteReasons', [
            ['value' => 'Duplicate patient record', 'other' => false],
            ['value' => 'Encoded by mistake', 'other' => false],
            ['value' => 'Test / training entry', 'other' => false],
            ['value' => 'Other (specify)', 'other' => true],
        ]));
});

it('refuses to move a patient to Trash while they have visits not in Trash', function () {
    $patient = Patient::factory()->create();
    Visit::factory()->for($patient)->create();
    Visit::factory()->for($patient)->create()->moveToTrash($this->admin, 'Duplicate entry');

    $this->actingAs($this->admin)->from('/records/patients')
        ->delete("/patients/{$patient->id}", ['reason' => 'Duplicate patient record'])
        ->assertRedirect('/records/patients')
        ->assertSessionHasErrors(['item' => "Move this patient's visits to Trash first."]);

    expect($patient->fresh()->trashed())->toBeFalse()
        ->and(AuditLog::where('action', 'patient.deleted')->exists())->toBeFalse();
});

it("doesn't let other roles move a patient to Trash", function (string $role) {
    $patient = Patient::factory()->create();

    $this->actingAs(userWithRole($role))
        ->delete("/patients/{$patient->id}", ['reason' => 'Duplicate patient record'])
        ->assertForbidden();

    expect($patient->fresh()->trashed())->toBeFalse();
})->with(['encoder', 'viewer', 'system_admin']);
