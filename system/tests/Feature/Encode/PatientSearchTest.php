<?php

use App\Models\Branch;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Visit;
use Illuminate\Support\Carbon;

/*
 * "Find returning patient" (GET /patients/search?q=): by last name or
 * Patient ID, at most 10 results, patients in Trash left out.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));
    $this->actingAs(userWithRole('encoder'));
});

it('finds patients by the start of the last name', function () {
    $santos = Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'middle_initial' => 'L', 'sex' => 'Female', 'birthdate' => '1990-05-01']);
    Patient::factory()->create(['last_name' => 'Dela Santos']);

    $this->getJson('/patients/search?q=sant')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', [
            'id' => $santos->id,
            'patient_no' => $santos->fresh()->patient_no,
            'last_name' => 'Santos',
            'first_name' => 'Maria',
            'middle_initial' => 'L',
            'sex' => 'Female',
            'birthdate' => '1990-05-01',
            'full_name' => 'Santos, Maria L.',
            'last_visit' => null,
        ]);
});

it('narrows by first name after the last name', function () {
    Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria']);
    $mark = Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Mark']);

    $this->getJson('/patients/search?q='.urlencode('Santos Mar'))->assertJsonCount(2, 'data');
    $this->getJson('/patients/search?q='.urlencode('santos, mark'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mark->id);
});

it('finds a patient by Patient ID', function (string $typed) {
    Patient::factory()->count(3)->create();
    $patient = Patient::factory()->create();
    $patientNo = $patient->fresh()->patient_no; // e.g. P-000012

    $query = match ($typed) {
        'as shown' => $patientNo,        // P-000012
        'lowercase' => strtolower($patientNo), // p-000012
        'digits only' => substr($patientNo, 2), // 000012
    };

    $this->getJson('/patients/search?q='.urlencode($query))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $patient->id);
})->with(['as shown', 'lowercase', 'digits only']);

it('leaves out patients in Trash', function () {
    $kept = Patient::factory()->create(['last_name' => 'Reyes']);
    $gone = Patient::factory()->create(['last_name' => 'Reyes']);
    $gone->moveToTrash(userWithRole('admin'), 'Duplicate');

    $this->getJson('/patients/search?q=reyes')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->id);

    $this->getJson('/patients/search?q='.$gone->fresh()->patient_no)->assertJsonCount(0, 'data');
});

it('returns at most 10 results, sorted by name', function () {
    foreach (range(1, 12) as $i) {
        Patient::factory()->create(['last_name' => 'Garcia', 'first_name' => sprintf('Name%02d', 13 - $i)]);
    }

    $response = $this->getJson('/patients/search?q=garcia')->assertJsonCount(10, 'data');

    expect($response->json('data.0.first_name'))->toBe('Name01')
        ->and($response->json('data.9.first_name'))->toBe('Name10');
});

it('waits for at least 2 characters', function () {
    Patient::factory()->create(['last_name' => 'Aquino']);

    $this->getJson('/patients/search?q=a')->assertJsonCount(0, 'data');
    $this->getJson('/patients/search?q=')->assertJsonCount(0, 'data');
});

it('treats % and _ as plain characters', function () {
    Patient::factory()->create(['last_name' => 'Aquino']);

    $this->getJson('/patients/search?q='.urlencode('%%'))->assertJsonCount(0, 'data');
    $this->getJson('/patients/search?q=__')->assertJsonCount(0, 'data');
});

it("includes the branch and rank of the patient's latest visit not in Trash", function () {
    $patient = Patient::factory()->create(['last_name' => 'Torres']);
    $army = Branch::factory()->create();
    $navy = Branch::factory()->create();
    $cpl = Rank::factory()->for($army)->create();
    $ens = Rank::factory()->for($navy)->create();

    Visit::factory()->create(['patient_id' => $patient->id, 'visited_at' => now()->subMonths(2), 'rank_id' => $cpl->id, 'branch_id' => $army->id]);
    Visit::factory()->create(['patient_id' => $patient->id, 'visited_at' => now()->subMonth(), 'rank_id' => $ens->id, 'branch_id' => $navy->id, 'rank_other' => null]);
    $newestButTrashed = Visit::factory()->create(['patient_id' => $patient->id, 'visited_at' => now()->subDay(), 'rank_id' => $cpl->id, 'branch_id' => $army->id]);
    $newestButTrashed->moveToTrash(userWithRole('admin'), 'Duplicate');

    $this->getJson('/patients/search?q=torres')
        ->assertJsonPath('data.0.last_visit', [
            'branch_id' => $navy->id,
            'rank_id' => $ens->id,
            'rank_other' => null,
        ]);
});
