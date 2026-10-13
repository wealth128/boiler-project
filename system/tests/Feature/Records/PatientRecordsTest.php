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

it('shows 50 patients per page with page numbers', function () {
    foreach (range(1, 51) as $n) {
        Patient::factory()->create(['last_name' => sprintf('Patient%02d', $n)]);
    }

    $this->actingAs($this->admin)
        ->get('/records/patients')
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 50)
            ->where('patients.meta.last_page', 2)
            ->where('patients.meta.total', 51));

    $this->actingAs($this->admin)
        ->get('/records/patients?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('patients.data', 1)
            ->where('patients.data.0.patient_name', fn (string $name) => str_starts_with($name, 'Patient51, ')));
});

// --- Move to Trash -----------------------------------------------------------

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
