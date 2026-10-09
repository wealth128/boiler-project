<?php

use App\Enums\Category;
use App\Enums\Role;
use App\Enums\Sex;
use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

it('generates Patient IDs and visit numbers from the id', function () {
    $patient = Patient::factory()->create();
    $visit = Visit::factory()->for($patient)->create();

    expect($patient->fresh()->patient_no)->toBe(sprintf('P-%06d', $patient->id))
        ->and($visit->fresh()->visit_no)->toBe(sprintf('V-%06d', $visit->id));
});

it('casts enums, dates and numbers', function () {
    $visit = Visit::factory()->create(['category' => 'ER', 'visited_at' => '2026-09-15 08:30:00'])->fresh();

    expect($visit->category)->toBe(Category::ER)
        ->and($visit->visited_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($visit->period)->toBe('2026-09')
        ->and($visit->age)->toBeInt()
        ->and($visit->patient->sex)->toBeInstanceOf(Sex::class)
        ->and($visit->encoder->role)->toBe(Role::Encoder);
});

it('connects a visit to its patient, branch, rank, diagnosis and encoder', function () {
    $visit = Visit::factory()->create();

    expect($visit->patient)->toBeInstanceOf(Patient::class)
        ->and($visit->rank->branch->is($visit->branch))->toBeTrue()
        ->and($visit->diagnosis)->toBeInstanceOf(Diagnosis::class)
        ->and($visit->encoder)->toBeInstanceOf(User::class)
        ->and($visit->patient->visits->first()->is($visit))->toBeTrue()
        ->and($visit->encoder->encodedVisits->first()->is($visit))->toBeTrue();
});

it('moves a record to Trash with who and why, and restores it', function () {
    $admin = User::factory()->admin()->create();
    $visit = Visit::factory()->create();

    $visit->moveToTrash($admin, 'Duplicate entry');

    $trashed = Visit::withTrashed()->findOrFail($visit->id);
    expect(Visit::find($visit->id))->toBeNull()
        ->and($trashed->deleted_at)->not->toBeNull()
        ->and($trashed->deleted_by)->toBe($admin->id)
        ->and($trashed->delete_reason)->toBe('Duplicate entry')
        ->and($trashed->deletedBy->is($admin))->toBeTrue();

    $trashed->restoreFromTrash();

    $restored = Visit::findOrFail($visit->id);
    expect($restored->deleted_by)->toBeNull()->and($restored->delete_reason)->toBeNull();
});

it('soft deletes every model the schema marks as soft-deletable', function (string $model) {
    expect(in_array(SoftDeletes::class, class_uses_recursive($model), true))->toBeTrue();
})->with([User::class, Patient::class, Visit::class, Diagnosis::class, Rank::class, AgeBracket::class]);

it('still shows a visit\'s diagnosis and rank after they go to Trash', function () {
    $admin = User::factory()->admin()->create();
    $visit = Visit::factory()->create();

    $visit->diagnosis->moveToTrash($admin, 'Merged');
    $visit->rank->moveToTrash($admin, 'Renamed');

    $fresh = Visit::findOrFail($visit->id);
    expect($fresh->diagnosis)->not->toBeNull()->and($fresh->rank)->not->toBeNull();
});

it('groups a deleted month\'s visits under one Trash entry', function () {
    $admin = User::factory()->admin()->create();
    $deletion = MonthDeletion::create([
        'period' => '2026-09', 'visit_count' => 2, 'deleted_by' => $admin->id,
        'delete_reason' => 'Wrong month encoded', 'deleted_at' => now(),
    ]);
    $visits = Visit::factory()->count(2)->create();
    $visits->each(function (Visit $v) use ($deletion, $admin) {
        $v->month_deletion_id = $deletion->id;
        $v->moveToTrash($admin, 'Wrong month encoded');
    });

    expect($deletion->visits()->count())->toBe(2)
        ->and($deletion->deletedBy->is($admin))->toBeTrue();

    $deletion->delete(); // restore or purge removes the entry; the link is cleared
    expect(Visit::withTrashed()->whereNotNull('month_deletion_id')->count())->toBe(0);
});

it('labels age brackets and finds the bracket for an age', function () {
    $bounded = new AgeBracket(['min_age' => 18, 'max_age' => 25]);
    $open = new AgeBracket(['min_age' => 60, 'max_age' => null]);

    expect($bounded->label)->toBe('18–25')
        ->and($open->label)->toBe('60 & above')
        ->and($bounded->contains(25))->toBeTrue()
        ->and($bounded->contains(26))->toBeFalse()
        ->and($open->contains(99))->toBeTrue();
});

it('computes a patient\'s age from the birthdate', function () {
    $patient = Patient::factory()->make(['birthdate' => '1990-10-09']);

    expect($patient->ageAt(CarbonImmutable::parse('2026-10-08')))->toBe(35)
        ->and($patient->ageAt(CarbonImmutable::parse('2026-10-09')))->toBe(36);
});

it('reports whether a month is closed', function () {
    $admin = User::factory()->admin()->create();
    MonthClosure::create(['period' => '2026-09', 'is_closed' => true, 'closed_by' => $admin->id, 'closed_at' => now()]);

    expect(MonthClosure::isClosed('2026-09'))->toBeTrue()
        ->and(MonthClosure::isClosed('2026-10'))->toBeFalse();
});

it('stores audit subjects by short name and never changes an entry', function () {
    $user = User::factory()->create();
    $visit = Visit::factory()->create();

    $log = AuditLog::create([
        'user_id' => $user->id,
        'action' => 'visit.created',
        'subject_type' => $visit->getMorphClass(),
        'subject_id' => $visit->id,
        'ip_address' => '127.0.0.1',
    ]);

    expect($log->subject_type)->toBe('visits')
        ->and($log->fresh()->subject->is($visit))->toBeTrue()
        ->and($log->created_at)->not->toBeNull();

    expect(fn () => $log->update(['action' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});
