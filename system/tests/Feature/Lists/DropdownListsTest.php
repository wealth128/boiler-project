<?php

use App\Enums\ListItemDeleteReason;
use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Dropdown Lists (GET /settings/lists, POST /settings/{list},
 * PUT /settings/{list}/{id}, DELETE /settings/{list}/{id},
 * PUT /settings/ranks/{id}/move). Admin only.
 * Rules: planning/mvp-scope.md "Dropdown lists", planning/database-schema.md.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
    $this->army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'is_afp' => true, 'sort_order' => 2]);
    $this->others = Branch::factory()->create(['name' => 'Others', 'is_afp' => false, 'sort_order' => 3]);

    $this->col = Rank::factory()->for($this->army)->create(['name' => 'COL', 'sort_order' => 1]);
    $this->maj = Rank::factory()->for($this->army)->create(['name' => 'MAJ', 'sort_order' => 2]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL', 'sort_order' => 3]);
    $this->dependent = Rank::factory()->for($this->others)->create(['name' => 'Dependent', 'sort_order' => 1]);
    $this->otherType = Rank::factory()->for($this->others)->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 2]);

    $this->otherDiagnosis = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 999]);
    $this->pneumonia = Diagnosis::factory()->create(['name' => 'Pneumonia', 'sort_order' => 1]);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension', 'sort_order' => 2]);

    $this->adult = AgeBracket::create(['min_age' => 18, 'max_age' => 25]);
    $this->child = AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    $this->senior = AgeBracket::create(['min_age' => 60, 'max_age' => null]);
});

/** @return array{reason: string, reason_other: string} */
function listsReason(): array
{
    return ['reason' => ListItemDeleteReason::NoLongerUsed->value, 'reason_other' => ''];
}

/**
 * Names of one branch's ranks as the Encode screen lists them.
 *
 * @return list<string>
 */
function listsEncodeRanks(object $test, Branch $branch): array
{
    $ranks = test()->actingAs($test->admin)->get('/encode')->inertiaProps('ranks');

    return collect($ranks)->where('branch_id', $branch->id)->pluck('name')->values()->all();
}

/** @return list<string> */
function listsEncodeDiagnoses(object $test): array
{
    return collect(test()->actingAs($test->admin)->get('/encode')->inertiaProps('diagnoses'))->pluck('name')->all();
}

function listsVisit(object $test, array $attributes = []): Visit
{
    return Visit::factory()->create([
        'patient_id' => Patient::factory(),
        'branch_id' => $test->army->id,
        'rank_id' => $test->cpl->id,
        'diagnosis_id' => $test->hypertension->id,
        'encoded_by' => $test->admin->id,
        'category' => 'ER',
        'visited_at' => now()->subDay(),
        ...$attributes,
    ]);
}

// --- Who may open it ---------------------------------------------------------

it('shows Admin the active items of each list in the right order', function () {
    Diagnosis::factory()->create(['name' => 'Asthma'])->moveToTrash($this->admin, 'No longer used');
    AgeBracket::create(['min_age' => 26, 'max_age' => 35])->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)
        ->get('/settings/lists')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lists/index')
            // Alphabetical, "Other (specify)" last; nothing from Trash.
            ->where('diagnoses', [
                ['id' => $this->hypertension->id, 'name' => 'Hypertension', 'is_other' => false],
                ['id' => $this->pneumonia->id, 'name' => 'Pneumonia', 'is_other' => false],
                ['id' => $this->otherDiagnosis->id, 'name' => 'Other (specify)', 'is_other' => true],
            ])
            // By From age.
            ->where('ageBrackets', [
                ['id' => $this->child->id, 'min_age' => 0, 'max_age' => 17, 'label' => '0–17'],
                ['id' => $this->adult->id, 'min_age' => 18, 'max_age' => 25, 'label' => '18–25'],
                ['id' => $this->senior->id, 'min_age' => 60, 'max_age' => null, 'label' => '60 & above'],
            ])
            ->where('branches.0.name', 'Army')
            ->where('branches.0.ranks', [
                ['id' => $this->col->id, 'name' => 'COL', 'is_other' => false],
                ['id' => $this->maj->id, 'name' => 'MAJ', 'is_other' => false],
                ['id' => $this->cpl->id, 'name' => 'CPL', 'is_other' => false],
            ])
            ->where('branches.1.ranks', [])
            ->where('branches.2.ranks.1.name', 'Other (specify)')
            ->where('limits', ['name_min' => 2, 'diagnosis_name_max' => 120, 'rank_name_max' => 60, 'age_min' => 0, 'age_max' => 120])
            ->has('deleteReasons', 4));
});

it('gives every other role a 403', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/settings/lists')->assertForbidden();
    $this->actingAs($user)->post('/settings/diagnoses', ['name' => 'Asthma'])->assertForbidden();
    $this->actingAs($user)->put("/settings/diagnoses/{$this->pneumonia->id}", ['name' => 'Pneumonia X'])->assertForbidden();
    $this->actingAs($user)->delete("/settings/diagnoses/{$this->pneumonia->id}", listsReason())->assertForbidden();
    $this->actingAs($user)->put("/settings/ranks/{$this->cpl->id}/move", ['direction' => 'up'])->assertForbidden();

    expect(Diagnosis::where('name', 'Asthma')->exists())->toBeFalse()
        ->and($this->pneumonia->fresh()->name)->toBe('Pneumonia')
        ->and($this->pneumonia->fresh()->trashed())->toBeFalse();
})->with(['encoder', 'system_admin', 'viewer']);

it('answers 404 for an unknown list', function () {
    $this->actingAs($this->admin)->post('/settings/colors', ['name' => 'Red'])->assertNotFound();
});

// --- Diagnoses ---------------------------------------------------------------

it('adds a diagnosis with spaces cleaned up', function () {
    $this->actingAs($this->admin)
        ->post('/settings/diagnoses', ['name' => '  Acute   Gastroenteritis '])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/lists')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Added “Acute Gastroenteritis”');

    $added = Diagnosis::where('name', 'Acute Gastroenteritis')->first();

    expect($added)->not->toBeNull()
        ->and($added->is_other)->toBeFalse()
        ->and(AuditLog::where('action', 'diagnosis.created')->where('subject_id', $added->id)->exists())->toBeTrue()
        ->and(listsEncodeDiagnoses($this))->toBe(['Acute Gastroenteritis', 'Hypertension', 'Pneumonia', 'Other (specify)']);
});

it('checks the diagnosis name length', function (string $name, ?string $error) {
    $response = $this->actingAs($this->admin)->post('/settings/diagnoses', ['name' => $name]);

    $error === null
        ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors(['name' => $error]);
})->with([
    'empty' => ['   ', 'Type the diagnosis to add.'],
    'one letter' => ['A', 'Use 2 to 120 characters.'],
    'two letters' => ['AB', null],
    '120 characters' => [str_repeat('a', 120), null],
    '121 characters' => [str_repeat('a', 121), 'Use 2 to 120 characters.'],
]);

it('rejects a diagnosis name already in the list, ignoring case', function () {
    $this->actingAs($this->admin)
        ->post('/settings/diagnoses', ['name' => 'HYPERTENSION'])
        ->assertSessionHasErrors(['name' => '“Hypertension” is already in the list.']);

    expect(Diagnosis::withTrashed()->count())->toBe(3);
});

it('points to Trash when the same diagnosis name is in Trash', function () {
    $this->pneumonia->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)
        ->post('/settings/diagnoses', ['name' => 'pneumonia'])
        ->assertSessionHasErrors(['name' => '“Pneumonia” is in Trash. Restore it from Trash instead.']);
});

it('fixes the spelling of a diagnosis everywhere it is used', function () {
    $visit = listsVisit($this);

    $this->actingAs($this->admin)
        ->put("/settings/diagnoses/{$this->hypertension->id}", ['name' => 'Hypertension, Essential'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Renamed to “Hypertension, Essential”');

    $diagnosis = collect($this->get('/records/visits')->inertiaProps('visits.data'))->firstWhere('id', $visit->id)['diagnosis'];

    expect($diagnosis)->toBe('Hypertension, Essential')
        ->and(AuditLog::where('action', 'diagnosis.updated')->first()->changes)
        ->toEqual(['old' => ['name' => 'Hypertension'], 'new' => ['name' => 'Hypertension, Essential']]);
});

it('lets the edit change only the upper / lower case of the same name', function () {
    $this->actingAs($this->admin)
        ->put("/settings/diagnoses/{$this->hypertension->id}", ['name' => 'HYPERTENSION'])
        ->assertSessionHasNoErrors();

    expect($this->hypertension->fresh()->name)->toBe('HYPERTENSION');
});

it('runs the same checks on edit as on add', function () {
    Diagnosis::factory()->create(['name' => 'Asthma'])->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)
        ->put("/settings/diagnoses/{$this->hypertension->id}", ['name' => ' pneumonia '])
        ->assertSessionHasErrors(['name' => '“Pneumonia” is already in the list.']);

    $this->put("/settings/diagnoses/{$this->hypertension->id}", ['name' => 'ASTHMA'])
        ->assertSessionHasErrors(['name' => '“Asthma” is in Trash. Restore it from Trash instead.']);

    $this->put("/settings/diagnoses/{$this->hypertension->id}", ['name' => ''])
        ->assertSessionHasErrors(['name' => "The name can't be empty."]);

    $this->put("/settings/diagnoses/{$this->hypertension->id}", ['name' => 'X'])
        ->assertSessionHasErrors(['name' => 'Use 2 to 120 characters.']);

    expect($this->hypertension->fresh()->name)->toBe('Hypertension');
});

it('does not edit an item in Trash', function () {
    $this->pneumonia->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)
        ->put("/settings/diagnoses/{$this->pneumonia->id}", ['name' => 'Pneumonia, CAP'])
        ->assertNotFound();
});

it('moves a diagnosis to Trash: gone from Encode, still on old visits', function () {
    $visit = listsVisit($this);

    $this->actingAs($this->admin)
        ->delete("/settings/diagnoses/{$this->hypertension->id}", listsReason())
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/lists')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Moved “Hypertension” to Trash');

    $trashed = Diagnosis::withTrashed()->find($this->hypertension->id);
    $onRecords = collect($this->get('/records/visits')->inertiaProps('visits.data'))->firstWhere('id', $visit->id)['diagnosis'];

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->delete_reason)->toBe('No longer used')
        ->and($trashed->deleted_by)->toBe($this->admin->id)
        ->and(listsEncodeDiagnoses($this))->not->toContain('Hypertension')
        ->and($onRecords)->toBe('Hypertension')
        ->and(AuditLog::where('action', 'diagnosis.deleted')->exists())->toBeTrue();

    $this->get('/settings/lists')->assertInertia(fn (Assert $page) => $page->has('diagnoses', 2));
});

it('needs a reason from the List item reasons to delete', function () {
    $this->actingAs($this->admin)
        ->delete("/settings/diagnoses/{$this->hypertension->id}", ['reason' => 'Duplicate entry'])
        ->assertSessionHasErrors(['reason' => 'Choose a reason from the list.']);

    $this->delete("/settings/diagnoses/{$this->hypertension->id}", ['reason' => 'Other (specify)', 'reason_other' => 'short'])
        ->assertSessionHasErrors(['reason_other' => 'Describe the reason in 10 to 200 characters.']);

    $this->delete("/settings/diagnoses/{$this->hypertension->id}", ['reason' => 'Other (specify)', 'reason_other' => 'Merged into another diagnosis'])
        ->assertSessionHasNoErrors();

    expect(Diagnosis::withTrashed()->find($this->hypertension->id)->delete_reason)->toBe('Other: Merged into another diagnosis');
});

// --- Ranks -------------------------------------------------------------------

it('adds a rank at the bottom of its branch, and an "Others" type before Other (specify)', function () {
    $this->actingAs($this->admin)
        ->post('/settings/ranks', ['branch_id' => $this->army->id, 'name' => ' pvt '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Added “pvt” to Army');

    $this->post('/settings/ranks', ['branch_id' => $this->others->id, 'name' => 'Retiree'])
        ->assertSessionHasNoErrors();

    expect(listsEncodeRanks($this, $this->army))->toBe(['COL', 'MAJ', 'CPL', 'pvt'])
        ->and(listsEncodeRanks($this, $this->others))->toBe(['Dependent', 'Retiree', 'Other (specify)'])
        ->and(AuditLog::where('action', 'rank.created')->whereIn('subject_id', Rank::whereIn('name', ['pvt', 'Retiree'])->pluck('id'))->count())->toBe(2);
});

it('keeps rank names unique only within their branch', function () {
    $this->actingAs($this->admin)
        ->post('/settings/ranks', ['branch_id' => $this->army->id, 'name' => 'cpl'])
        ->assertSessionHasErrors(['name' => '“CPL” is already in the Army list.']);

    $this->post('/settings/ranks', ['branch_id' => $this->navy->id, 'name' => 'CPL'])
        ->assertSessionHasNoErrors();

    expect(Rank::where('name', 'CPL')->count())->toBe(2);
});

it('points to Trash when the same rank name is in Trash in that branch', function () {
    $this->maj->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)
        ->post('/settings/ranks', ['branch_id' => $this->army->id, 'name' => 'Maj'])
        ->assertSessionHasErrors(['name' => '“MAJ” is in Trash. Restore it from Trash instead.']);
});

it('checks the rank name length (2 to 60) and the branch', function () {
    $this->actingAs($this->admin)
        ->post('/settings/ranks', ['branch_id' => $this->army->id, 'name' => str_repeat('R', 61)])
        ->assertSessionHasErrors(['name' => 'Use 2 to 60 characters.']);

    $this->post('/settings/ranks', ['branch_id' => $this->army->id, 'name' => ''])
        ->assertSessionHasErrors(['name' => 'Type the rank or patient type to add.']);

    $this->post('/settings/ranks', ['branch_id' => 9999, 'name' => 'SGT'])
        ->assertSessionHasErrors(['branch_id' => 'Choose a branch.']);

    $this->post('/settings/ranks', ['branch_id' => $this->army->id, 'name' => str_repeat('R', 60)])
        ->assertSessionHasNoErrors();
});

it('renames a rank on past visits but keeps it in its branch', function () {
    $visit = listsVisit($this);

    $this->actingAs($this->admin)
        ->put("/settings/ranks/{$this->cpl->id}", ['name' => 'CPL.', 'branch_id' => $this->navy->id])
        ->assertSessionHasNoErrors();

    $rank = $this->cpl->fresh();
    $onRecords = collect($this->get('/records/visits')->inertiaProps('visits.data'))->firstWhere('id', $visit->id)['rank'];

    expect($rank->name)->toBe('CPL.')
        ->and($rank->branch_id)->toBe($this->army->id)
        ->and($onRecords)->toBe('CPL.');

    $this->put("/settings/ranks/{$this->cpl->id}", ['name' => 'maj'])
        ->assertSessionHasErrors(['name' => '“MAJ” is already in the Army list.']);
});

it('moves a rank to Trash: gone from Encode, still on old visits', function () {
    $visit = listsVisit($this);

    $this->actingAs($this->admin)
        ->delete("/settings/ranks/{$this->cpl->id}", listsReason())
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Moved “CPL” (Army) to Trash');

    $onRecords = collect($this->get('/records/visits')->inertiaProps('visits.data'))->firstWhere('id', $visit->id)['rank'];

    expect(listsEncodeRanks($this, $this->army))->toBe(['COL', 'MAJ'])
        ->and($onRecords)->toBe('CPL')
        ->and(AuditLog::where('action', 'rank.deleted')->exists())->toBeTrue();
});

it('changes the order Encode shows with Move up and Move down', function () {
    $this->actingAs($this->admin)
        ->put("/settings/ranks/{$this->cpl->id}/move", ['direction' => 'up'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/lists');

    expect(listsEncodeRanks($this, $this->army))->toBe(['COL', 'CPL', 'MAJ']);

    $this->put("/settings/ranks/{$this->col->id}/move", ['direction' => 'down']);

    expect(listsEncodeRanks($this, $this->army))->toBe(['CPL', 'COL', 'MAJ'])
        ->and(AuditLog::where('action', 'rank.updated')->count())->toBe(4);
});

it('leaves the first rank in place on Move up and the last on Move down', function () {
    $this->actingAs($this->admin)->put("/settings/ranks/{$this->col->id}/move", ['direction' => 'up']);
    $this->put("/settings/ranks/{$this->cpl->id}/move", ['direction' => 'down']);
    $this->put("/settings/ranks/{$this->dependent->id}/move", ['direction' => 'down']);

    expect(listsEncodeRanks($this, $this->army))->toBe(['COL', 'MAJ', 'CPL'])
        ->and(listsEncodeRanks($this, $this->others))->toBe(['Dependent', 'Other (specify)'])
        ->and(AuditLog::where('action', 'rank.updated')->exists())->toBeFalse();
});

it('skips ranks in Trash when moving', function () {
    $this->maj->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)->put("/settings/ranks/{$this->cpl->id}/move", ['direction' => 'up']);

    expect(listsEncodeRanks($this, $this->army))->toBe(['CPL', 'COL']);
});

it('only accepts up or down', function () {
    $this->actingAs($this->admin)
        ->put("/settings/ranks/{$this->cpl->id}/move", ['direction' => 'top'])
        ->assertSessionHasErrors(['direction' => 'Choose up or down.']);
});

// --- Other (specify) -----------------------------------------------------------

it('never edits, deletes or moves "Other (specify)"', function () {
    $this->actingAs($this->admin);

    $this->put("/settings/diagnoses/{$this->otherDiagnosis->id}", ['name' => 'Others'])->assertForbidden();
    $this->put("/settings/ranks/{$this->otherType->id}", ['name' => 'Others'])->assertForbidden();
    $this->put("/settings/ranks/{$this->otherType->id}/move", ['direction' => 'up'])->assertForbidden();

    $this->delete("/settings/diagnoses/{$this->otherDiagnosis->id}", listsReason())
        ->assertSessionHasErrors(['item' => '"Other (specify)" is a fixed entry and can\'t be removed.']);
    $this->delete("/settings/ranks/{$this->otherType->id}", listsReason())
        ->assertSessionHasErrors(['item']);

    expect($this->otherDiagnosis->fresh()->name)->toBe('Other (specify)')
        ->and($this->otherDiagnosis->fresh()->trashed())->toBeFalse()
        ->and($this->otherType->fresh()->trashed())->toBeFalse()
        ->and(listsEncodeRanks($this, $this->others))->toBe(['Dependent', 'Other (specify)']);
});

// --- Age brackets --------------------------------------------------------------

it('adds an age bracket, with blank To saved as "and above"', function () {
    $this->senior->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)
        ->post('/settings/age-brackets', ['min_age' => '26', 'max_age' => '59'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Added age bracket 26–59');

    $this->post('/settings/age-brackets', ['min_age' => '65', 'max_age' => ''])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Added age bracket 65 & above');

    $open = AgeBracket::where('min_age', 65)->first();

    expect($open->max_age)->toBeNull()
        ->and($open->label)->toBe('65 & above')
        ->and(AuditLog::where('action', 'age_bracket.created')->whereIn('subject_id', AgeBracket::whereIn('min_age', [26, 65])->pluck('id'))->count())->toBe(2);
});

it('checks the ages of a bracket', function (array $form, string $field, string $error) {
    $this->actingAs($this->admin)
        ->post('/settings/age-brackets', $form)
        ->assertSessionHasErrors([$field => $error]);

    expect(AgeBracket::count())->toBe(3);
})->with([
    'From missing' => [['min_age' => '', 'max_age' => '30'], 'min_age', 'Enter a starting age from 0 to 120.'],
    'From below 0' => [['min_age' => '-1', 'max_age' => '30'], 'min_age', 'Enter a starting age from 0 to 120.'],
    'From above 120' => [['min_age' => '121'], 'min_age', 'Enter a starting age from 0 to 120.'],
    'To above 120' => [['min_age' => '26', 'max_age' => '121'], 'max_age', 'Enter an ending age from 0 to 120, or leave it blank for "and above".'],
    'not a number' => [['min_age' => '26.5', 'max_age' => '30'], 'min_age', 'Enter a starting age from 0 to 120.'],
    'From above To' => [['min_age' => '40', 'max_age' => '30'], 'max_age', 'The ending age must be the same as or higher than the starting age.'],
    'overlaps 18–25' => [['min_age' => '20', 'max_age' => '30'], 'min_age', 'Overlaps 18–25. Adjust the ages.'],
    'touches 18–25' => [['min_age' => '25', 'max_age' => '30'], 'min_age', 'Overlaps 18–25. Adjust the ages.'],
    'inside 60 & above' => [['min_age' => '70', 'max_age' => '80'], 'min_age', 'Overlaps 60 & above. Adjust the ages.'],
    'open-ended over 60 & above' => [['min_age' => '50', 'max_age' => ''], 'min_age', 'Overlaps 60 & above. Adjust the ages.'],
]);

it('ignores brackets in Trash when checking overlap', function () {
    $this->adult->moveToTrash($this->admin, 'No longer used');

    $this->actingAs($this->admin)
        ->post('/settings/age-brackets', ['min_age' => '18', 'max_age' => '30'])
        ->assertSessionHasNoErrors();
});

it('edits a bracket without counting it as its own overlap', function () {
    $this->actingAs($this->admin)
        ->put("/settings/age-brackets/{$this->adult->id}", ['min_age' => '18', 'max_age' => '29'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Saved 18–29');

    $this->put("/settings/age-brackets/{$this->adult->id}", ['min_age' => '15', 'max_age' => '29'])
        ->assertSessionHasErrors(['min_age' => 'Overlaps 0–17. Adjust the ages.']);

    $this->put("/settings/age-brackets/{$this->adult->id}", ['min_age' => '30', 'max_age' => '18'])
        ->assertSessionHasErrors(['max_age' => 'The ending age must be the same as or higher than the starting age.']);

    expect($this->adult->fresh()->label)->toBe('18–29')
        ->and(AuditLog::where('action', 'age_bracket.updated')->count())->toBe(1);
});

it('moves an age bracket to Trash', function () {
    $this->actingAs($this->admin)
        ->delete("/settings/age-brackets/{$this->adult->id}", listsReason())
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Moved age bracket 18–25 to Trash');

    expect(AgeBracket::withTrashed()->find($this->adult->id)->trashed())->toBeTrue()
        ->and(AuditLog::where('action', 'age_bracket.deleted')->exists())->toBeTrue();

    $this->get('/settings/lists')->assertInertia(fn (Assert $page) => $page->has('ageBrackets', 2));
});
