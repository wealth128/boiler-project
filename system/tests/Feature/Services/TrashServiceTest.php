<?php

use App\Enums\ListItemDeleteReason;
use App\Enums\MonthReportDeleteReason;
use App\Enums\PatientDeleteReason;
use App\Enums\UserDeleteReason;
use App\Enums\VisitDeleteReason;
use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\TrashService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * TrashService: move to Trash (reason from the record type's list),
 * restore, delete permanently, empty Trash and "Delete month report".
 * Every action goes through the TrashPolicy rules.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 10:00', 'Asia/Manila'));
    $this->admin = userWithRole('admin');
    $this->actingAs($this->admin);
    $this->trash = app(TrashService::class);
});

function closeMonthFor(string $period): void
{
    MonthClosure::create(['period' => $period, 'is_closed' => true, 'closed_by' => test()->admin->id, 'closed_at' => now()]);
}

/**
 * The first reason on the record type's list, e.g. "Duplicate entry".
 */
function aReasonFor(Model|string $item): string
{
    return TrashService::reasonsFor($item)::cases()[0]->value;
}

// --- Reasons -----------------------------------------------------------------

it('uses one reason list per record type', function (Closure $make, string $reasons) {
    expect(TrashService::reasonsFor($make()))->toBe($reasons);
})->with([
    'visit' => [fn () => Visit::factory()->create(), VisitDeleteReason::class],
    'patient' => [fn () => Patient::factory()->create(), PatientDeleteReason::class],
    'user' => [fn () => userWithRole('encoder'), UserDeleteReason::class],
    'diagnosis' => [fn () => Diagnosis::factory()->create(), ListItemDeleteReason::class],
    'rank' => [fn () => Rank::factory()->create(), ListItemDeleteReason::class],
    'age bracket' => [fn () => AgeBracket::create(['min_age' => 90, 'max_age' => null, 'sort_order' => 9]), ListItemDeleteReason::class],
    'month report' => [fn () => 'month', MonthReportDeleteReason::class],
]);

it('ends every reason list with "Other (specify)"', function (string $reasons) {
    $options = $reasons::options();

    expect(end($options))->toBe(['value' => 'Other (specify)', 'other' => true])
        ->and(collect($options)->where('other', true))->toHaveCount(1);
})->with([
    VisitDeleteReason::class, PatientDeleteReason::class, UserDeleteReason::class,
    ListItemDeleteReason::class, MonthReportDeleteReason::class,
]);

it('has the agreed reasons for each record type', function () {
    expect(array_column(VisitDeleteReason::cases(), 'value'))->toBe(['Duplicate entry', 'Wrong patient selected', 'Encoded by mistake', 'Test / training entry', 'Other (specify)'])
        ->and(array_column(PatientDeleteReason::cases(), 'value'))->toBe(['Duplicate patient record', 'Encoded by mistake', 'Test / training entry', 'Other (specify)'])
        ->and(array_column(UserDeleteReason::cases(), 'value'))->toBe(['Resigned / separated', 'Transferred out', 'Created by mistake', 'Other (specify)'])
        ->and(array_column(ListItemDeleteReason::cases(), 'value'))->toBe(['Duplicate item', 'Misspelled, replaced with a corrected one', 'No longer used', 'Other (specify)'])
        ->and(array_column(MonthReportDeleteReason::cases(), 'value'))->toBe(['Encoded in the wrong month', 'Test / training data', 'Other (specify)']);
});

// --- Move to Trash -------------------------------------------------------

it('moves a record to Trash with who and why', function () {
    $patient = Patient::factory()->create();

    $this->trash->moveToTrash($patient, $this->admin, 'Duplicate patient record');

    $patient = Patient::withTrashed()->find($patient->id);
    expect($patient->trashed())->toBeTrue()
        ->and($patient->deleted_by)->toBe($this->admin->id)
        ->and($patient->delete_reason)->toBe('Duplicate patient record')
        ->and(AuditLog::where('action', 'patient.deleted')->sole()->changes)->toBe(['reason' => 'Duplicate patient record']);
});

it('accepts only a reason from the record type\'s list', function (mixed $reason) {
    $patient = Patient::factory()->create();

    expect(fn () => $this->trash->moveToTrash($patient, $this->admin, $reason))
        ->toThrow(ValidationException::class, 'Choose a reason from the list.');

    expect($patient->fresh()->trashed())->toBeFalse();
})->with([
    'no reason' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'free text' => ['Duplicate record'],
    'another type\'s reason' => ['Duplicate entry'],
    'with extra spaces' => ['  Duplicate patient record  '],
    'a list' => [['Duplicate patient record']],
]);

it('needs 10 to 200 characters for "Other (specify)"', function (mixed $text) {
    $rank = Rank::factory()->create();

    expect(fn () => $this->trash->moveToTrash($rank, $this->admin, 'Other (specify)', $text))
        ->toThrow(ValidationException::class, 'Describe the reason in 10 to 200 characters.');

    expect($rank->fresh()->trashed())->toBeFalse();
})->with([
    'nothing' => [null],
    'empty' => [''],
    '9 characters' => ['Too short'],
    '9 characters plus spaces' => ['  Too   short  '],
    '201 characters' => [str_repeat('x', 201)],
    'a list' => [['A long enough reason']],
]);

it('saves "Other (specify)" as "Other: <text>", with spaces tidied', function () {
    $rank = Rank::factory()->create();

    $this->trash->moveToTrash($rank, $this->admin, 'Other (specify)', "  Replaced by\tthe new  rank list  ");

    expect(Rank::onlyTrashed()->findOrFail($rank->id)->delete_reason)->toBe('Other: Replaced by the new rank list')
        ->and(AuditLog::where('action', 'rank.deleted')->sole()->changes)->toBe(['reason' => 'Other: Replaced by the new rank list']);
});

it('ignores typed text when the reason is not "Other (specify)"', function () {
    $diagnosis = Diagnosis::factory()->create();

    $this->trash->moveToTrash($diagnosis, $this->admin, 'No longer used', 'This text is not saved');

    expect(Diagnosis::onlyTrashed()->findOrFail($diagnosis->id)->delete_reason)->toBe('No longer used');
});

it('lets only Admin move patients, visits and dropdown items to Trash', function (string $role) {
    $actor = userWithRole($role);

    foreach ([Patient::factory()->create(), Visit::factory()->create(), Diagnosis::factory()->create()] as $item) {
        expect(fn () => $this->trash->moveToTrash($item, $actor, aReasonFor($item)))->toThrow(AuthorizationException::class);
    }
})->with(['encoder', 'system_admin', 'viewer']);

it('refuses to move a visit of a closed month to Trash', function () {
    closeMonthFor('2026-09');
    $visit = Visit::factory()->create(['visited_at' => '2026-09-20 09:00']);

    expect(fn () => $this->trash->moveToTrash($visit, $this->admin, 'Duplicate entry'))
        ->toThrow(AuthorizationException::class, 'September 2026 is closed. The Admin must reopen it first.');
});

it('refuses to remove the fixed "Other (specify)" entries', function () {
    $other = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true]);

    expect(fn () => $this->trash->moveToTrash($other, $this->admin, 'No longer used'))
        ->toThrow(AuthorizationException::class, '"Other (specify)" is a fixed entry');
});

it('follows the user rules: not yourself, not the Admin, System Admin may delete others', function () {
    $sysadmin = userWithRole('system_admin');
    $encoder = userWithRole('encoder');

    expect(fn () => $this->trash->moveToTrash($this->admin, $this->admin, 'Created by mistake'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->trash->moveToTrash($this->admin, $sysadmin, 'Created by mistake'))->toThrow(AuthorizationException::class);

    $this->trash->moveToTrash($encoder, $sysadmin, 'Resigned / separated');

    expect(User::withTrashed()->find($encoder->id)->trashed())->toBeTrue();
});

it('refuses to move something to Trash twice', function () {
    $patient = Patient::factory()->create();
    $this->trash->moveToTrash($patient, $this->admin, 'Encoded by mistake');

    expect(fn () => $this->trash->moveToTrash($patient, $this->admin, 'Encoded by mistake'))
        ->toThrow(AuthorizationException::class, 'already in Trash');
});

// --- Restore and permanent delete ---------------------------------------

it('restores a record and clears the delete details', function () {
    $rank = Rank::factory()->create();
    $this->trash->moveToTrash($rank, $this->admin, 'Duplicate item');

    $this->trash->restore($this->trash->find('ranks', $rank->id), $this->admin);

    $rank->refresh();
    expect($rank->trashed())->toBeFalse()
        ->and($rank->deleted_by)->toBeNull()
        ->and($rank->delete_reason)->toBeNull()
        ->and(AuditLog::where('action', 'rank.restored')->exists())->toBeTrue();
});

it('deletes permanently when allowed, and refuses when history depends on it', function () {
    $visit = Visit::factory()->create();
    $patient = $visit->patient;
    // Visits go to Trash before their patient.
    $this->trash->moveToTrash($visit, $this->admin, 'Test / training entry');
    $this->trash->moveToTrash($patient, $this->admin, 'Test / training entry');

    // The visit is in Trash, but it still points at the patient.
    expect(fn () => $this->trash->forceDelete($patient, $this->admin))
        ->toThrow(AuthorizationException::class, 'still has visits');

    $this->trash->forceDelete($visit, $this->admin);
    $this->trash->forceDelete($patient, $this->admin);

    expect(Visit::withTrashed()->find($visit->id))->toBeNull()
        ->and(Patient::withTrashed()->find($patient->id))->toBeNull()
        ->and(AuditLog::where('action', 'visit.purged')->sole()->changes)->toBe(['label' => $visit->visit_no]);
});

it('finds only items that are in Trash', function () {
    $patient = Patient::factory()->create();

    expect(fn () => $this->trash->find('patients', $patient->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->trash->find('settings', 1))->toThrow(NotFoundHttpException::class);
});

// --- Empty Trash -----------------------------------------------------------

it('empties Trash but keeps what the rules protect', function () {
    // Deleted: a visit and its patient, an age bracket.
    $visit = Visit::factory()->create();
    $this->trash->moveToTrash($visit, $this->admin, 'Test / training entry');
    $this->trash->moveToTrash($visit->patient, $this->admin, 'Test / training entry');
    $bracket = AgeBracket::create(['min_age' => 90, 'max_age' => null, 'sort_order' => 9]);
    $this->trash->moveToTrash($bracket, $this->admin, 'No longer used');

    // Kept: a diagnosis still used by a visit, a user with audit entries.
    $usedDiagnosis = Visit::factory()->create()->diagnosis;
    $this->trash->moveToTrash($usedDiagnosis, $this->admin, 'Duplicate item');
    $user = userWithRole('encoder');
    app(AuditLogger::class)->log('user.login', $user, by: $user);
    $this->trash->moveToTrash($user, $this->admin, 'Transferred out');

    expect($this->trash->emptyTrash($this->admin))->toBe(['deleted' => 3, 'kept' => 2])
        ->and(Diagnosis::onlyTrashed()->whereKey($usedDiagnosis->id)->exists())->toBeTrue()
        ->and(User::onlyTrashed()->whereKey($user->id)->exists())->toBeTrue()
        ->and(AgeBracket::withTrashed()->find($bracket->id))->toBeNull();
});

it('lets only Admin empty Trash', function () {
    expect(fn () => $this->trash->emptyTrash(userWithRole('system_admin')))->toThrow(AuthorizationException::class);
});

// --- Delete month report ---------------------------------------------------

it('moves all visits of an open month to Trash as one entry', function () {
    $september = Visit::factory()->count(3)->create(['visited_at' => '2026-09-15 08:00']);
    $alreadyTrashed = Visit::factory()->create(['visited_at' => '2026-09-02 08:00']);
    $this->trash->moveToTrash($alreadyTrashed, $this->admin, 'Wrong patient selected');
    $october = Visit::factory()->create(['visited_at' => '2026-10-01 00:05']);
    $august = Visit::factory()->create(['visited_at' => '2026-08-31 23:55']);

    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Encoded in the wrong month');

    expect($entry->visit_count)->toBe(3)
        ->and($entry->delete_reason)->toBe('Encoded in the wrong month')
        ->and(Visit::onlyTrashed()->where('month_deletion_id', $entry->id)->pluck('id')->sort()->values()->all())
        ->toBe($september->pluck('id')->sort()->values()->all())
        ->and(Visit::withTrashed()->find($alreadyTrashed->id)->month_deletion_id)->toBeNull()
        ->and($october->fresh()->trashed())->toBeFalse()
        ->and($august->fresh()->trashed())->toBeFalse();

    // One entry for the month, not one per visit.
    expect(AuditLog::where('action', 'month.report_deleted')->sole()->changes)->toEqual([
        'period' => '2026-09', 'visit_count' => 3, 'reason' => 'Encoded in the wrong month',
    ])->and(AuditLog::where('action', 'visit.deleted')->count())->toBe(1);
});

it('saves "Other (specify)" on a month report as "Other: <text>"', function () {
    Visit::factory()->create(['visited_at' => '2026-09-15 08:00']);

    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Other (specify)', 'Imported twice from the old sheet');

    expect($entry->delete_reason)->toBe('Other: Imported twice from the old sheet');
});

it('refuses to delete the report of a closed month', function () {
    Visit::factory()->create(['visited_at' => '2026-09-15 08:00']);
    closeMonthFor('2026-09');

    expect(fn () => $this->trash->deleteMonthReport('2026-09', $this->admin, 'Test / training data'))
        ->toThrow(AuthorizationException::class, 'September 2026 is closed.');
});

it('refuses an empty month, a reason not on the month list, short Other text and non-Admins', function () {
    Visit::factory()->create(['visited_at' => '2026-09-15 08:00']);

    expect(fn () => $this->trash->deleteMonthReport('2026-07', $this->admin, 'Test / training data'))->toThrow(ValidationException::class, 'no visits')
        ->and(fn () => $this->trash->deleteMonthReport('2026-09', $this->admin, ''))->toThrow(ValidationException::class, 'Choose a reason from the list.')
        ->and(fn () => $this->trash->deleteMonthReport('2026-09', $this->admin, 'Duplicate entry'))->toThrow(ValidationException::class, 'Choose a reason from the list.')
        ->and(fn () => $this->trash->deleteMonthReport('2026-09', $this->admin, 'Other (specify)', 'Short'))->toThrow(ValidationException::class, 'Describe the reason in 10 to 200 characters.')
        ->and(fn () => $this->trash->deleteMonthReport('2026-09', userWithRole('encoder'), 'Test / training data'))->toThrow(AuthorizationException::class);

    expect(MonthDeletion::count())->toBe(0);
});

it('restores a whole month report', function () {
    $visits = Visit::factory()->count(2)->create(['visited_at' => '2026-09-15 08:00']);
    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Test / training data');

    // One visit alone can't come back.
    expect(fn () => $this->trash->restore(Visit::withTrashed()->find($visits[0]->id), $this->admin))
        ->toThrow(AuthorizationException::class, 'part of a deleted month report');

    $this->trash->restore($this->trash->find('months', $entry->id), $this->admin);

    expect(Visit::whereIn('id', $visits->pluck('id'))->count())->toBe(2)
        ->and(Visit::whereIn('id', $visits->pluck('id'))->whereNotNull('month_deletion_id')->exists())->toBeFalse()
        ->and(MonthDeletion::count())->toBe(0)
        ->and(AuditLog::where('action', 'month.report_restored')->exists())->toBeTrue();
});

it('deletes a whole month report permanently, unless the month is closed', function () {
    $visits = Visit::factory()->count(2)->create(['visited_at' => '2026-09-15 08:00']);
    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Test / training data');

    closeMonthFor('2026-09');
    expect(fn () => $this->trash->forceDelete($entry, $this->admin))->toThrow(AuthorizationException::class);

    MonthClosure::where('period', '2026-09')->update(['is_closed' => false]);
    $this->trash->forceDelete($entry, $this->admin);

    expect(Visit::withTrashed()->whereIn('id', $visits->pluck('id'))->exists())->toBeFalse()
        ->and(MonthDeletion::count())->toBe(0)
        ->and(AuditLog::where('action', 'month.report_purged')->sole()->changes)->toEqual(['period' => '2026-09', 'visit_count' => 2]);
});

// --- Patients and their visits (prototype/prototype.html) ---------------

it("moves a patient to Trash only after all the patient's visits are in Trash", function () {
    $patient = Patient::factory()->create();
    $visits = Visit::factory()->count(2)->create(['patient_id' => $patient->id, 'visited_at' => '2026-10-05 08:00']);

    expect(fn () => $this->trash->moveToTrash($patient, $this->admin, 'Duplicate patient record'))
        ->toThrow(AuthorizationException::class, "Move this patient's visits to Trash first.");

    $this->trash->moveToTrash($visits[0], $this->admin, 'Duplicate entry');

    expect(fn () => $this->trash->moveToTrash($patient->fresh(), $this->admin, 'Duplicate patient record'))
        ->toThrow(AuthorizationException::class, "Move this patient's visits to Trash first.");

    $this->trash->moveToTrash($visits[1], $this->admin, 'Duplicate entry');
    $this->trash->moveToTrash($patient->fresh(), $this->admin, 'Duplicate patient record');

    expect(Patient::onlyTrashed()->whereKey($patient->id)->exists())->toBeTrue();
});

it('moves a patient with no visits to Trash', function () {
    $patient = Patient::factory()->create();

    $this->trash->moveToTrash($patient, $this->admin, 'Encoded by mistake');

    expect($patient->fresh()->trashed())->toBeTrue();
});

it('restores a visit only after its patient is restored', function () {
    $patient = Patient::factory()->create();
    $visit = Visit::factory()->create(['patient_id' => $patient->id, 'visited_at' => '2026-10-05 08:00']);
    $this->trash->moveToTrash($visit, $this->admin, 'Duplicate entry');
    $this->trash->moveToTrash($patient->fresh(), $this->admin, 'Duplicate patient record');

    expect(fn () => $this->trash->restore($this->trash->find('visits', $visit->id), $this->admin))
        ->toThrow(AuthorizationException::class, 'Restore the patient first.');

    $this->trash->restore($this->trash->find('patients', $patient->id), $this->admin);
    $this->trash->restore($this->trash->find('visits', $visit->id), $this->admin);

    expect($visit->fresh()->trashed())->toBeFalse();
});

it('restores a month report only after the patients in Trash are restored', function () {
    $patient = Patient::factory()->create();
    Visit::factory()->create(['patient_id' => $patient->id, 'visited_at' => '2026-09-15 08:00']);
    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Test / training data');
    $this->trash->moveToTrash($patient->fresh(), $this->admin, 'Duplicate patient record');

    expect(fn () => $this->trash->restore($this->trash->find('months', $entry->id), $this->admin))
        ->toThrow(AuthorizationException::class, "Restore patient {$patient->fresh()->patient_no} first.");

    $this->trash->restore($this->trash->find('patients', $patient->id), $this->admin);
    $this->trash->restore($this->trash->find('months', $entry->id), $this->admin);

    expect(MonthDeletion::count())->toBe(0);
});

// --- Restoring dropdown list items (planning/mvp-scope.md, "Dropdown lists") --

it('does not restore an age bracket that overlaps an active one', function () {
    $old = AgeBracket::create(['min_age' => 18, 'max_age' => 25]);
    $this->trash->moveToTrash($old, $this->admin, 'No longer used');
    $active = AgeBracket::create(['min_age' => 20, 'max_age' => 30]);

    expect(fn () => $this->trash->restore($this->trash->find('age-brackets', $old->id), $this->admin))
        ->toThrow(ValidationException::class, "Can't restore 18–25: it overlaps 20–30. Edit or delete that one first.");

    expect($old->fresh()->trashed())->toBeTrue();

    // Once the other bracket no longer overlaps, it comes back.
    $active->update(['min_age' => 26]);
    $this->trash->restore($this->trash->find('age-brackets', $old->id), $this->admin);

    expect($old->fresh()->trashed())->toBeFalse();
});

it('does not restore an "and above" bracket over an active one', function () {
    $old = AgeBracket::create(['min_age' => 60, 'max_age' => null]);
    $this->trash->moveToTrash($old, $this->admin, 'No longer used');
    AgeBracket::create(['min_age' => 65, 'max_age' => 70]);

    expect(fn () => $this->trash->restore($this->trash->find('age-brackets', $old->id), $this->admin))
        ->toThrow(ValidationException::class, "Can't restore 60 & above: it overlaps 65–70.");
});

it('does not restore a diagnosis whose name an active one now uses', function () {
    // Saved before the list rules (double space), so the database allowed both.
    $old = Diagnosis::factory()->create(['name' => 'Acute  Gastroenteritis']);
    $this->trash->moveToTrash($old, $this->admin, 'Duplicate item');
    Diagnosis::factory()->create(['name' => 'acute gastroenteritis']);

    expect(fn () => $this->trash->restore($this->trash->find('diagnoses', $old->id), $this->admin))
        ->toThrow(ValidationException::class, "Can't restore “Acute  Gastroenteritis”: “acute gastroenteritis” is already in the list.");

    expect($old->fresh()->trashed())->toBeTrue();
});

it('checks a restored rank name only within its branch', function () {
    $old = Rank::factory()->create(['name' => 'S SGT']);
    $this->trash->moveToTrash($old, $this->admin, 'Duplicate item');

    // Same name in another branch: no conflict.
    Rank::factory()->create(['name' => 'S  SGT']);
    $this->trash->restore($this->trash->find('ranks', $old->id), $this->admin);
    expect($old->fresh()->trashed())->toBeFalse();

    // Same name in the same branch: refused.
    $this->trash->moveToTrash($old->fresh(), $this->admin, 'Duplicate item');
    Rank::factory()->for($old->branch)->create(['name' => 's  sgt']);

    expect(fn () => $this->trash->restore($this->trash->find('ranks', $old->id), $this->admin))
        ->toThrow(ValidationException::class, "is already in the {$old->branch->name} list.");
});

it('restores a list item with no conflict', function () {
    $diagnosis = Diagnosis::factory()->create(['name' => 'Dengue Fever']);
    $bracket = AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    $this->trash->moveToTrash($diagnosis, $this->admin, 'No longer used');
    $this->trash->moveToTrash($bracket, $this->admin, 'No longer used');

    $this->trash->restore($this->trash->find('diagnoses', $diagnosis->id), $this->admin);
    $this->trash->restore($this->trash->find('age-brackets', $bracket->id), $this->admin);

    expect($diagnosis->fresh()->trashed())->toBeFalse()
        ->and($bracket->fresh()->trashed())->toBeFalse();
});
