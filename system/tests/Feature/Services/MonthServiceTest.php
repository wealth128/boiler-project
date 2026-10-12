<?php

use App\Enums\Category;
use App\Models\AuditLog;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\ReportSnapshot;
use App\Models\Visit;
use App\Services\MonthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/*
 * Close / reopen a month. Closing locks it and saves a report snapshot;
 * reopening unlocks it and removes the snapshot.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 10:00', 'Asia/Manila'));
    $this->admin = userWithRole('admin');
    $this->actingAs($this->admin);
    $this->months = app(MonthService::class);
});

function septemberVisits(): void
{
    $ana = Patient::factory()->create();
    $ben = Patient::factory()->create();

    // OPD: Ana twice and Ben once = 2 patients, 3 visits.
    Visit::factory()->create(['patient_id' => $ana->id, 'category' => Category::OPD, 'visited_at' => '2026-09-01 00:00']);
    Visit::factory()->create(['patient_id' => $ana->id, 'category' => Category::OPD, 'visited_at' => '2026-09-20 10:00']);
    Visit::factory()->create(['patient_id' => $ben->id, 'category' => Category::OPD, 'visited_at' => '2026-09-30 23:59']);
    // ER: Ana once.
    Visit::factory()->create(['patient_id' => $ana->id, 'category' => Category::ER, 'visited_at' => '2026-09-05 03:00']);
    // Not counted: in Trash, or another month.
    Visit::factory()->create(['category' => Category::ER, 'visited_at' => '2026-09-06 03:00'])->moveToTrash(test()->admin, 'Test');
    Visit::factory()->create(['category' => Category::Admission, 'visited_at' => '2026-10-01 00:00']);
}

it('closes a month and saves the report counts', function () {
    septemberVisits();

    $closure = $this->months->close('2026-09', $this->admin);

    expect($closure->is_closed)->toBeTrue()
        ->and($closure->closed_by)->toBe($this->admin->id)
        ->and(MonthClosure::isClosed('2026-09'))->toBeTrue();

    $snapshot = ReportSnapshot::where('period', '2026-09')->sole();
    expect($snapshot->created_by)->toBe($this->admin->id)
        ->and($snapshot->data['categories'])->toEqual([
            'OPD' => ['patients' => 2, 'visits' => 3],
            'ER' => ['patients' => 1, 'visits' => 1],
            'Admission' => ['patients' => 0, 'visits' => 0],
        ])
        ->and(AuditLog::where('action', 'month.closed')->sole()->changes)->toBe(['period' => '2026-09']);
});

it('locks the visits of a closed month', function () {
    $visit = Visit::factory()->create(['visited_at' => '2026-09-10 08:00']);

    $this->months->close('2026-09', $this->admin);

    expect(Gate::forUser($this->admin)->allows('update', $visit))->toBeFalse();
});

it("refuses to close a month that hasn't ended, or is already closed", function () {
    expect(fn () => $this->months->close('2026-10', $this->admin))->toThrow(ValidationException::class, "October 2026 hasn't ended yet.")
        ->and(fn () => $this->months->close('2026-11', $this->admin))->toThrow(ValidationException::class);

    $this->months->close('2026-09', $this->admin);

    expect(fn () => $this->months->close('2026-09', $this->admin))->toThrow(ValidationException::class, 'already closed');
});

it('lets only Admin close or reopen', function (string $role) {
    expect(fn () => $this->months->close('2026-09', userWithRole($role)))->toThrow(AuthorizationException::class);

    $this->months->close('2026-09', $this->admin);

    expect(fn () => $this->months->reopen('2026-09', userWithRole($role)))->toThrow(AuthorizationException::class);
})->with(['encoder', 'system_admin', 'viewer']);

it('reopens a month and removes its snapshot', function () {
    $this->months->close('2026-09', $this->admin);

    $closure = $this->months->reopen('2026-09', $this->admin);

    expect($closure->is_closed)->toBeFalse()
        ->and($closure->reopened_by)->toBe($this->admin->id)
        ->and(MonthClosure::isClosed('2026-09'))->toBeFalse()
        ->and(ReportSnapshot::where('period', '2026-09')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'month.reopened')->exists())->toBeTrue();
});

it('refuses to reopen a month that is not closed', function () {
    expect(fn () => $this->months->reopen('2026-09', $this->admin))->toThrow(ValidationException::class, 'is not closed');
});

it('saves a fresh snapshot when the month is closed again', function () {
    $this->months->close('2026-09', $this->admin);
    $this->months->reopen('2026-09', $this->admin);

    Visit::factory()->create(['category' => Category::Admission, 'visited_at' => '2026-09-12 12:00']);
    $closure = $this->months->close('2026-09', $this->admin);

    expect(ReportSnapshot::where('period', '2026-09')->sole()->data['categories']['Admission'])->toEqual(['patients' => 1, 'visits' => 1])
        ->and($closure->reopened_by)->toBeNull()
        ->and(MonthClosure::count())->toBe(1)
        ->and(AuditLog::where('action', 'month.closed')->count())->toBe(2);
});

it('rejects a badly written month', function () {
    $this->months->close('2026-13', $this->admin);
})->throws(InvalidArgumentException::class);
