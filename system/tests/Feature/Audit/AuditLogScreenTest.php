<?php

use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditActions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Audit log screen (GET /audit-log). Admin only, read-only. Rules:
 * planning/rbac.md ("View audit log"), planning/database-schema.md
 * (audit_logs), planning/security.md section 2. Times are Asia/Manila. With
 * no dates in the URL the range is 01-Sep-2026 to 09-Oct-2026.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin', ['username' => 'admin.garcia', 'name' => 'Ana Garcia']);
    $this->encoder = userWithRole('encoder', ['username' => 'er.cruz', 'name' => 'Ed Cruz']);
    $this->army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'is_afp' => true, 'sort_order' => 2]);
    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL']);
    $this->ens = Rank::factory()->for($this->navy)->create(['name' => 'ENS']);
    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->asthma = Diagnosis::factory()->create(['name' => 'Asthma']);
    $this->santos = Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'middle_initial' => 'L', 'sex' => 'Female']);
    $this->visit = Visit::factory()->create([
        'patient_id' => $this->santos->id,
        'rank_id' => $this->cpl->id,
        'branch_id' => $this->army->id,
        'diagnosis_id' => $this->hypertension->id,
        'encoded_by' => $this->encoder->id,
        'category' => 'ER',
        'visited_at' => '2026-10-01 09:00',
    ]);
    $this->visit->refresh();

    // Setting up the data above wrote entries of its own. Start empty.
    DB::table('audit_logs')->delete();
});

/**
 * One audit entry at a given Manila time.
 *
 * @param  Model|array{0: string, 1: int}  $subject  A record, or [subject_type, subject_id]
 * @param  array<string, mixed>|null  $changes
 */
function auditEntry(string $at, ?User $by, string $action, Model|array $subject, ?array $changes = null, ?string $ip = '10.0.0.5'): AuditLog
{
    [$type, $id] = is_array($subject) ? $subject : [$subject->getMorphClass(), $subject->getKey()];

    $entry = AuditLog::create([
        'user_id' => $by?->id,
        'action' => $action,
        'subject_type' => $type,
        'subject_id' => $id,
        'changes' => $changes,
        'ip_address' => $ip,
    ]);

    DB::table('audit_logs')->where('id', $entry->id)->update(['created_at' => Carbon::parse($at, 'Asia/Manila')]);

    return $entry;
}

function auditGet(object $test, string $query = '')
{
    return $test->actingAs($test->admin)->get('/audit-log'.$query);
}

/** One prop of the screen (dot notation), as the Admin sees it. */
function auditProp(object $test, string $key, string $query = ''): mixed
{
    $value = null;

    auditGet($test, $query)->assertOk()->assertInertia(function (Assert $page) use (&$value, $key) {
        $value = data_get($page->toArray()['props'], $key);
    });

    return $value;
}

/** The ids of the entries shown, in order. */
function auditIds(object $test, string $query = ''): array
{
    return collect(auditProp($test, 'entries.data', $query))->pluck('id')->all();
}

it('shows entries newest first with plain labels, user, record and PC', function () {
    $oldest = auditEntry('2026-10-02 09:00', $this->encoder, 'visit.created', $this->visit, ['new' => ['age' => 40]], '10.0.0.5');
    $middle = auditEntry('2026-10-05 10:30', $this->encoder, 'visit.deleted', $this->visit, ['reason' => 'Duplicate entry'], '10.0.0.6');
    $newest = auditEntry('2026-10-08 17:45', $this->admin, 'month.closed', ['month_closures', 1], ['period' => '2026-09'], '10.0.0.9');

    auditGet($this)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('audit/index')
        ->where('entries.meta.total', 3)
        ->where('entries.data.0.id', $newest->id)
        ->where('entries.data.0.action_label', 'Month closed')
        ->where('entries.data.0.record', 'September 2026')
        ->where('entries.data.0.username', 'admin.garcia')
        ->where('entries.data.0.user_name', 'Ana Garcia')
        ->where('entries.data.0.ip_address', '10.0.0.9')
        ->where('entries.data.1.id', $middle->id)
        ->where('entries.data.1.action_label', 'Visit moved to Trash')
        ->where('entries.data.1.record', $this->visit->visit_no)
        ->where('entries.data.1.username', 'er.cruz')
        ->where('entries.data.1.ip_address', '10.0.0.6')
        ->where('entries.data.2.id', $oldest->id)
        ->where('entries.data.2.action_label', 'Visit encoded'));
});

it('shows the Patient ID with the name, the username, and list items by name', function () {
    $rank = auditEntry('2026-10-02 09:00', $this->admin, 'rank.created', $this->cpl);
    $diagnosis = auditEntry('2026-10-02 09:01', $this->admin, 'diagnosis.updated', $this->hypertension);
    $user = auditEntry('2026-10-02 09:02', $this->admin, 'user.unlocked', $this->encoder, ['failed_attempts' => 5]);
    $patient = auditEntry('2026-10-02 09:03', $this->admin, 'patient.updated', $this->santos);
    $bracket = AgeBracket::create(['min_age' => 18, 'max_age' => 25]);
    $age = auditEntry('2026-10-02 09:04', $this->admin, 'age_bracket.created', $bracket);

    $records = collect(auditProp($this, 'entries.data'))->pluck('record', 'id');

    expect($records[$rank->id])->toBe('CPL (Army)')
        ->and($records[$diagnosis->id])->toBe('Hypertension')
        ->and($records[$user->id])->toBe('er.cruz')
        ->and($records[$patient->id])->toBe("{$this->santos->patient_no} · {$this->santos->full_name}")
        ->and($records[$age->id])->toBe($bracket->label);
});

it('names the people and places in Details instead of showing IDs', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'visit.updated', $this->visit, [
        'old' => ['branch_id' => $this->army->id, 'rank_id' => $this->cpl->id, 'diagnosis_id' => $this->hypertension->id, 'visited_at' => '2026-10-01 09:00:00', 'updated_by' => null],
        'new' => ['branch_id' => $this->navy->id, 'rank_id' => $this->ens->id, 'diagnosis_id' => $this->asthma->id, 'visited_at' => '2026-10-02 10:30:00', 'updated_by' => $this->admin->id],
    ]);

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.details', [
            ['field' => 'Date & time', 'old' => '01-Oct-2026 09:00', 'new' => '02-Oct-2026 10:30'],
            ['field' => 'Branch', 'old' => 'Army', 'new' => 'Navy'],
            ['field' => 'Rank / type', 'old' => 'CPL', 'new' => 'ENS'],
            ['field' => 'Diagnosis', 'old' => 'Hypertension', 'new' => 'Asthma'],
            ['field' => 'Edited by', 'old' => '—', 'new' => 'admin.garcia'],
        ]));
});

it('shows a birthdate as DD-Mon-YYYY and a new record without an old value', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'patient.created', $this->santos, [
        'new' => ['last_name' => 'Santos', 'birthdate' => '1990-03-07'],
    ]);

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.details', [
            ['field' => 'Last name', 'old' => null, 'new' => 'Santos'],
            ['field' => 'Birthdate', 'old' => null, 'new' => '07-Mar-1990'],
        ]));
});

it('shows a delete reason as it was saved', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'visit.deleted', $this->visit, ['reason' => 'Other: patient left before triage']);

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.details', [
            ['field' => 'Reason', 'old' => null, 'new' => 'Other: patient left before triage'],
        ]));
});

it('has no Details for an entry with nothing to list', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'visit.restored', $this->visit);

    auditGet($this)->assertInertia(fn (Assert $page) => $page->where('entries.data.0.details', []));
});

it('labels why the system signed someone out', function () {
    auditEntry('2026-10-03 08:00', $this->encoder, 'user.session_ended', $this->encoder, ['reason' => 'deactivated']);

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.action_label', 'Signed out (account deactivated)'));
});

it('keeps every action label in one map, with an unknown code shown as itself', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'gadget.exploded', $this->visit);

    auditGet($this)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.action_label', 'gadget.exploded'));

    expect(AuditActions::label('gadget.exploded'))->toBe('gadget.exploded')
        ->and(AuditActions::label('visit.created'))->toBe('Visit encoded')
        ->and(AuditActions::label('user.password_reset'))->toBe('Password reset')
        ->and(AuditActions::label('user.password_changed'))->toBe('Password changed')
        ->and(AuditActions::label('user.unlocked'))->toBe('User unlocked')
        ->and(AuditActions::label('visit.purged'))->toBe('Visit deleted permanently');
});

it('still shows the saved ID or name of a record deleted permanently', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'visit.purged', ['visits', 987654], ['label' => 'V-987654']);
    auditEntry('2026-10-03 08:01', $this->admin, 'user.purged', ['users', 987655], ['label' => 'old.encoder']);
    auditEntry('2026-10-03 08:02', $this->admin, 'month.report_purged', ['month_deletions', 987656], ['period' => '2026-08', 'visit_count' => 40]);

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.record', 'August 2026')
        ->where('entries.data.1.record', 'old.encoder')
        ->where('entries.data.2.record', 'V-987654'));
});

it('falls back to the number for an entry whose record is gone and saved nothing', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'visit.updated', ['visits', 987654]);

    auditGet($this)->assertInertia(fn (Assert $page) => $page->where('entries.data.0.record', '#987654'));
});

it('never sends passwords or password hashes to the page', function () {
    auditEntry('2026-10-03 08:00', $this->admin, 'user.updated', $this->encoder, [
        'old' => ['password' => 'OLDHASH-xyz', 'name' => 'Ed Cruz'],
        'new' => ['password' => 'NEWHASH-xyz', 'remember_token' => 'TOKEN-xyz', 'name' => 'Edward Cruz'],
        'new_password' => 'PLAIN-xyz',
        'Password_Hash' => 'HASH2-xyz',
        'nested' => ['current_password' => 'NESTED-xyz', 'ok' => 'fine'],
    ]);

    $response = auditGet($this)->assertOk();

    foreach (['OLDHASH-xyz', 'NEWHASH-xyz', 'TOKEN-xyz', 'PLAIN-xyz', 'HASH2-xyz', 'NESTED-xyz'] as $secret) {
        $response->assertDontSee($secret, false);
    }

    $response->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.details', [
            ['field' => 'Name', 'old' => 'Ed Cruz', 'new' => 'Edward Cruz'],
            ['field' => 'Nested', 'old' => null, 'new' => '{"ok":"fine"}'],
        ]));
});

it('filters by date range', function () {
    $august = auditEntry('2026-08-15 09:00', $this->admin, 'visit.created', $this->visit);
    $sept1 = auditEntry('2026-09-01 00:00', $this->admin, 'visit.created', $this->visit);
    $sept30 = auditEntry('2026-09-30 23:59', $this->admin, 'visit.created', $this->visit);
    $oct9 = auditEntry('2026-10-09 08:00', $this->admin, 'visit.created', $this->visit);

    // Default: first day of last month to today.
    expect(auditIds($this))->toBe([$oct9->id, $sept30->id, $sept1->id])
        ->and(auditIds($this, '?from=2026-09-30&to=2026-09-30'))->toBe([$sept30->id])
        ->and(auditIds($this, '?from=2026-08-01&to=2026-08-31'))->toBe([$august->id])
        ->and(auditIds($this, '?from=2026-09-15'))->toBe([$oct9->id, $sept30->id])
        ->and(auditIds($this, '?to=2026-09-01'))->toBe([$sept1->id])
        // Empty From and To mean no limit.
        ->and(auditIds($this, '?from=&to='))->toBe([$oct9->id, $sept30->id, $sept1->id, $august->id])
        ->and(auditIds($this, '?from=2026-10-01&to=2026-09-01'))->toBe([]);

    auditGet($this, '?from=2026-08-01&to=2026-08-31')->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2026-08-01')->where('filters.to', '2026-08-31'));
});

it('filters by user, including deactivated users and users in Trash', function () {
    $mine = auditEntry('2026-10-02 09:00', $this->admin, 'visit.created', $this->visit);
    $theirs = auditEntry('2026-10-02 10:00', $this->encoder, 'visit.created', $this->visit);
    $system = auditEntry('2026-10-02 11:00', null, 'user.deactivated', $this->encoder, null, null);

    expect(auditIds($this, "?user={$this->encoder->id}"))->toBe([$theirs->id])
        ->and(auditIds($this, "?user={$this->admin->id}"))->toBe([$mine->id])
        ->and(auditIds($this))->toBe([$system->id, $theirs->id, $mine->id]);

    $gone = userWithRole('encoder', ['username' => 'old.encoder', 'name' => 'Old Encoder', 'is_active' => false]);
    $gone->delete();

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->has('users', 3)
        ->where('users.0.id', $this->admin->id)
        ->where('users.0.label', 'admin.garcia · Ana Garcia')
        ->where('users.1.label', 'er.cruz · Ed Cruz')
        ->where('users.2.label', 'old.encoder · Old Encoder (in Trash)'));
});

it('filters by action and lists every action by plain label in groups', function () {
    $created = auditEntry('2026-10-02 09:00', $this->admin, 'visit.created', $this->visit);
    $updated = auditEntry('2026-10-02 10:00', $this->admin, 'visit.updated', $this->visit);
    auditEntry('2026-10-02 11:00', $this->admin, 'visit.deleted', $this->visit);

    expect(auditIds($this, '?action=visit.created'))->toBe([$created->id])
        ->and(auditIds($this, '?action=visit.updated'))->toBe([$updated->id]);

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->where('actions.0.group', 'Visits')
        ->where('actions.0.options.0', ['value' => 'visit.created', 'label' => 'Visit encoded'])
        ->where('actions.1.group', 'Patients')
        ->where('actions.2.group', 'Months')
        ->where('actions.3.group', 'Lists')
        ->where('actions.4.group', 'Users & logins')
        ->where('actions.5.group', 'Trash'));
});

it('filters by Visit No., Patient ID or username, exactly', function () {
    $other = Visit::factory()->create([
        'patient_id' => $this->santos->id, 'rank_id' => $this->cpl->id, 'branch_id' => $this->army->id,
        'diagnosis_id' => $this->hypertension->id, 'encoded_by' => $this->encoder->id,
    ]);
    $other->refresh();

    $visitEntry = auditEntry('2026-10-02 09:00', $this->admin, 'visit.created', $this->visit);
    auditEntry('2026-10-02 09:30', $this->admin, 'visit.created', $other);
    $patientEntry = auditEntry('2026-10-02 10:00', $this->admin, 'patient.updated', $this->santos);
    $userEntry = auditEntry('2026-10-02 11:00', $this->admin, 'user.unlocked', $this->encoder);
    $purged = auditEntry('2026-10-02 12:00', $this->admin, 'visit.purged', ['visits', 987654], ['label' => 'V-987654']);

    expect(auditIds($this, '?record='.$this->visit->visit_no))->toBe([$visitEntry->id])
        ->and(auditIds($this, '?record='.$this->santos->patient_no))->toBe([$patientEntry->id])
        ->and(auditIds($this, '?record=er.cruz'))->toBe([$userEntry->id])
        ->and(auditIds($this, '?record=V-987654'))->toBe([$purged->id])
        // Exact, not "contains".
        ->and(auditIds($this, '?record=er.cru'))->toBe([])
        ->and(auditIds($this, '?record=V-00'))->toBe([])
        ->and(auditIds($this, '?record=nobody'))->toBe([]);

    auditGet($this, '?record=nobody')->assertInertia(fn (Assert $page) => $page
        ->where('entries.meta.total', 0)->where('entries.data', []));
});

it('combines filters', function () {
    $hit = auditEntry('2026-10-02 09:00', $this->encoder, 'visit.created', $this->visit);
    auditEntry('2026-10-02 10:00', $this->admin, 'visit.created', $this->visit);
    auditEntry('2026-10-02 11:00', $this->encoder, 'visit.updated', $this->visit);
    auditEntry('2026-08-02 11:00', $this->encoder, 'visit.created', $this->visit);

    expect(auditIds($this, "?user={$this->encoder->id}&action=visit.created&record={$this->visit->visit_no}&from=2026-10-01&to=2026-10-09"))->toBe([$hit->id]);
});

it('uses the defaults when a filter value is bad', function () {
    $august = auditEntry('2026-08-15 09:00', $this->admin, 'visit.created', $this->visit);
    $inRange = auditEntry('2026-10-02 09:00', $this->encoder, 'visit.created', $this->visit);

    $bad = [
        '?from=banana',
        '?to=2026-02-30',
        '?from=2026-13-01&to=31-12-2026',
        '?from[]=2026-10-01&to[x]=1',
        '?user=abc',
        '?user=999999',
        '?user[]=1',
        '?action=nope.nope',
        '?action[]=visit.created',
        '?record='.str_repeat('x', 200),
        '?record[]=x',
        '?page=abc',
    ];

    foreach ($bad as $query) {
        auditGet($this, $query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['from' => '2026-09-01', 'to' => '2026-10-09', 'user' => '', 'action' => '', 'record' => ''])
            ->where('entries.data.0.id', $inRange->id)
            ->where('entries.meta.total', 1));
    }

    // One bad value does not spoil the good ones.
    expect(auditIds($this, "?from=banana&user={$this->encoder->id}"))->toBe([$inRange->id])
        ->and(auditIds($this, '?from=2026-08-01&to=2026-08-31&user=abc&action=nope'))->toBe([$august->id]);
});

/** Insert $count entries by the Admin, one minute apart from 08:01 on 02-Oct-2026. */
function auditBulk(object $test, int $count): void
{
    DB::table('audit_logs')->insert(collect(range(1, $count))->map(fn (int $n): array => [
        'user_id' => $test->admin->id,
        'action' => 'visit.created',
        'subject_type' => 'visits',
        'subject_id' => $test->visit->id,
        'changes' => null,
        'ip_address' => '10.0.0.5',
        'created_at' => Carbon::parse('2026-10-02 08:00', 'Asia/Manila')->addMinutes($n),
    ])->all());
}

it('pages 10 entries at a time by default and sends the user to the last page when past the end', function () {
    auditBulk($this, 25);

    auditGet($this)->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 10)
        ->where('entries.meta', ['current_page' => 1, 'last_page' => 3, 'per_page' => 10, 'total' => 25, 'from' => 1, 'to' => 10, 'pages' => [1, 2, 3]]));

    auditGet($this, '?page=3')->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 5)
        ->where('entries.meta.current_page', 3)
        // The oldest of the 25 comes last.
        ->where('entries.data.4.created_at', Carbon::parse('2026-10-02 08:01', 'Asia/Manila')->toIso8601String()));

    auditGet($this, '?page=9')->assertRedirect('/audit-log?page=3');
});

it('shows 20 or 50 entries per page when asked, and 10 for any other value', function (string $query, int $rows, int $perPage) {
    auditBulk($this, 60);

    auditGet($this, $query)->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', $rows)
        ->where('entries.meta.per_page', $perPage));
})->with([
    '20' => ['?per_page=20', 20, 20],
    '50' => ['?per_page=50', 50, 50],
    '10' => ['?per_page=10', 10, 10],
    '999' => ['?per_page=999', 10, 10],
    'text' => ['?per_page=abc', 10, 10],
    'negative' => ['?per_page=-10', 10, 10],
    'a list' => ['?per_page[]=20', 10, 10],
]);

it('keeps the filters and rows per page when it goes to the last page', function () {
    auditBulk($this, 3);

    auditGet($this, '?action=visit.created&per_page=20&page=4')
        ->assertRedirect('/audit-log?action=visit.created&per_page=20&page=1');
});

it('does not run a query per row', function () {
    $add = fn (int $count) => DB::table('audit_logs')->insert(collect(range(1, $count))->map(fn (): array => [
        'user_id' => $this->encoder->id, 'action' => 'visit.updated', 'subject_type' => 'visits', 'subject_id' => $this->visit->id,
        'changes' => json_encode(['old' => ['branch_id' => $this->army->id], 'new' => ['branch_id' => $this->navy->id]]),
        'ip_address' => '10.0.0.5', 'created_at' => Carbon::parse('2026-10-02 08:00', 'Asia/Manila'),
    ])->all());

    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        auditGet($this)->assertOk();

        return count(DB::getQueryLog());
    };

    $add(3);
    $queries(); // The first page also fills the one-minute caches of the shared props (Step 7g).
    $few = $queries();
    $add(40);

    expect($queries())->toBe($few);
});

it('does not log that the audit log was viewed', function () {
    auditEntry('2026-10-02 09:00', $this->admin, 'visit.created', $this->visit);

    auditGet($this)->assertOk();
    auditGet($this, '?action=visit.created')->assertOk();

    expect(AuditLog::query()->count())->toBe(1);
});

it('is for Admin only', function (string $role, int $status) {
    $user = userWithRole($role, ['username' => "test.{$role}"]);

    $this->actingAs($user)->get('/audit-log')->assertStatus($status);
})->with([
    'admin' => ['admin', 200],
    'encoder' => ['encoder', 403],
    'viewer' => ['viewer', 403],
    'system admin' => ['system_admin', 403],
]);

it('sends a signed-out visitor to log in', function () {
    $this->get('/audit-log')->assertRedirect('/login');
});

it('has no way to create, edit or delete an audit entry', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'audit'));

    expect($routes)->toHaveCount(1)
        ->and($routes->first()->methods())->toBe(['GET', 'HEAD']);

    $entry = auditEntry('2026-10-02 09:00', $this->admin, 'visit.created', $this->visit);

    $this->actingAs($this->admin);
    // The app answers an unknown URL or method with its 404 page.
    foreach ([['post', '/audit-log'], ['put', "/audit-log/{$entry->id}"], ['patch', "/audit-log/{$entry->id}"], ['delete', "/audit-log/{$entry->id}"], ['delete', '/audit-log']] as [$method, $url]) {
        expect($this->{$method}($url)->getStatusCode())->toBeIn([404, 405]);
    }

    expect(fn () => $entry->update(['action' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->count())->toBe(1);
});

it('does not send whole models to the page', function () {
    auditEntry('2026-10-02 09:00', $this->encoder, 'visit.created', $this->visit);

    $entry = collect(auditProp($this, 'entries.data'))->first();

    expect(array_keys($entry))->toBe(['id', 'created_at', 'username', 'user_name', 'action', 'action_label', 'record', 'ip_address', 'details']);
});
