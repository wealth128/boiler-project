<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Services\TrashService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Trash screen (GET /trash) and its actions: Restore, Delete
 * permanently and Empty Trash. Admin only. Every rule lives in TrashPolicy
 * and TrashService (tested on their own); these tests check what the screen
 * shows and what the routes do with the answers. Rules:
 * planning/database-schema.md section 5, planning/rbac.md. Times are
 * Asia/Manila.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin', ['username' => 'admin.garcia']);
    $this->sysadmin = userWithRole('system_admin', ['username' => 'sys.reyes']);
    $this->army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL']);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
});

/**
 * Move an item to Trash at a given time, with a reason from its list.
 */
function trashTestMove(object $test, string $time, Model $item, string $reason, ?User $by = null): Model
{
    $test->travelTo(Carbon::parse($time, 'Asia/Manila'));
    $item->moveToTrash($by ?? $test->admin, $reason);

    return $item;
}

/**
 * A visit in the given month (Army CPL, Hypertension).
 *
 * @param  array<string, mixed>  $attributes
 */
function trashTestVisit(object $test, string $visitedAt = '2026-10-01 09:00', array $attributes = []): Visit
{
    return Visit::factory()->create([
        'rank_id' => $test->cpl->id,
        'branch_id' => $test->army->id,
        'diagnosis_id' => $test->hypertension->id,
        'visited_at' => $visitedAt,
        ...$attributes,
    ]);
}

function trashTestClose(string $period): void
{
    MonthClosure::create(['period' => $period, 'is_closed' => true, 'closed_by' => test()->admin->id, 'closed_at' => now()]);
}

/**
 * "Delete month report" through the service, as the Reports screen does.
 */
function trashTestMonth(object $test, string $period = '2026-08', int $visits = 2): MonthDeletion
{
    foreach (range(1, $visits) as $day) {
        trashTestVisit($test, "{$period}-0{$day} 09:00");
    }

    return app(TrashService::class)->deleteMonthReport($period, $test->admin, 'Test / training data');
}

/**
 * @return list<array<string, mixed>>
 */
function trashTestRows(object $test): array
{
    return $test->actingAs($test->admin)->get('/trash')->inertiaProps('items.data');
}

/**
 * The row of Trash with this Item text.
 *
 * @return array<string, mixed>
 */
function trashTestRow(object $test, string $item): array
{
    return collect(trashTestRows($test))->firstWhere('item', $item) ?? throw new RuntimeException("No row \"{$item}\" in Trash.");
}

function trashTestRestoreUrl(string $type, int $id): string
{
    return "/trash/{$type}/{$id}/restore";
}

// --- Who may open it -------------------------------------------------------

it('shows Trash to Admin', function () {
    $this->actingAs($this->admin)
        ->get('/trash')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('trash/index')
            ->has('items.data', 0)
            ->where('items.meta.total', 0));
});

it('refuses every Trash route to every other role', function (string $role) {
    $actor = userWithRole($role);
    $visit = trashTestMove($this, '2026-10-09 09:00', Visit::factory()->create(), 'Duplicate entry');

    $this->actingAs($actor)->get('/trash')->assertForbidden();
    $this->actingAs($actor)->post(trashTestRestoreUrl('visits', $visit->id))->assertForbidden();
    $this->actingAs($actor)->delete("/trash/visits/{$visit->id}", ['confirm' => $visit->visit_no])->assertForbidden();
    $this->actingAs($actor)->delete('/trash', ['confirm' => 'EMPTY TRASH'])->assertForbidden();

    expect(Visit::onlyTrashed()->count())->toBe(1);
})->with(['encoder', 'viewer', 'system_admin']);

it('sends a signed-out visitor to the login page', function () {
    $this->get('/trash')->assertRedirect('/login');
});

it('answers 404 for a type that is not in the list or an id that is not a number', function () {
    $this->actingAs($this->admin);

    $this->post('/trash/ghosts/1/restore')->assertNotFound();
    $this->delete('/trash/ghosts/1', ['confirm' => 'x'])->assertNotFound();
    $this->post('/trash/visits/abc/restore')->assertNotFound();
    $this->delete('/trash/visits/1.5', ['confirm' => 'x'])->assertNotFound();
});

// --- The table -------------------------------------------------------------

it('lists every type, newest first, with the right labels', function () {
    $visit = trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this), 'Duplicate entry');
    $patient = trashTestMove($this, '2026-10-09 09:10', Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'middle_initial' => 'L']), 'Encoded by mistake');
    trashTestMove($this, '2026-10-09 09:20', Diagnosis::factory()->create(['name' => 'Acute fever']), 'No longer used');
    trashTestMove($this, '2026-10-09 09:30', Rank::factory()->for($this->army)->create(['name' => 'SGT']), 'Duplicate item');
    trashTestMove($this, '2026-10-09 09:40', AgeBracket::create(['min_age' => 18, 'max_age' => 25, 'sort_order' => 1]), 'No longer used');
    trashTestMove($this, '2026-10-09 09:50', userWithRole('encoder', ['username' => 'er.cruz']), 'Resigned / separated', $this->sysadmin);
    $this->travelTo(Carbon::parse('2026-10-09 10:00', 'Asia/Manila'));
    $month = trashTestMonth($this);

    $rows = trashTestRows($this);

    expect(collect($rows)->pluck('type_label')->all())->toBe(['Month report', 'User account', 'Age bracket', 'Rank / type', 'Diagnosis', 'Patient', 'Visit'])
        ->and(collect($rows)->pluck('type')->all())->toBe(['months', 'users', 'age-brackets', 'ranks', 'diagnoses', 'patients', 'visits'])
        ->and(collect($rows)->pluck('item')->all())->toBe([
            'August 2026 · 2 visits',
            'er.cruz',
            '18–25',
            'SGT (Army)',
            'Acute fever',
            "{$patient->patient_no} · Santos, Maria L.",
            $visit->visit_no,
        ])
        ->and(collect($rows)->pluck('confirm_text')->all())->toBe(['2026-08', 'er.cruz', '18–25', 'SGT', 'Acute fever', $patient->patient_no, $visit->visit_no])
        ->and(collect($rows)->pluck('reason')->all())->toBe(['Test / training data', 'Resigned / separated', 'No longer used', 'Duplicate item', 'No longer used', 'Encoded by mistake', 'Duplicate entry'])
        ->and(collect($rows)->pluck('deleted_by')->all())->toBe(['admin.garcia', 'sys.reyes', 'admin.garcia', 'admin.garcia', 'admin.garcia', 'admin.garcia', 'admin.garcia'])
        ->and($rows[0]['deleted_at'])->toBe(Carbon::parse('2026-10-09 10:00', 'Asia/Manila')->toIso8601String())
        ->and($rows[6]['deleted_at'])->toBe(Carbon::parse('2026-10-09 09:00', 'Asia/Manila')->toIso8601String())
        ->and($rows[0]['id'])->toBe($month->id)
        ->and(collect($rows)->pluck('key')->unique())->toHaveCount(7)
        // Nothing here is protected, so every row can be deleted permanently.
        ->and(collect($rows)->pluck('blocked')->filter()->all())->toBe([]);

    $this->actingAs($this->admin)->get('/trash')->assertInertia(fn (Assert $page) => $page->where('items.meta.total', 7));
});

it('says "1 visit" for a month report of one visit', function () {
    trashTestMonth($this, '2026-08', 1);

    expect(trashTestRows($this)[0]['item'])->toBe('August 2026 · 1 visit');
});

it('shows the visits of a deleted month only inside its Month report row', function () {
    $month = trashTestMonth($this, '2026-08', 3);
    $alone = trashTestMove($this, '2026-10-09 15:00', trashTestVisit($this), 'Encoded by mistake');

    $rows = trashTestRows($this);

    expect(Visit::onlyTrashed()->count())->toBe(4)
        ->and(collect($rows)->pluck('type')->all())->toBe(['visits', 'months'])
        ->and(collect($rows)->pluck('item')->all())->toBe([$alone->visit_no, 'August 2026 · 3 visits'])
        ->and(collect($rows)->firstWhere('type', 'months')['id'])->toBe($month->id);

    $this->actingAs($this->admin)->get('/trash')->assertInertia(fn (Assert $page) => $page->where('items.meta.total', 2));
});

it('keeps a month report in Trash, with its visits hidden, when its restore is refused', function () {
    $month = trashTestMonth($this);
    $patient = Visit::onlyTrashed()->first()->patient;
    trashTestMove($this, '2026-10-09 16:00', $patient, 'Encoded by mistake');

    $this->actingAs($this->admin)->post(trashTestRestoreUrl('months', $month->id))->assertSessionHasErrors('item');

    expect(collect(trashTestRows($this))->pluck('type')->all())->toBe(['patients', 'months']);
});

it('shows 10 rows per page by default, with page numbers', function () {
    Visit::factory()->count(25)->create();
    Visit::query()->update(['deleted_at' => now(), 'delete_reason' => 'Duplicate entry']);
    $this->actingAs($this->admin);

    $this->get('/trash')->assertInertia(fn (Assert $page) => $page
        ->has('items.data', 10)
        ->where('items.meta', ['current_page' => 1, 'last_page' => 3, 'per_page' => 10, 'total' => 25, 'from' => 1, 'to' => 10]));

    $this->get('/trash?page=3')->assertInertia(fn (Assert $page) => $page
        ->has('items.data', 5)
        ->where('items.meta.current_page', 3)
        ->where('items.meta.from', 21));

    $this->get('/trash?page=4')->assertRedirect('/trash?page=3');
});

it('shows 20 or 50 rows per page when asked, and 10 for any other value', function (string $query, int $rows, int $perPage) {
    Visit::factory()->count(60)->create();
    Visit::query()->update(['deleted_at' => now(), 'delete_reason' => 'Duplicate entry']);

    $this->actingAs($this->admin)->get('/trash'.$query)->assertInertia(fn (Assert $page) => $page
        ->has('items.data', $rows)
        ->where('items.meta.per_page', $perPage));
})->with([
    '20' => ['?per_page=20', 20, 20],
    '50' => ['?per_page=50', 50, 50],
    '999' => ['?per_page=999', 10, 10],
    'text' => ['?per_page=abc', 10, 10],
    'a list' => ['?per_page[]=20', 10, 10],
]);

it('keeps the rows per page when it goes to the last page', function () {
    trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this), 'Duplicate entry');

    $this->actingAs($this->admin)->get('/trash?per_page=20&page=3')->assertRedirect('/trash?per_page=20&page=1');
});

it('marks the items that can not be deleted permanently, with the reason', function () {
    $closed = trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this, '2026-09-10 09:00'), 'Duplicate entry');
    trashTestClose('2026-09');
    $open = trashTestMove($this, '2026-10-09 09:10', trashTestVisit($this, '2026-10-02 09:00'), 'Duplicate entry');

    expect(trashTestRow($this, $closed->visit_no)['blocked'])->toBe('September 2026 is closed. The Admin must reopen it first.')
        ->and(trashTestRow($this, $open->visit_no)['blocked'])->toBeNull();
});

// --- Restore ---------------------------------------------------------------

it('restores each type with one click and writes the audit entry', function (string $type, Closure $make, string $action) {
    $item = $make($this);
    $this->actingAs($this->admin)
        ->post(trashTestRestoreUrl($type, $item->id))
        ->assertRedirect('/trash')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect(trashTestRows($this))->toBe([])
        ->and(AuditLog::where('action', $action)->sole()->user_id)->toBe($this->admin->id);
})->with([
    'visit' => ['visits', fn ($t) => trashTestMove($t, '2026-10-09 09:00', trashTestVisit($t), 'Duplicate entry'), 'visit.restored'],
    'patient' => ['patients', fn ($t) => trashTestMove($t, '2026-10-09 09:00', Patient::factory()->create(), 'Encoded by mistake'), 'patient.restored'],
    'user' => ['users', fn ($t) => trashTestMove($t, '2026-10-09 09:00', userWithRole('encoder'), 'Created by mistake', $t->sysadmin), 'user.restored'],
    'diagnosis' => ['diagnoses', fn ($t) => trashTestMove($t, '2026-10-09 09:00', Diagnosis::factory()->create(), 'No longer used'), 'diagnosis.restored'],
    'rank' => ['ranks', fn ($t) => trashTestMove($t, '2026-10-09 09:00', Rank::factory()->for($t->army)->create(['name' => 'SGT']), 'Duplicate item'), 'rank.restored'],
    'age bracket' => ['age-brackets', fn ($t) => trashTestMove($t, '2026-10-09 09:00', AgeBracket::create(['min_age' => 90, 'max_age' => null, 'sort_order' => 9]), 'No longer used'), 'age_bracket.restored'],
    'month report' => ['months', fn ($t) => trashTestMonth($t), 'month.report_restored'],
]);

it('restores all visits of a month report together', function () {
    $month = trashTestMonth($this, '2026-08', 3);

    $this->actingAs($this->admin)->post(trashTestRestoreUrl('months', $month->id))->assertSessionHasNoErrors();

    expect(Visit::onlyTrashed()->count())->toBe(0)
        ->and(Visit::whereNull('month_deletion_id')->count())->toBe(3)
        ->and(MonthDeletion::count())->toBe(0)
        ->and(AuditLog::where('action', 'month.report_restored')->count())->toBe(1);
});

it('comes back to the page the click came from', function () {
    $visit = trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this), 'Duplicate entry');

    $this->actingAs($this->admin)
        ->post(trashTestRestoreUrl('visits', $visit->id), ['page' => 2])
        ->assertRedirect('/trash?page=2');
});

it('keeps the rows per page after Restore, Delete permanently and Empty Trash', function () {
    $restore = trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this), 'Duplicate entry');
    $purge = trashTestMove($this, '2026-10-09 09:05', trashTestVisit($this), 'Duplicate entry');
    $this->actingAs($this->admin);

    $this->post(trashTestRestoreUrl('visits', $restore->id), ['page' => 2, 'per_page' => 20])
        ->assertRedirect('/trash?page=2&per_page=20');

    $this->delete("/trash/visits/{$purge->id}", ['confirm' => $purge->visit_no, 'per_page' => 50])
        ->assertRedirect('/trash?per_page=50');

    $this->delete('/trash', ['confirm' => 'EMPTY TRASH', 'per_page' => 20])->assertRedirect('/trash?per_page=20');

    // The default size, and a size that is not offered, are left out.
    $again = trashTestMove($this, '2026-10-09 09:10', trashTestVisit($this), 'Duplicate entry');
    $this->post(trashTestRestoreUrl('visits', $again->id), ['per_page' => 10])->assertRedirect('/trash');

    $last = trashTestMove($this, '2026-10-09 09:15', trashTestVisit($this), 'Duplicate entry');
    $this->post(trashTestRestoreUrl('visits', $last->id), ['per_page' => 999])->assertRedirect('/trash');
});

it('shows a refused Restore as a message for that row, not a 403 page', function (Closure $make, string $type, Closure $expected, ?Closure $inTrash = null) {
    $item = $make($this);

    $this->actingAs($this->admin)
        ->from('/trash')
        ->post(trashTestRestoreUrl($type, $item->id))
        ->assertRedirect('/trash')
        ->assertSessionHasErrors(['item' => $expected($this, $item)]);

    $this->actingAs($this->admin)->get('/trash')->assertOk();
    expect(trashTestRows($this))->not->toBe([]);
})->with([
    'a visit whose patient is in Trash' => [
        fn ($t) => tap(trashTestMove($t, '2026-10-09 09:00', trashTestVisit($t), 'Duplicate entry'), fn (Visit $v) => trashTestMove($t, '2026-10-09 09:05', $v->patient, 'Encoded by mistake')),
        'visits',
        fn ($t, $item) => 'Restore the patient first.',
    ],
    'a month report with a patient in Trash' => [
        function ($t) {
            $month = trashTestMonth($t);
            trashTestMove($t, '2026-10-09 09:05', Visit::onlyTrashed()->orderBy('id')->first()->patient, 'Encoded by mistake');

            return $month;
        },
        'months',
        fn ($t, $item) => 'Restore patient '.Patient::onlyTrashed()->value('patient_no').' first.',
    ],
    'a diagnosis whose name is now used' => [
        function ($t) {
            $gone = trashTestMove($t, '2026-10-09 09:00', Diagnosis::factory()->create(['name' => 'Acute fever']), 'No longer used');
            Diagnosis::factory()->create(['name' => 'acute  FEVER']);

            return $gone;
        },
        'diagnoses',
        fn ($t, $item) => "Can't restore “Acute fever”: “acute  FEVER” is already in the list. Rename or delete that one first.",
    ],
    'an age bracket that overlaps an active one' => [
        function ($t) {
            $gone = trashTestMove($t, '2026-10-09 09:00', AgeBracket::create(['min_age' => 90, 'max_age' => null, 'sort_order' => 9]), 'No longer used');
            AgeBracket::create(['min_age' => 95, 'max_age' => 100, 'sort_order' => 10]);

            return $gone;
        },
        'age-brackets',
        fn ($t, $item) => "Can't restore 90 & above: it overlaps 95–100. Edit or delete that one first.",
    ],
    'a second active Admin' => [
        fn ($t) => trashTestMove($t, '2026-10-09 09:00', userWithRole('admin'), 'Resigned / separated', $t->sysadmin),
        'users',
        fn ($t, $item) => 'There is already an active Admin account. Only one is allowed.',
    ],
    'a second active System Admin' => [
        fn ($t) => trashTestMove($t, '2026-10-09 09:00', userWithRole('system_admin'), 'Resigned / separated', $t->admin),
        'users',
        fn ($t, $item) => 'There is already an active System Admin account. Only one is allowed.',
    ],
    'a visit of a closed month' => [
        function ($t) {
            $visit = trashTestMove($t, '2026-10-09 09:00', trashTestVisit($t, '2026-09-10 09:00'), 'Duplicate entry');
            trashTestClose('2026-09');

            return $visit;
        },
        'visits',
        fn ($t, $item) => 'September 2026 is closed. The Admin must reopen it first.',
    ],
    'a month report of a closed month' => [
        function ($t) {
            $month = trashTestMonth($t, '2026-08');
            trashTestClose('2026-08');

            return $month;
        },
        'months',
        fn ($t, $item) => 'August 2026 is closed. The Admin must reopen it first.',
    ],
]);

it('does not restore a visit that belongs to a deleted month report on its own', function () {
    $month = trashTestMonth($this);
    $visit = Visit::onlyTrashed()->where('month_deletion_id', $month->id)->firstOrFail();

    $this->actingAs($this->admin)
        ->post(trashTestRestoreUrl('visits', $visit->id))
        ->assertSessionHasErrors(['item' => 'This visit is part of a deleted month report. Restore or delete the whole month report instead.']);
});

it('says so when the item is no longer in Trash', function () {
    $this->actingAs($this->admin)
        ->post(trashTestRestoreUrl('visits', 999))
        ->assertRedirect('/trash')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'That item is no longer in Trash.');
});

it('leaves a visit alone when it is not in Trash', function () {
    $visit = Visit::factory()->create();

    $this->actingAs($this->admin)->post(trashTestRestoreUrl('visits', $visit->id))->assertRedirect('/trash');

    expect(AuditLog::where('action', 'visit.restored')->exists())->toBeFalse();
});

// --- Delete permanently ----------------------------------------------------

it('deletes permanently only when the exact ID is typed', function (mixed $typed) {
    $visit = trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this), 'Duplicate entry');
    $typed = match ($typed) {
        'LOWER' => strtolower($visit->visit_no),
        'SHORT' => substr($visit->visit_no, 0, -1),
        'LIST' => [$visit->visit_no],
        default => $typed,
    };

    $this->actingAs($this->admin)
        ->from('/trash')
        ->delete("/trash/visits/{$visit->id}", ['confirm' => $typed])
        ->assertRedirect('/trash')
        ->assertSessionHasErrors(['confirm' => "Type {$visit->visit_no} exactly to confirm."]);

    expect(Visit::onlyTrashed()->count())->toBe(1)
        ->and(AuditLog::where('action', 'visit.purged')->exists())->toBeFalse();
})->with([
    'nothing' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'another ID' => ['V-999999'],
    'lower case' => ['LOWER'],
    'a letter short' => ['SHORT'],
    'a list' => ['LIST'],
]);

it('ignores spaces around the typed ID and logs only the ID', function () {
    $visit = trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this), 'Duplicate entry');

    $this->actingAs($this->admin)
        ->delete("/trash/visits/{$visit->id}", ['confirm' => "  {$visit->visit_no}\t"])
        ->assertRedirect('/trash')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', "{$visit->visit_no} permanently deleted");

    expect(Visit::withTrashed()->count())->toBe(0)
        ->and(AuditLog::where('action', 'visit.purged')->sole()->changes)->toBe(['label' => $visit->visit_no]);
});

it('asks for the ID, username, name, bracket label or month of each type', function (string $type, Closure $make, string $typed, string $action) {
    $item = $make($this);

    $this->actingAs($this->admin)->delete("/trash/{$type}/{$item->id}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
    expect(trashTestRows($this))->not->toBe([]);

    $this->actingAs($this->admin)->delete("/trash/{$type}/{$item->id}", ['confirm' => match ($typed) {
        'VISIT_NO' => $item->visit_no,
        'PATIENT_NO' => $item->patient_no,
        default => $typed,
    }])->assertSessionHasNoErrors();

    expect(trashTestRows($this))->toBe([])
        ->and(AuditLog::where('action', $action)->count())->toBe(1);
})->with([
    'visit' => ['visits', fn ($t) => trashTestMove($t, '2026-10-09 09:00', trashTestVisit($t), 'Duplicate entry'), 'VISIT_NO', 'visit.purged'],
    'patient' => ['patients', fn ($t) => trashTestMove($t, '2026-10-09 09:00', Patient::factory()->create(), 'Encoded by mistake'), 'PATIENT_NO', 'patient.purged'],
    'user' => ['users', fn ($t) => trashTestMove($t, '2026-10-09 09:00', userWithRole('encoder', ['username' => 'er.cruz']), 'Created by mistake', $t->sysadmin), 'er.cruz', 'user.purged'],
    'diagnosis' => ['diagnoses', fn ($t) => trashTestMove($t, '2026-10-09 09:00', Diagnosis::factory()->create(['name' => 'Acute fever']), 'No longer used'), 'Acute fever', 'diagnosis.purged'],
    'rank' => ['ranks', fn ($t) => trashTestMove($t, '2026-10-09 09:00', Rank::factory()->for($t->army)->create(['name' => 'SGT']), 'Duplicate item'), 'SGT', 'rank.purged'],
    'age bracket, with the dash typed on the keyboard' => ['age-brackets', fn ($t) => trashTestMove($t, '2026-10-09 09:00', AgeBracket::create(['min_age' => 18, 'max_age' => 25, 'sort_order' => 1]), 'No longer used'), '18-25', 'age_bracket.purged'],
    'age bracket, with the dash as shown' => ['age-brackets', fn ($t) => trashTestMove($t, '2026-10-09 09:00', AgeBracket::create(['min_age' => 18, 'max_age' => 25, 'sort_order' => 1]), 'No longer used'), '18–25', 'age_bracket.purged'],
    'open-ended age bracket' => ['age-brackets', fn ($t) => trashTestMove($t, '2026-10-09 09:00', AgeBracket::create(['min_age' => 60, 'max_age' => null, 'sort_order' => 1]), 'No longer used'), '60 & above', 'age_bracket.purged'],
    'month report' => ['months', fn ($t) => trashTestMonth($t), '2026-08', 'month.report_purged'],
]);

it('deletes a whole month report and its visits permanently', function () {
    $month = trashTestMonth($this, '2026-08', 3);

    $this->actingAs($this->admin)->delete("/trash/months/{$month->id}", ['confirm' => '2026-08'])->assertSessionHasNoErrors();

    expect(Visit::withTrashed()->count())->toBe(0)
        ->and(MonthDeletion::count())->toBe(0)
        ->and(AuditLog::where('action', 'month.report_purged')->sole()->changes)->toEqual(['period' => '2026-08', 'visit_count' => 3]);
});

it('refuses to delete permanently what history needs, with the reason', function (Closure $make, string $type, string $message) {
    $item = $make($this);
    $row = collect(trashTestRows($this))->firstWhere('key', "{$type}-{$item->id}");

    // The row says so, so the button is not offered...
    expect($row['blocked'])->toBe($message);

    // ...and the server refuses even with the right ID typed.
    $this->actingAs($this->admin)
        ->from('/trash')
        ->delete("/trash/{$type}/{$item->id}", ['confirm' => $row['confirm_text']])
        ->assertRedirect('/trash')
        ->assertSessionHasErrors(['item' => $message]);

    expect(trashTestRows($this))->not->toBe([])
        ->and(AuditLog::where('action', 'like', '%.purged')->orWhere('action', 'like', '%.report_purged')->exists())->toBeFalse();
})->with([
    'a visit of a closed month' => [
        function ($t) {
            $visit = trashTestMove($t, '2026-10-09 09:00', trashTestVisit($t, '2026-09-10 09:00'), 'Duplicate entry');
            trashTestClose('2026-09');

            return $visit;
        },
        'visits', 'September 2026 is closed. The Admin must reopen it first.',
    ],
    'a month report of a closed month' => [
        function ($t) {
            $month = trashTestMonth($t, '2026-08');
            trashTestClose('2026-08');

            return $month;
        },
        'months', 'August 2026 is closed. The Admin must reopen it first.',
    ],
    'a patient who still has visits' => [
        function ($t) {
            $visit = trashTestMove($t, '2026-10-09 09:00', trashTestVisit($t), 'Duplicate entry');

            return trashTestMove($t, '2026-10-09 09:05', $visit->patient, 'Encoded by mistake');
        },
        'patients', "This patient still has visits, so the record can't be deleted permanently.",
    ],
    'a diagnosis used by visits' => [
        function ($t) {
            trashTestVisit($t);

            return trashTestMove($t, '2026-10-09 09:00', $t->hypertension, 'No longer used');
        },
        'diagnoses', "This diagnosis is used by visits, so it can't be deleted permanently.",
    ],
    'a rank used by visits' => [
        function ($t) {
            trashTestVisit($t);

            return trashTestMove($t, '2026-10-09 09:00', $t->cpl, 'No longer used');
        },
        'ranks', "This rank is used by visits, so it can't be deleted permanently.",
    ],
    'a user with visits' => [
        function ($t) {
            $encoder = userWithRole('encoder', ['username' => 'er.cruz']);
            trashTestVisit($t, '2026-10-01 09:00', ['encoded_by' => $encoder->id]);

            return trashTestMove($t, '2026-10-09 09:00', $encoder, 'Resigned / separated', $t->sysadmin);
        },
        'users', "This account has visits or audit log entries, so it can't be deleted permanently.",
    ],
    'a user with audit entries' => [
        function ($t) {
            $encoder = userWithRole('encoder', ['username' => 'er.cruz']);
            AuditLog::create(['user_id' => $encoder->id, 'action' => 'user.login', 'subject_type' => 'users', 'subject_id' => $encoder->id]);

            return trashTestMove($t, '2026-10-09 09:00', $encoder, 'Resigned / separated', $t->sysadmin);
        },
        'users', "This account has visits or audit log entries, so it can't be deleted permanently.",
    ],
]);

// --- Empty Trash -----------------------------------------------------------

it('needs EMPTY TRASH typed exactly to empty Trash', function (mixed $typed) {
    trashTestMove($this, '2026-10-09 09:00', Diagnosis::factory()->create(), 'No longer used');

    $this->actingAs($this->admin)
        ->from('/trash')
        ->delete('/trash', ['confirm' => $typed])
        ->assertRedirect('/trash')
        ->assertSessionHasErrors(['confirm' => 'Type EMPTY TRASH exactly to confirm.']);

    expect(Diagnosis::onlyTrashed()->count())->toBe(1);
})->with([
    'nothing' => [null],
    'empty' => [''],
    'lower case' => ['empty trash'],
    'one word' => ['EMPTY'],
    'a list' => [['EMPTY TRASH']],
]);

it('deletes only what is allowed, leaves the rest, and reports the counts', function () {
    // Deleted: a visit and the patient who waited for it, an unused diagnosis, a bracket.
    $visit = trashTestMove($this, '2026-10-09 09:00', trashTestVisit($this, '2026-10-02 09:00'), 'Duplicate entry');
    trashTestMove($this, '2026-10-09 09:05', $visit->patient, 'Encoded by mistake');
    trashTestMove($this, '2026-10-09 09:10', Diagnosis::factory()->create(['name' => 'Unused']), 'No longer used');
    trashTestMove($this, '2026-10-09 09:15', AgeBracket::create(['min_age' => 90, 'max_age' => null, 'sort_order' => 9]), 'No longer used');

    // Kept: a visit of a closed month and its patient, and a diagnosis still used.
    $closed = trashTestMove($this, '2026-10-09 09:20', trashTestVisit($this, '2026-09-10 09:00'), 'Duplicate entry');
    $closedPatient = $closed->patient;
    trashTestClose('2026-09');
    trashTestMove($this, '2026-10-09 09:25', $closedPatient, 'Encoded by mistake');
    $used = Diagnosis::factory()->create(['name' => 'Used']);
    trashTestVisit($this, '2026-10-03 09:00', ['diagnosis_id' => $used->id]);
    trashTestMove($this, '2026-10-09 09:30', $used, 'No longer used');

    $this->actingAs($this->admin)
        ->delete('/trash', ['confirm' => ' EMPTY TRASH '])
        ->assertRedirect('/trash')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', "Deleted 4 items. 3 items stay in Trash because they're still needed.");

    expect(collect(trashTestRows($this))->pluck('item')->sort()->values()->all())
        ->toBe(collect([$closed->visit_no, "{$closedPatient->patient_no} · {$closedPatient->full_name}", 'Used'])->sort()->values()->all())
        ->and(AuditLog::where('action', 'like', '%.purged')->pluck('action')->sort()->values()->all())
        ->toBe(['age_bracket.purged', 'diagnosis.purged', 'patient.purged', 'visit.purged']);
});

it('words the counts for one item and for nothing left behind', function () {
    $this->actingAs($this->admin);
    trashTestMove($this, '2026-10-09 09:00', Diagnosis::factory()->create(), 'No longer used');

    $this->delete('/trash', ['confirm' => 'EMPTY TRASH'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Deleted 1 item.');

    trashTestVisit($this);
    trashTestMove($this, '2026-10-09 09:10', $this->hypertension, 'No longer used');
    trashTestMove($this, '2026-10-09 09:20', Diagnosis::factory()->create(), 'No longer used');

    $this->delete('/trash', ['confirm' => 'EMPTY TRASH'])
        ->assertSessionHas('inertia.flash_data.toast.message', "Deleted 1 item. 1 item stays in Trash because it's still needed.");

    $this->delete('/trash', ['confirm' => 'EMPTY TRASH'])
        ->assertSessionHas('inertia.flash_data.toast.type', 'info')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Deleted 0 items. 1 item stays in Trash because it\'s still needed.');
});

it('deletes a month report with Empty Trash unless its month is closed', function () {
    trashTestMonth($this, '2026-08');
    trashTestMonth($this, '2026-07');
    trashTestClose('2026-07');

    $this->actingAs($this->admin)->delete('/trash', ['confirm' => 'EMPTY TRASH'])
        ->assertSessionHas('inertia.flash_data.toast.message', "Deleted 1 item. 1 item stays in Trash because it's still needed.");

    expect(MonthDeletion::pluck('period')->all())->toBe(['2026-07'])
        ->and(Visit::onlyTrashed()->count())->toBe(2)
        ->and(AuditLog::where('action', 'month.report_purged')->count())->toBe(1);
});
