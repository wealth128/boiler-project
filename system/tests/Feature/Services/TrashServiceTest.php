<?php

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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * TrashService: move to Trash (reason required), restore, delete
 * permanently, empty Trash and "Delete month report". Every action goes
 * through the TrashPolicy rules.
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

// --- Move to Trash -------------------------------------------------------

it('moves a record to Trash with who and why', function () {
    $patient = Patient::factory()->create();

    $this->trash->moveToTrash($patient, $this->admin, '  Duplicate record  ');

    $patient = Patient::withTrashed()->find($patient->id);
    expect($patient->trashed())->toBeTrue()
        ->and($patient->deleted_by)->toBe($this->admin->id)
        ->and($patient->delete_reason)->toBe('Duplicate record')
        ->and(AuditLog::where('action', 'patient.deleted')->sole()->changes)->toBe(['reason' => 'Duplicate record']);
});

it('requires a reason', function (?string $reason) {
    $patient = Patient::factory()->create();

    expect(fn () => $this->trash->moveToTrash($patient, $this->admin, $reason))
        ->toThrow(ValidationException::class, 'Give a reason for moving this to Trash.');

    expect($patient->fresh()->trashed())->toBeFalse();
})->with([null, '', '   ']);

it('refuses a reason over 255 characters', function () {
    expect(fn () => $this->trash->moveToTrash(Patient::factory()->create(), $this->admin, str_repeat('x', 256)))
        ->toThrow(ValidationException::class);
});

it('lets only Admin move patients, visits and dropdown items to Trash', function (string $role) {
    $actor = userWithRole($role);

    foreach ([Patient::factory()->create(), Visit::factory()->create(), Diagnosis::factory()->create()] as $item) {
        expect(fn () => $this->trash->moveToTrash($item, $actor, 'Test'))->toThrow(AuthorizationException::class);
    }
})->with(['encoder', 'system_admin', 'viewer']);

it('refuses to move a visit of a closed month to Trash', function () {
    closeMonthFor('2026-09');
    $visit = Visit::factory()->create(['visited_at' => '2026-09-20 09:00']);

    expect(fn () => $this->trash->moveToTrash($visit, $this->admin, 'Test'))
        ->toThrow(AuthorizationException::class, 'September 2026 is closed. The Admin must reopen it first.');
});

it('refuses to remove the fixed "Other (specify)" entries', function () {
    $other = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true]);

    expect(fn () => $this->trash->moveToTrash($other, $this->admin, 'Test'))
        ->toThrow(AuthorizationException::class, '"Other (specify)" is a fixed entry');
});

it('follows the user rules: not yourself, not the Admin, System Admin may delete others', function () {
    $sysadmin = userWithRole('system_admin');
    $encoder = userWithRole('encoder');

    expect(fn () => $this->trash->moveToTrash($this->admin, $this->admin, 'Test'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->trash->moveToTrash($this->admin, $sysadmin, 'Test'))->toThrow(AuthorizationException::class);

    $this->trash->moveToTrash($encoder, $sysadmin, 'Left the hospital');

    expect(User::withTrashed()->find($encoder->id)->trashed())->toBeTrue();
});

it('refuses to move something to Trash twice', function () {
    $patient = Patient::factory()->create();
    $this->trash->moveToTrash($patient, $this->admin, 'Test');

    expect(fn () => $this->trash->moveToTrash($patient, $this->admin, 'Again'))
        ->toThrow(AuthorizationException::class, 'already in Trash');
});

// --- Restore and permanent delete ---------------------------------------

it('restores a record and clears the delete details', function () {
    $rank = Rank::factory()->create();
    $this->trash->moveToTrash($rank, $this->admin, 'Test');

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
    $this->trash->moveToTrash($patient, $this->admin, 'Test');

    expect(fn () => $this->trash->forceDelete($patient, $this->admin))
        ->toThrow(AuthorizationException::class, 'still has visits');

    $this->trash->moveToTrash($visit, $this->admin, 'Test');
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
    $this->trash->moveToTrash($visit, $this->admin, 'Test');
    $this->trash->moveToTrash($visit->patient, $this->admin, 'Test');
    $bracket = AgeBracket::create(['min_age' => 90, 'max_age' => null, 'sort_order' => 9]);
    $this->trash->moveToTrash($bracket, $this->admin, 'Test');

    // Kept: a diagnosis still used by a visit, a user with audit entries.
    $usedDiagnosis = Visit::factory()->create()->diagnosis;
    $this->trash->moveToTrash($usedDiagnosis, $this->admin, 'Test');
    $user = userWithRole('encoder');
    app(AuditLogger::class)->log('user.login', $user, by: $user);
    $this->trash->moveToTrash($user, $this->admin, 'Test');

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
    $this->trash->moveToTrash($alreadyTrashed, $this->admin, 'Wrong patient');
    $october = Visit::factory()->create(['visited_at' => '2026-10-01 00:05']);
    $august = Visit::factory()->create(['visited_at' => '2026-08-31 23:55']);

    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Encoded in the wrong month');

    expect($entry->visit_count)->toBe(3)
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

it('refuses to delete the report of a closed month', function () {
    Visit::factory()->create(['visited_at' => '2026-09-15 08:00']);
    closeMonthFor('2026-09');

    expect(fn () => $this->trash->deleteMonthReport('2026-09', $this->admin, 'Test'))
        ->toThrow(AuthorizationException::class, 'September 2026 is closed.');
});

it('refuses an empty month, a missing reason and non-Admins', function () {
    Visit::factory()->create(['visited_at' => '2026-09-15 08:00']);

    expect(fn () => $this->trash->deleteMonthReport('2026-07', $this->admin, 'Test'))->toThrow(ValidationException::class, 'no visits')
        ->and(fn () => $this->trash->deleteMonthReport('2026-09', $this->admin, ''))->toThrow(ValidationException::class)
        ->and(fn () => $this->trash->deleteMonthReport('2026-09', userWithRole('encoder'), 'Test'))->toThrow(AuthorizationException::class);

    expect(MonthDeletion::count())->toBe(0);
});

it('restores a whole month report', function () {
    $visits = Visit::factory()->count(2)->create(['visited_at' => '2026-09-15 08:00']);
    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Test');

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
    $entry = $this->trash->deleteMonthReport('2026-09', $this->admin, 'Test');

    closeMonthFor('2026-09');
    expect(fn () => $this->trash->forceDelete($entry, $this->admin))->toThrow(AuthorizationException::class);

    MonthClosure::where('period', '2026-09')->update(['is_closed' => false]);
    $this->trash->forceDelete($entry, $this->admin);

    expect(Visit::withTrashed()->whereIn('id', $visits->pluck('id'))->exists())->toBeFalse()
        ->and(MonthDeletion::count())->toBe(0)
        ->and(AuditLog::where('action', 'month.report_purged')->sole()->changes)->toEqual(['period' => '2026-09', 'visit_count' => 2]);
});
