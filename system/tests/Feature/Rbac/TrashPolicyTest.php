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
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/*
 * TrashPolicy (planning/database-schema.md, section 5): Admin only, and
 * permanent delete is blocked when it would break history.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin');
});

function purge(User $actor, $item): Response
{
    return Gate::forUser($actor)->inspect('delete-permanently', $item);
}

function trashed($model)
{
    $model->moveToTrash(test()->admin, 'Test');

    return $model;
}

it('gives Trash to Admin only', function (string $role) {
    $actor = userWithRole($role);
    $visit = trashed(Visit::factory()->create());

    expect(purge($actor, $visit)->allowed())->toBeFalse()
        ->and(Gate::forUser($actor)->allows('restore-from-trash', $visit))->toBeFalse();
})->with(['encoder', 'system_admin', 'viewer']);

it('only works on items that are in Trash', function () {
    $visit = Visit::factory()->create();

    expect(purge($this->admin, $visit)->message())->toBe('Only items in Trash can be deleted permanently.')
        ->and(Gate::forUser($this->admin)->inspect('restore-from-trash', $visit)->message())->toBe('This item is not in Trash.');
});

it('blocks restore and permanent delete of a visit in a closed month', function () {
    $visit = trashed(Visit::factory()->create(['visited_at' => '2026-09-10 09:00']));
    MonthClosure::create(['period' => '2026-09', 'is_closed' => true, 'closed_by' => $this->admin->id, 'closed_at' => now()]);

    expect(purge($this->admin, $visit)->message())->toBe('September 2026 is closed. The Admin must reopen it first.')
        ->and(Gate::forUser($this->admin)->allows('restore-from-trash', $visit))->toBeFalse();
});

it('allows permanent delete of a visit in an open month', function () {
    $visit = trashed(Visit::factory()->create(['visited_at' => '2026-09-10 09:00']));

    expect(purge($this->admin, $visit)->allowed())->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('restore-from-trash', $visit))->toBeTrue();
});

it('blocks a month report in Trash while its month is closed', function () {
    $entry = MonthDeletion::create(['period' => '2026-08', 'visit_count' => 0, 'deleted_by' => $this->admin->id, 'delete_reason' => 'Test', 'deleted_at' => now()]);

    expect(purge($this->admin, $entry)->allowed())->toBeTrue();

    MonthClosure::create(['period' => '2026-08', 'is_closed' => true, 'closed_by' => $this->admin->id, 'closed_at' => now()]);

    expect(purge($this->admin, $entry)->allowed())->toBeFalse();
});

it('blocks permanent delete of a patient who still has visits, even visits in Trash', function () {
    $patient = trashed(Patient::factory()->create());
    $visit = Visit::factory()->create(['patient_id' => $patient->id]);

    expect(purge($this->admin, $patient)->message())->toBe("This patient still has visits, so the record can't be deleted permanently.");

    trashed($visit);
    expect(purge($this->admin, $patient)->allowed())->toBeFalse();

    $visit->forceDelete();
    expect(purge($this->admin, $patient)->allowed())->toBeTrue();
});

it('blocks permanent delete of a diagnosis or rank used by a visit', function () {
    $usedDiagnosis = Diagnosis::factory()->create();
    $usedRank = Rank::factory()->create();
    Visit::factory()->create(['diagnosis_id' => $usedDiagnosis->id, 'rank_id' => $usedRank->id]);
    trashed($usedDiagnosis);
    trashed($usedRank);

    $unusedDiagnosis = trashed(Diagnosis::factory()->create());
    $unusedRank = trashed(Rank::factory()->create());

    expect(purge($this->admin, $usedDiagnosis)->allowed())->toBeFalse()
        ->and(purge($this->admin, $usedRank)->allowed())->toBeFalse()
        ->and(purge($this->admin, $unusedDiagnosis)->allowed())->toBeTrue()
        ->and(purge($this->admin, $unusedRank)->allowed())->toBeTrue();
});

it('blocks permanent delete of a user who has visits or audit entries', function () {
    $withVisits = userWithRole('encoder');
    Visit::factory()->create(['encoded_by' => $withVisits->id]);
    $withAudit = userWithRole('encoder');
    app(AuditLogger::class)->log('user.login', $withAudit, by: $withAudit);
    $clean = userWithRole('encoder');

    foreach ([$withVisits, $withAudit, $clean] as $user) {
        trashed($user);
    }

    expect(purge($this->admin, $withVisits)->allowed())->toBeFalse()
        ->and(purge($this->admin, $withAudit)->allowed())->toBeFalse()
        ->and(purge($this->admin, $clean)->allowed())->toBeTrue()
        ->and(AuditLog::count())->toBe(1);
});

it('always allows permanent delete of an age bracket in Trash', function () {
    $bracket = trashed(AgeBracket::create(['min_age' => 90, 'max_age' => null, 'sort_order' => 99]));

    expect(purge($this->admin, $bracket)->allowed())->toBeTrue();
});

it('refuses to restore an Admin account while another Admin is active', function () {
    $oldAdmin = trashed(userWithRole('admin'));

    expect(Gate::forUser($this->admin)->inspect('restore-from-trash', $oldAdmin)->message())
        ->toBe('There is already an active Admin account. Only one is allowed.');
});

it('rejects things that never go to Trash', function () {
    purge($this->admin, AuditLog::create(['user_id' => $this->admin->id, 'action' => 'x.y', 'subject_type' => 'users', 'subject_id' => 1]));
})->throws(InvalidArgumentException::class);
