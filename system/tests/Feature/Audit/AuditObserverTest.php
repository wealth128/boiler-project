<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Observers\AuditObserver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/*
 * AuditObserver: every create, update, move to Trash, restore and permanent
 * delete of visits, patients, users, diagnoses, ranks, age brackets and
 * month closures writes an audit_logs entry. Passwords are never written.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin');
    $this->actingAs($this->admin);
});

/**
 * @return Builder<AuditLog>
 */
function entriesFor(Model $model)
{
    return AuditLog::query()
        ->where('subject_type', $model->getMorphClass())
        ->where('subject_id', $model->getKey())
        ->orderBy('id');
}

dataset('audited models', [
    'visit' => ['visit', fn () => Visit::factory()->create(), ['remarks' => 'Follow-up in 2 weeks']],
    'patient' => ['patient', fn () => Patient::factory()->create(['first_name' => 'Juan']), ['first_name' => 'Jose']],
    'user' => ['user', fn () => User::factory()->create(['name' => 'Ana Reyes']), ['name' => 'Ana R. Santos']],
    'diagnosis' => ['diagnosis', fn () => Diagnosis::factory()->create(), ['name' => 'Acute gastroenteritis']],
    'rank' => ['rank', fn () => Rank::factory()->create(), ['name' => 'SSg']],
    'age bracket' => ['age_bracket', fn () => AgeBracket::create(['min_age' => 70, 'max_age' => 79, 'sort_order' => 9]), ['max_age' => 80]],
]);

it('logs created, updated, deleted, restored and purged', function (string $subject, Closure $make, array $edit) {
    /** @var Model $model */
    $model = $make();

    $created = entriesFor($model)->where('action', "{$subject}.created")->sole();
    expect($created->user_id)->toBe($this->admin->id)
        ->and($created->changes['new'])->toHaveKey(array_key_first($edit));

    $old = $model->getRawOriginal(array_key_first($edit));
    $model->update($edit);

    $updated = entriesFor($model)->where('action', "{$subject}.updated")->sole();
    // MySQL may reorder JSON keys, so compare without key order.
    expect($updated->changes)->toEqual([
        'old' => [array_key_first($edit) => $old],
        'new' => $edit,
    ]);

    $model->moveToTrash($this->admin, 'Encoded by mistake');
    expect(entriesFor($model)->where('action', "{$subject}.deleted")->sole()->changes)
        ->toBe(['reason' => 'Encoded by mistake']);

    $model->restoreFromTrash();
    expect(entriesFor($model)->where('action', "{$subject}.restored")->exists())->toBeTrue();

    $model->forceDelete();
    $purged = entriesFor($model)->where('action', "{$subject}.purged")->sole();
    expect($purged->changes['label'])->not->toBeNull();

    // Restore saves the model, but that is not logged as an extra edit.
    expect(entriesFor($model)->pluck('action')->all())->toBe([
        "{$subject}.created", "{$subject}.updated", "{$subject}.deleted", "{$subject}.restored", "{$subject}.purged",
    ]);
})->with('audited models');

it('logs only the fields that changed', function () {
    $patient = Patient::factory()->create(['last_name' => 'Cruz', 'first_name' => 'Juan']);

    $patient->update(['last_name' => 'Cruz', 'first_name' => 'Jose']);

    expect(entriesFor($patient)->where('action', 'patient.updated')->sole()->changes)->toEqual([
        'old' => ['first_name' => 'Juan'],
        'new' => ['first_name' => 'Jose'],
    ]);
});

it('writes no entry when nothing really changed', function () {
    $patient = Patient::factory()->create();

    $patient->update(['first_name' => $patient->first_name]);
    $patient->touch();

    expect(entriesFor($patient)->where('action', 'patient.updated')->exists())->toBeFalse();
});

it('never writes a password, plain or hashed', function () {
    $user = User::factory()->create(['password' => 'first-secret-123']);
    $user->update(['password' => 'second-secret-456']);

    $created = entriesFor($user)->where('action', 'user.created')->sole();
    $updated = entriesFor($user)->where('action', 'user.updated')->sole();

    expect($created->changes['new']['password'])->toBe('[hidden]')
        ->and($updated->changes)->toEqual(['old' => ['password' => '[hidden]'], 'new' => ['password' => '[hidden]']]);

    $everything = AuditLog::all()->map(fn (AuditLog $entry) => json_encode($entry->changes))->implode(' ');

    expect($everything)->not->toContain('first-secret-123')
        ->not->toContain('second-secret-456')
        ->not->toContain(addcslashes($user->fresh()->password, '/'))
        ->not->toContain('$2y$');
});

it('does not log the remember-me token change on logout', function () {
    $user = userWithRole('encoder');

    $this->actingAs($user)->post(route('logout'));

    expect(entriesFor($user)->where('action', 'user.updated')->exists())->toBeFalse()
        ->and(entriesFor($user)->where('action', 'user.logout')->exists())->toBeTrue();
});

it('keeps the wrong-password counter out of the log', function () {
    auth()->logout();
    $user = User::factory()->create(['username' => 'er.cruz']);

    foreach (range(1, 5) as $try) {
        $this->post(route('login.store'), ['username' => 'er.cruz', 'password' => 'wrong-password']);
    }

    expect($user->fresh()->locked_at)->not->toBeNull()
        ->and(entriesFor($user)->where('action', 'user.updated')->exists())->toBeFalse();
});

it('logs closing and reopening a month', function () {
    $closure = MonthClosure::create(['period' => '2026-09', 'is_closed' => true, 'closed_by' => $this->admin->id, 'closed_at' => now()]);
    $closure->update(['is_closed' => false, 'reopened_by' => $this->admin->id, 'reopened_at' => now()]);
    $closure->update(['is_closed' => true]);

    expect(entriesFor($closure)->pluck('action')->all())->toBe(['month.closed', 'month.reopened', 'month.closed'])
        ->and(entriesFor($closure)->first()->changes)->toBe(['period' => '2026-09']);
});

it('records who did it', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs($encoder);

    $patient = Patient::factory()->create();

    expect(entriesFor($patient)->sole()->user_id)->toBe($encoder->id);
});

it('writes nothing while muted', function () {
    $patient = AuditObserver::muted(fn () => Patient::factory()->create());

    expect(entriesFor($patient)->exists())->toBeFalse();

    $patient->update(['first_name' => 'Pedro']);
    expect(entriesFor($patient)->exists())->toBeTrue();
});

it('does not log the starting data from the seeder', function () {
    config(['census.seed.admin_password' => 'x-admin-pass', 'census.seed.sysadmin_password' => 'x-sysadmin-pass']);
    $before = AuditLog::count();

    $this->seed(DatabaseSeeder::class);

    expect(AuditLog::count())->toBe($before);
});
