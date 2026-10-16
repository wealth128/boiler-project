<?php

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Models\AuditLog;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Services\BackupService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Backups screen (GET /backups) and its actions: Run backup now and the
 * USB copy / test restore marks. Admin only. mysqldump is never really run:
 * Process::fake() stands in for it. Rules: planning/architecture.md
 * section 4, planning/database-schema.md (backup_runs), planning/security.md.
 * Times are Asia/Manila.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));
    $this->folder = storage_path('framework/testing/backups-screen-'.uniqid());
    config([
        'census.backup.path' => $this->folder,
        'census.backup.mysqldump_path' => 'C:/fake/mysqldump.exe',
        'database.connections.mysql.password' => 'db-secret-pass',
    ]);

    $this->admin = userWithRole('admin', ['username' => 'admin.garcia', 'name' => 'Dr. Garcia']);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

/** Fake mysqldump: writes a small file and succeeds, or fails with $error. */
function fakeDump(bool $works = true, string $error = 'mysqldump: Got error: 1045 Access denied'): void
{
    Process::fake(function (PendingProcess $process) use ($works, $error) {
        $result = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--result-file='));

        if ($works && $result) {
            file_put_contents(substr($result, strlen('--result-file=')), "-- MySQL dump\nCREATE TABLE visits (...);\n");
        }

        return Process::result(output: '', errorOutput: $works ? '' : $error, exitCode: $works ? 0 : 2);
    });
}

/** A backup_runs row that started $hoursAgo hours ago. */
function backupRunAgo(float $hoursAgo, BackupStatus $status = BackupStatus::Success, ?string $message = null): BackupRun
{
    $at = now()->subMinutes((int) ($hoursAgo * 60));

    return BackupRun::create([
        'started_at' => $at,
        'finished_at' => $at->copy()->addMinute(),
        'status' => $status,
        'file_name' => $status === BackupStatus::Success ? 'patient_census_'.$at->format('Y-m-d_His').'.sql' : null,
        'size_bytes' => $status === BackupStatus::Success ? 5505024 : null,
        'message' => $message,
    ]);
}

/** Both manual steps done recently, so only the red warning can appear. */
function marksUpToDate(object $test): void
{
    foreach (BackupMarkType::cases() as $type) {
        BackupMark::create(['type' => $type, 'marked_by' => $test->admin->id, 'marked_at' => now()->subDay()]);
    }
}

function backupsProp(object $test, string $key, string $query = ''): mixed
{
    $value = null;

    $test->actingAs($test->admin)->get('/backups'.$query)->assertOk()
        ->assertInertia(function (Assert $page) use (&$value, $key) {
            $value = data_get($page->toArray()['props'], $key);
        });

    return $value;
}

function alertsFor(object $test, $user, string $uri): array
{
    $alerts = [];

    $test->actingAs($user)->get($uri)->assertOk()
        ->assertInertia(function (Assert $page) use (&$alerts) {
            $alerts = $page->toArray()['props']['backupAlerts'];
        });

    return $alerts;
}

// --- Status panel ----------------------------------------------------------

it('shows the last successful backup, schedule, retention and folder', function () {
    backupRunAgo(30);
    $latest = backupRunAgo(3);
    marksUpToDate($this);

    $this->actingAs($this->admin)->get('/backups')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('backups/index')
            ->where('status.last_success.file_name', $latest->file_name)
            ->where('status.last_success.size_mb', 5.25)
            ->where('status.last_success.finished_at', $latest->finished_at->toIso8601String())
            ->where('status.next_run', Carbon::parse('2026-10-10 02:00', 'Asia/Manila')->toIso8601String())
            ->where('status.keep_days', 30)
            ->where('status.folder', $this->folder)
            ->where('status.problem', null));
});

it('says the next run is today when it is before 02:00', function () {
    $this->travelTo(Carbon::parse('2026-10-09 01:10', 'Asia/Manila'));

    expect(backupsProp($this, 'status.next_run'))
        ->toBe(Carbon::parse('2026-10-09 02:00', 'Asia/Manila')->toIso8601String());
});

it('shows "None yet" data and a red warning when no backup has ever run', function () {
    marksUpToDate($this);

    $this->actingAs($this->admin)->get('/backups')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status.last_success', null)
            ->where('status.problem', 'No successful backup has been recorded yet. Tell IT.')
            ->where('runs.meta.total', 0));
});

// --- Red warning -----------------------------------------------------------

it('warns when the last run failed', function () {
    backupRunAgo(30);
    backupRunAgo(2, BackupStatus::Failed, "mysqldump: Got error: 1045 Access denied\nsecond line");
    marksUpToDate($this);

    expect(backupsProp($this, 'status.problem'))
        ->toBe('The last backup failed on 09-Oct-2026 12:00: mysqldump: Got error: 1045 Access denied. Tell IT.');
});

it('warns when there is no success in the last 26 hours, and not before', function () {
    marksUpToDate($this);
    backupRunAgo(27);

    expect(backupsProp($this, 'status.problem'))->toBe('No successful backup since 08-Oct-2026 11:01. Tell IT.');

    // 25 hours ago is still fine.
    BackupRun::query()->delete();
    backupRunAgo(25);

    expect(backupsProp($this, 'status.problem'))->toBeNull();
});

it('shows no warning when the latest run succeeded within 26 hours', function () {
    backupRunAgo(30, BackupStatus::Failed, 'old failure');
    backupRunAgo(4);
    marksUpToDate($this);

    expect(backupsProp($this, 'status.problem'))->toBeNull();
    expect(alertsFor($this, $this->admin, '/encode'))->toBe([]);
});

it('keeps the database password and login out of the page', function () {
    backupRunAgo(2, BackupStatus::Failed, "Access denied for user 'census'@'localhost' using password db-secret-pass");
    marksUpToDate($this);

    $response = $this->actingAs($this->admin)->get('/backups')->assertOk();
    $html = $response->getContent();

    expect($html)->not->toContain('db-secret-pass')
        ->and($html)->not->toContain('census&#039;@&#039;localhost')
        ->and($html)->not->toContain("'census'@'localhost'")
        ->and($html)->toContain('***');
});

// --- Banner on every Admin page ------------------------------------------

it('shows the red banner with the short text on other pages for Admin only', function () {
    backupRunAgo(2, BackupStatus::Failed, 'disk is full');
    marksUpToDate($this);

    expect(alertsFor($this, $this->admin, '/records/visits'))->toBe([
        ['level' => 'error', 'message' => 'The last backup failed. Tell IT.'],
    ]);

    expect(alertsFor($this, userWithRole('encoder'), '/encode'))->toBe([])
        ->and(alertsFor($this, userWithRole('viewer'), '/reports'))->toBe([])
        ->and(alertsFor($this, userWithRole('system_admin'), '/users'))->toBe([]);
});

it('shows the banner for a missing backup to Admin only', function () {
    marksUpToDate($this);

    expect(alertsFor($this, $this->admin, '/trash')[0])->toBe(['level' => 'error', 'message' => 'No successful backup has been recorded yet. Tell IT.'])
        ->and(alertsFor($this, userWithRole('encoder'), '/encode'))->toBe([]);
});

// --- Run backup now --------------------------------------------------------

it('runs a backup now, records it and writes an audit entry', function () {
    fakeDump();

    $this->actingAs($this->admin)->from('/backups')->post('/backups/run')->assertRedirect('/backups');

    $run = BackupRun::sole();
    expect($run->status)->toBe(BackupStatus::Success)
        ->and($run->file_name)->toStartWith('patient_census_2026-10-09_')
        ->and(is_file($this->folder.DIRECTORY_SEPARATOR.$run->file_name))->toBeTrue();

    $entry = AuditLog::where('action', 'backup.run_manual')->sole();
    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->subject_type)->toBe('backup_runs')
        ->and($entry->subject_id)->toBe($run->id)
        ->and($entry->changes['result'])->toBe('success')
        ->and($entry->changes['label'])->toBe($run->file_name);
});

it('records a failed manual backup with a short error and an audit entry', function () {
    fakeDump(false, "mysqldump: Got error: 1045 Access denied\nmore detail");

    $this->actingAs($this->admin)->from('/backups')->post('/backups/run')->assertRedirect('/backups');

    $run = BackupRun::sole();
    expect($run->status)->toBe(BackupStatus::Failed);

    $entry = AuditLog::where('action', 'backup.run_manual')->sole();
    expect($entry->changes['result'])->toBe('failed')
        ->and($entry->changes['error'])->toBe('mysqldump: Got error: 1045 Access denied');

    // The failure now shows as the red warning.
    expect(backupsProp($this, 'status.problem'))->toContain('The last backup failed on 09-Oct-2026 14:00');
});

it('refuses a second backup while one is running', function () {
    fakeDump();
    $lock = Cache::lock(BackupService::LOCK, 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs($this->admin)->from('/backups')->post('/backups/run')->assertRedirect('/backups');

    expect(BackupRun::count())->toBe(0)
        ->and(AuditLog::where('action', 'backup.run_manual')->count())->toBe(0);
    Process::assertNothingRan();

    // After the first one finishes, the next can run.
    $lock->release();
    $this->actingAs($this->admin)->from('/backups')->post('/backups/run');

    expect(BackupRun::count())->toBe(1);
});

it('frees the lock after a backup, even a failed one', function () {
    fakeDump(false);

    $this->actingAs($this->admin)->post('/backups/run');
    $this->actingAs($this->admin)->post('/backups/run');

    expect(BackupRun::count())->toBe(2);
});

// --- History ---------------------------------------------------------------

it('lists every run newest first with the error only on failed runs', function () {
    marksUpToDate($this);
    $old = backupRunAgo(50, BackupStatus::Success, 'Removed 1 backup(s) older than 30 days.');
    $failed = backupRunAgo(20, BackupStatus::Failed, '<script>alert(1)</script> disk full');
    $new = backupRunAgo(2);

    $runs = backupsProp($this, 'runs.data');

    expect(collect($runs)->pluck('id')->all())->toBe([$new->id, $failed->id, $old->id])
        ->and($runs[1]['status'])->toBe('failed')
        ->and($runs[1]['error'])->toBe('<script>alert(1)</script> disk full') // plain text: React escapes it
        ->and($runs[0]['error'])->toBeNull()
        ->and($runs[2]['error'])->toBeNull()
        ->and($runs[0]['size_mb'])->toBe(5.25)
        ->and($runs[1]['size_mb'])->toBeNull();
});

it('pages the history with 10 / 20 / 50 rows and keeps it in the URL', function () {
    marksUpToDate($this);

    foreach (range(1, 25) as $i) {
        backupRunAgo($i / 10);
    }

    expect(backupsProp($this, 'runs.meta'))->toMatchArray(['per_page' => 10, 'total' => 25, 'last_page' => 3])
        ->and(backupsProp($this, 'runs.data', '?page=3'))->toHaveCount(5)
        ->and(backupsProp($this, 'runs.data', '?per_page=20'))->toHaveCount(20)
        ->and(backupsProp($this, 'runs.meta.per_page', '?per_page=50'))->toBe(50)
        ->and(backupsProp($this, 'runs.meta.per_page', '?per_page=999'))->toBe(10);

    $this->actingAs($this->admin)->get('/backups?page=9&per_page=10')->assertRedirect('/backups?page=3&per_page=10');
});

// --- No download, no restore -----------------------------------------------

it('has no route that serves or restores a backup file', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'backup'))
        ->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())
        ->sort()->values()->all();

    expect($routes)->toBe([
        'GET backups',
        'POST backups/marks/{type}',
        'POST backups/run',
    ]);

    fakeDump();
    $this->actingAs($this->admin)->post('/backups/run');
    $file = BackupRun::sole()->file_name;

    $this->actingAs($this->admin)->get("/backups/{$file}")->assertNotFound();
    $this->actingAs($this->admin)->get("/backups/download/{$file}")->assertNotFound();
    // The framework's own storage route (closed in Phase 8) refuses unsigned requests too.
    expect($this->actingAs($this->admin)->get("/storage/backups/{$file}")->status())->toBeIn([403, 404]);
    expect(public_path($file))->not->toBeFile();
});

// --- Roles -----------------------------------------------------------------

it('gives every role except Admin a 403 on the backup routes', function (string $role) {
    fakeDump();
    $user = userWithRole($role);

    $this->actingAs($user)->get('/backups')->assertForbidden();
    $this->actingAs($user)->post('/backups/run')->assertForbidden();
    $this->actingAs($user)->post('/backups/marks/usb-copy')->assertForbidden();
    $this->actingAs($user)->post('/backups/marks/test-restore')->assertForbidden();

    expect(BackupRun::count())->toBe(0)->and(BackupMark::count())->toBe(0);
    Process::assertNothingRan();
})->with(['encoder', 'viewer', 'system_admin']);

it('sends a signed-out visitor to the login page', function () {
    $this->get('/backups')->assertRedirect(route('login'));
    $this->post('/backups/run')->assertRedirect(route('login'));
});

// --- USB copy and test restore marks ---------------------------------------

it('shows "never" and the yellow warnings before anything is marked', function () {
    backupRunAgo(2);

    $marks = backupsProp($this, 'marks');

    expect($marks)->toBe([
        ['type' => 'usb-copy', 'label' => 'USB copy', 'last_at' => null, 'by' => null, 'overdue' => true],
        ['type' => 'test-restore', 'label' => 'Test restore', 'last_at' => null, 'by' => null, 'overdue' => true],
    ]);

    expect(alertsFor($this, $this->admin, '/encode'))->toBe([
        ['level' => 'warning', 'message' => 'The weekly USB copy is overdue (last: never). Copy the latest backup to the USB drive.'],
        ['level' => 'warning', 'message' => 'The monthly test restore is overdue (last: never). Ask IT to test a restore.'],
    ]);
});

it('marks a USB copy and a test restore, with who and when, and writes audit entries', function () {
    backupRunAgo(2);

    $this->actingAs($this->admin)->from('/backups')->post('/backups/marks/usb-copy')->assertRedirect('/backups');
    $this->actingAs($this->admin)->from('/backups')->post('/backups/marks/test-restore')->assertRedirect('/backups');

    expect(BackupMark::count())->toBe(2);

    $usb = BackupMark::where('type', BackupMarkType::UsbCopy)->sole();
    expect($usb->marked_by)->toBe($this->admin->id)
        ->and($usb->marked_at->equalTo(now()))->toBeTrue();

    $entry = AuditLog::where('action', 'backup.usb_copy_marked')->sole();
    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->subject_type)->toBe('backup_marks')
        ->and($entry->subject_id)->toBe($usb->id)
        ->and($entry->changes['label'])->toBe('USB copy');
    expect(AuditLog::where('action', 'backup.test_restore_marked')->count())->toBe(1);

    expect(backupsProp($this, 'marks.0'))->toMatchArray(['by' => 'Dr. Garcia', 'overdue' => false])
        ->and(alertsFor($this, $this->admin, '/encode'))->toBe([]);
});

it('turns the USB copy warning on after 8 days and the test restore warning after 35', function () {
    backupRunAgo(2);
    BackupMark::create(['type' => BackupMarkType::UsbCopy, 'marked_by' => $this->admin->id, 'marked_at' => now()->subDays(8)->addMinute()]);
    BackupMark::create(['type' => BackupMarkType::TestRestore, 'marked_by' => $this->admin->id, 'marked_at' => now()->subDays(35)->addMinute()]);

    expect(alertsFor($this, $this->admin, '/encode'))->toBe([]);

    $this->travelTo(now()->addMinutes(2));
    backupRunAgo(1);

    expect(alertsFor($this, $this->admin, '/encode'))->toBe([
        ['level' => 'warning', 'message' => 'The weekly USB copy is overdue (last: 01-Oct-2026). Copy the latest backup to the USB drive.'],
        ['level' => 'warning', 'message' => 'The monthly test restore is overdue (last: 04-Sep-2026). Ask IT to test a restore.'],
    ]);
});

it('shows the red and yellow warnings together, red first', function () {
    backupRunAgo(2, BackupStatus::Failed, 'disk full');

    $alerts = alertsFor($this, $this->admin, '/encode');

    expect(collect($alerts)->pluck('level')->all())->toBe(['error', 'warning', 'warning']);
});

it('never shows the yellow warnings to the other roles', function () {
    backupRunAgo(2);

    expect(alertsFor($this, userWithRole('encoder'), '/encode'))->toBe([])
        ->and(alertsFor($this, userWithRole('viewer'), '/reports'))->toBe([])
        ->and(alertsFor($this, userWithRole('system_admin'), '/users'))->toBe([]);
});

it('blocks nothing while the reminders are overdue', function () {
    // No backup, no USB copy, no test restore: every warning is on.
    expect(alertsFor($this, $this->admin, '/encode'))->toHaveCount(3);

    foreach (['/encode', '/records/visits', '/records/patients', '/reports', '/settings/lists', '/audit-log', '/trash', '/users', '/backups'] as $uri) {
        $this->actingAs($this->admin)->get($uri)->assertOk();
    }

    $this->actingAs(userWithRole('encoder'))->get('/encode')->assertOk();
});

it('rejects an unknown mark type', function () {
    $this->actingAs($this->admin)->post('/backups/marks/restore-everything')->assertNotFound();

    expect(BackupMark::count())->toBe(0);
});

it('keeps the marks when the person who made them is later looked up', function () {
    BackupMark::create(['type' => BackupMarkType::UsbCopy, 'marked_by' => $this->admin->id, 'marked_at' => now()->subDays(2)]);

    expect(backupsProp($this, 'marks.0'))->toMatchArray(['last_at' => now()->subDays(2)->toIso8601String(), 'by' => 'Dr. Garcia']);
});
