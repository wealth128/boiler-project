<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Visit;
use App\Services\ReportBuilder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Close month, Reopen month and Delete month report (Admin only):
 * POST /months/{period}/close, POST /months/{period}/reopen,
 * DELETE /months/{period}. Rules: planning/rbac.md,
 * planning/database-schema.md. "Today" is 15-Oct-2026 (Asia/Manila).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $branch = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->rank = Rank::factory()->for($branch)->create(['name' => 'CPL']);
    $this->diagnosis = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->branch = $branch;
});

/** A visit on the given date and time, for a new patient unless given. */
function monthVisit(object $test, string $when, string $category = 'ER', ?Patient $patient = null): Visit
{
    return Visit::factory()->create([
        'patient_id' => ($patient ?? Patient::factory()->create())->id,
        'visited_at' => Carbon::parse($when, 'Asia/Manila'),
        'category' => $category,
        'branch_id' => $test->branch->id,
        'rank_id' => $test->rank->id,
        'diagnosis_id' => $test->diagnosis->id,
        'encoded_by' => $test->encoder->id,
    ]);
}

function closeSeptember(object $test): void
{
    $test->actingAs($test->admin)->from('/reports')->post('/months/2026-09/close')->assertSessionHasNoErrors();
}

// --- Close ------------------------------------------------------------------

it('closes a month, saves a version 4 snapshot and shows it on the report', function () {
    monthVisit($this, '2026-09-03 09:00', 'ER');
    monthVisit($this, '2026-09-04 09:00', 'OPD');

    $this->actingAs($this->admin)->from('/reports')
        ->post('/months/2026-09/close')
        ->assertRedirect('/reports')
        ->assertSessionHasNoErrors();

    $snapshot = ReportSnapshot::query()->where('period', '2026-09')->sole();

    expect(MonthClosure::isClosed('2026-09'))->toBeTrue()
        ->and(ReportBuilder::VERSION)->toBe(4)
        ->and($snapshot->data['version'])->toBe(4)
        ->and(array_keys($snapshot->data['categories']))->toEqualCanonicalizing(['OPD', 'ER', 'Admission'])
        ->and($snapshot->data['totals'])->toMatchArray(['patients' => 2, 'visits' => 2]);

    // A visit added to Trash afterwards does not change the saved copy.
    Visit::query()->first()->moveToTrash($this->admin, 'Duplicate entry');

    $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')
        ->assertInertia(fn (Assert $page) => $page
            ->where('month_status.closed', true)
            ->where('snapshot.month_label', 'September 2026')
            ->where('report.totals', ['patients' => 2, 'visits' => 2]));
});

it('closes a month that has no visits', function () {
    $this->actingAs($this->admin)->from('/reports')->post('/months/2026-08/close')->assertSessionHasNoErrors();

    expect(MonthClosure::isClosed('2026-08'))->toBeTrue()
        ->and(ReportSnapshot::query()->where('period', '2026-08')->sole()->data['totals'])->toMatchArray(['patients' => 0, 'visits' => 0]);
});

it('does not close the current or a future month', function (string $period, string $label) {
    $this->actingAs($this->admin)->from('/reports')
        ->post("/months/{$period}/close")
        ->assertRedirect('/reports')
        ->assertSessionHasErrors(['period' => "{$label} hasn't ended yet. A month can be closed from the 1st of the next month."]);

    expect(MonthClosure::isClosed($period))->toBeFalse()
        ->and(ReportSnapshot::query()->count())->toBe(0);
})->with([
    'current month' => ['2026-10', 'October 2026'],
    'future month' => ['2026-12', 'December 2026'],
]);

it('refuses to close a month that is already closed', function () {
    closeSeptember($this);

    $this->actingAs($this->admin)->from('/reports')
        ->post('/months/2026-09/close')
        ->assertSessionHasErrors(['period' => 'September 2026 is already closed.']);

    expect(ReportSnapshot::query()->count())->toBe(1);
});

it('returns 404 for a period that is not YYYY-MM', function (string $period) {
    $this->actingAs($this->admin)->post("/months/{$period}/close")->assertNotFound();
})->with(['2026-9', '2026-13', 'september', '2026-09-01']);

// --- Reopen -----------------------------------------------------------------

it('reopens a month, removes the snapshot and counts live again', function () {
    monthVisit($this, '2026-09-03 09:00');
    closeSeptember($this);
    monthVisit($this, '2026-09-10 09:00'); // can be added to the data, not through the app

    $this->actingAs($this->admin)->from('/reports')
        ->post('/months/2026-09/reopen')
        ->assertRedirect('/reports')
        ->assertSessionHasNoErrors();

    expect(MonthClosure::isClosed('2026-09'))->toBeFalse()
        ->and(ReportSnapshot::query()->where('period', '2026-09')->exists())->toBeFalse();

    $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')
        ->assertInertia(fn (Assert $page) => $page
            ->where('month_status.closed', false)
            ->where('snapshot', null)
            ->where('report.totals', ['patients' => 2, 'visits' => 2]));
});

it('refuses to reopen a month that is not closed', function () {
    $this->actingAs($this->admin)->from('/reports')
        ->post('/months/2026-09/reopen')
        ->assertSessionHasErrors(['period' => 'September 2026 is not closed.']);
});

// --- Delete month report ----------------------------------------------------

it('moves only that month to Trash as one month report with the chosen reason', function () {
    $inSeptember = [
        monthVisit($this, '2026-09-01 00:00'),
        monthVisit($this, '2026-09-30 23:59'),
    ];
    $august = monthVisit($this, '2026-08-31 23:59');
    $october = monthVisit($this, '2026-10-01 00:00');
    $already = monthVisit($this, '2026-09-15 09:00');
    $already->moveToTrash($this->admin, 'Duplicate entry');

    $this->actingAs($this->admin)->from('/reports')
        ->delete('/months/2026-09', ['reason' => 'Test / training data'])
        ->assertRedirect('/reports')
        ->assertSessionHasNoErrors();

    $entry = MonthDeletion::query()->sole();

    expect($entry->period)->toBe('2026-09')
        ->and($entry->visit_count)->toBe(2)
        ->and($entry->delete_reason)->toBe('Test / training data')
        ->and($entry->deleted_by)->toBe($this->admin->id);

    foreach ($inSeptember as $visit) {
        $fresh = Visit::withTrashed()->find($visit->id);
        expect($fresh->trashed())->toBeTrue()
            ->and($fresh->month_deletion_id)->toBe($entry->id)
            ->and($fresh->delete_reason)->toBe('Test / training data');
    }

    // Other months stay. A visit already in Trash stays its own Trash item.
    expect(Visit::find($august->id))->not->toBeNull()
        ->and(Visit::find($october->id))->not->toBeNull()
        ->and(Visit::withTrashed()->find($already->id)->month_deletion_id)->toBeNull()
        ->and(Visit::withTrashed()->find($already->id)->delete_reason)->toBe('Duplicate entry');
});

it('saves "Other" with the typed text as the reason', function () {
    monthVisit($this, '2026-09-03 09:00');

    $this->actingAs($this->admin)->from('/reports')
        ->delete('/months/2026-09', ['reason' => 'Other (specify)', 'reason_other' => 'Wrong file imported twice'])
        ->assertSessionHasNoErrors();

    expect(MonthDeletion::query()->sole()->delete_reason)->toBe('Other: Wrong file imported twice');
});

it('rejects Other with fewer than 10 characters, and a missing or unknown reason', function (array $data, string $field) {
    monthVisit($this, '2026-09-03 09:00');

    $this->actingAs($this->admin)->from('/reports')
        ->delete('/months/2026-09', $data)
        ->assertSessionHasErrors($field);

    expect(MonthDeletion::query()->count())->toBe(0)
        ->and(Visit::query()->count())->toBe(1);
})->with([
    'short other' => [['reason' => 'Other (specify)', 'reason_other' => 'too short'], 'reason_other'],
    'no reason' => [[], 'reason'],
    'not on the list' => [['reason' => 'Because'], 'reason'],
]);

it('refuses to delete a closed month and says to reopen it', function () {
    monthVisit($this, '2026-09-03 09:00');
    closeSeptember($this);

    $this->actingAs($this->admin)->from('/reports')
        ->delete('/months/2026-09', ['reason' => 'Test / training data'])
        ->assertRedirect('/reports')
        ->assertSessionHasErrors(['period' => 'September 2026 is closed. The Admin must reopen it first.']);

    expect(Visit::query()->count())->toBe(1)
        ->and(MonthDeletion::query()->count())->toBe(0);
});

it('says so when the month has no visits to delete', function () {
    monthVisit($this, '2026-08-03 09:00');

    $this->actingAs($this->admin)->from('/reports')
        ->delete('/months/2026-09', ['reason' => 'Test / training data'])
        ->assertSessionHasErrors(['period' => 'This month has no visits to delete.']);

    expect(MonthDeletion::query()->count())->toBe(0);
});

// --- A closed month locks its visits ---------------------------------------

it('blocks editing and deleting visits of a closed month', function () {
    $visit = monthVisit($this, '2026-09-03 09:00');
    closeSeptember($this);

    $message = 'September 2026 is closed. The Admin must reopen it first.';

    // Edit (Encode screen), as Admin and as the encoder who saved it.
    foreach ([$this->admin, $this->encoder] as $user) {
        $this->actingAs($user)->put("/visits/{$visit->id}", ['remarks' => 'changed'])
            ->assertForbidden();
    }

    // Delete (Records).
    $this->actingAs($this->admin)->from('/records/visits')
        ->delete("/visits/{$visit->id}", ['reason' => 'Duplicate entry'])
        ->assertSessionHasErrors(['item' => $message]);

    expect(Visit::find($visit->id))->not->toBeNull()
        ->and(Visit::find($visit->id)->remarks)->not->toBe('changed');

    // Reopen: Admin can delete again.
    $this->actingAs($this->admin)->post('/months/2026-09/reopen');
    $this->actingAs($this->admin)->from('/records/visits')
        ->delete("/visits/{$visit->id}", ['reason' => 'Duplicate entry'])
        ->assertSessionHasNoErrors();
});

it('blocks encoding a new visit while the current month is closed', function () {
    MonthClosure::create(['period' => '2026-10', 'is_closed' => true, 'closed_by' => $this->admin->id, 'closed_at' => now()]);

    $this->actingAs($this->encoder)->post('/visits', ['last_name' => 'Cruz'])->assertForbidden();

    expect(Visit::query()->count())->toBe(0)
        ->and(Patient::query()->count())->toBe(0);
});

// --- Audit log --------------------------------------------------------------

it('writes close, reopen and delete month report to the audit log', function () {
    monthVisit($this, '2026-08-03 09:00');
    monthVisit($this, '2026-09-03 09:00');

    $this->actingAs($this->admin)->post('/months/2026-08/close');
    $this->actingAs($this->admin)->post('/months/2026-08/reopen');
    $this->actingAs($this->admin)->delete('/months/2026-09', ['reason' => 'Encoded in the wrong month']);

    $actions = AuditLog::query()->whereIn('action', ['month.closed', 'month.reopened', 'month.report_deleted'])->orderBy('id')->get();

    expect($actions->pluck('action')->all())->toBe(['month.closed', 'month.reopened', 'month.report_deleted'])
        ->and($actions->pluck('user_id')->unique()->all())->toBe([$this->admin->id])
        ->and($actions[0]->changes)->toMatchArray(['period' => '2026-08'])
        ->and($actions[2]->changes)->toMatchArray(['period' => '2026-09', 'visit_count' => 1, 'reason' => 'Encoded in the wrong month']);
});

// --- Who sees and does what -------------------------------------------------

it('shows Admin the buttons and the visit count, and Viewer only the status', function () {
    monthVisit($this, '2026-09-03 09:00');
    monthVisit($this, '2026-09-04 09:00');

    $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')
        ->assertInertia(fn (Assert $page) => $page
            ->where('month_status', ['period' => '2026-09', 'closed' => false, 'visit_count' => 2, 'can_manage' => true])
            ->has('month_delete_reasons', 3));

    $this->actingAs(userWithRole('viewer'))->get('/reports?type=monthly&month=2026-09')
        ->assertInertia(fn (Assert $page) => $page
            ->where('month_status.closed', false)
            ->where('month_status.can_manage', false)
            ->where('month_delete_reasons', []));

    // No status for the other period types.
    $this->actingAs($this->admin)->get('/reports?type=annual&year=2026')
        ->assertInertia(fn (Assert $page) => $page->where('month_status', null));
});

it('gives 403 on the three month routes to everyone but Admin', function (string $role) {
    monthVisit($this, '2026-09-03 09:00');
    $user = userWithRole($role);

    $this->actingAs($user)->post('/months/2026-09/close')->assertForbidden();
    $this->actingAs($user)->post('/months/2026-09/reopen')->assertForbidden();
    $this->actingAs($user)->delete('/months/2026-09', ['reason' => 'Test / training data'])->assertForbidden();

    expect(MonthClosure::query()->count())->toBe(0)
        ->and(Visit::query()->count())->toBe(1)
        ->and(MonthDeletion::query()->count())->toBe(0);
})->with(['viewer', 'encoder', 'system_admin']);
