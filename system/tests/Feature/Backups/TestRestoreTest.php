<?php

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Models\AuditLog;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Models\Patient;
use App\Models\TestRestoreRun;
use App\Models\User;
use App\Models\Visit;
use App\Services\BackupMonitor;
use App\Services\BackupService;
use App\Services\Setup\MysqlAdmin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeMysqlAdmin;
use Tests\Support\SetupLog;

require_once __DIR__.'/../../Support/SetupFakes.php';

/*
 * The automatic test restore (php artisan backup:test-restore) and the "Run
 * test restore now" button. The latest backup is loaded into the scratch
 * database patient_census_restore_test and its counts are compared with the
 * counts saved when the backup was made. MySQL is replaced by a fake: no real
 * database is created or dropped here. Rules: planning/architecture.md
 * section 4, planning/security.md.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 03:00', 'Asia/Manila'));
    $this->folder = storage_path('framework/testing/test-restore-'.uniqid());
    File::ensureDirectoryExists($this->folder);
    config([
        'census.backup.path' => $this->folder,
        'census.restore_test.password' => 'scratch-secret-pw',
        'database.connections.mysql.password' => 'db-secret-pass',
    ]);

    SetupLog::$calls = [];
    $this->mysql = new FakeMysqlAdmin;
    $this->mysql->restoredCounts = ['patients' => 12, 'visits' => 40, 'users' => 4, 'closed_months' => 2];
    app()->instance(MysqlAdmin::class, $this->mysql);

    $this->admin = userWithRole('admin', ['name' => 'Dr. Garcia']);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

/** A good backup on disk (complete dump, valid zip) and its run, with the counts it saw. */
function goodBackup(object $test, ?array $counts = ['patients' => 12, 'visits' => 40, 'users' => 4, 'closed_months' => 2]): BackupRun
{
    $at = now()->subHour();
    $stamp = $at->format('Y-m-d_His');
    $zipName = "patient_census_files_{$stamp}.zip";

    file_put_contents($test->folder."/patient_census_{$stamp}.sql", "-- MySQL dump\nCREATE TABLE visits (id int);\n-- Dump completed on 2026-11-01  1:00:00\n");

    $zip = new ZipArchive;
    $zip->open($test->folder.'/'.$zipName, ZipArchive::CREATE);
    $zip->addFromString('hospital/logo.png', 'png');
    $zip->addFromString('backup-info.txt', 'info');
    $zip->close();

    return BackupRun::create([
        'started_at' => $at,
        'finished_at' => $at->copy()->addMinute(),
        'status' => BackupStatus::Success,
        'file_name' => "patient_census_{$stamp}.sql",
        'size_bytes' => 100,
        'zip_name' => $zipName,
        'zip_size_bytes' => 100,
        'patients_count' => $counts['patients'] ?? null,
        'visits_count' => $counts['visits'] ?? null,
        'users_count' => $counts['users'] ?? null,
        'closed_months_count' => $counts['closed_months'] ?? null,
    ]);
}

/** @return list<string> Temporary folders a test restore leaves behind. */
function leftoverTempFolders(): array
{
    return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'census-restore-test-*') ?: [];
}

function alertMessages(object $test): array
{
    $alerts = [];

    $test->actingAs($test->admin)->get('/encode')->assertOk()
        ->assertInertia(function ($page) use (&$alerts) {
            $alerts = $page->toArray()['props']['backupAlerts'];
        });

    return $alerts;
}

// --- The run itself -------------------------------------------------------

it('loads the latest backup into the scratch database, compares the counts and cleans up', function () {
    $backup = goodBackup($this);
    $before = leftoverTempFolders();

    $this->artisan('backup:test-restore')->expectsOutputToContain('Test restore passed')->assertSuccessful();

    expect(SetupLog::names())->toBe(['scratch-reset', 'scratch-load', 'scratch-counts', 'scratch-drop'])
        ->and(SetupLog::detail('scratch-load')['file'])->toBe($backup->file_name)
        ->and(leftoverTempFolders())->toBe($before);

    $run = TestRestoreRun::sole();
    expect($run->status)->toBe(BackupStatus::Success)
        ->and($run->trigger)->toBe('automatic')
        ->and($run->backup_run_id)->toBe($backup->id)
        ->and($run->started_by)->toBeNull()
        ->and($run->message)->toBeNull();
});

it('only ever uses the scratch database name', function () {
    goodBackup($this);

    $this->artisan('backup:test-restore')->assertSuccessful();

    $names = collect(SetupLog::$calls)->map(fn ($call) => $call[1]['database'] ?? null)->filter()->unique()->values()->all();

    expect($names)->toBe(['patient_census_restore_test'])
        ->and(MysqlAdmin::SCRATCH_DATABASE)->toBe('patient_census_restore_test')
        ->and(MysqlAdmin::SCRATCH_USER)->toBe('census_restore_test');
});

it('refuses any other database name, in the command and in MysqlAdmin itself', function () {
    goodBackup($this);

    $this->artisan('backup:test-restore', ['--database' => 'patient_census'])
        ->expectsOutputToContain('only uses the database patient_census_restore_test')
        ->assertFailed();

    expect(SetupLog::names())->toBe([])->and(TestRestoreRun::count())->toBe(0);

    // The real class stops before it opens any connection.
    $real = new MysqlAdmin;

    foreach (['patient_census', 'patient_census_test', 'mysql', ''] as $other) {
        expect(fn () => $real->resetScratchDatabase($other))->toThrow(RuntimeException::class, 'may only use')
            ->and(fn () => $real->dropScratchDatabase($other))->toThrow(RuntimeException::class, 'may only use')
            ->and(fn () => $real->loadScratchDump('x.sql', $other))->toThrow(RuntimeException::class, 'may only use')
            ->and(fn () => $real->scratchCounts($other))->toThrow(RuntimeException::class, 'may only use');
    }
});

it('never touches the live database: no dump is loaded through the live connection', function () {
    goodBackup($this);
    Process::fake();

    $this->artisan('backup:test-restore')->assertSuccessful();

    // No mysqldump and no root login: the test only talks to the scratch database.
    Process::assertNothingRan();
    expect(SetupLog::names())->not->toContain('recreate')->not->toContain('import')->not->toContain('connect');
});

it('fails when the restored counts differ from the backup, drops the scratch database and records no mark', function () {
    goodBackup($this);
    $this->mysql->restoredCounts = ['patients' => 12, 'visits' => 39, 'users' => 4, 'closed_months' => 1];

    $this->artisan('backup:test-restore')->assertFailed();

    expect(SetupLog::names())->toBe(['scratch-reset', 'scratch-load', 'scratch-counts', 'scratch-drop']);

    $run = TestRestoreRun::sole();
    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->message)->toContain('visits: backup 40, restored 39')
        ->and($run->message)->toContain('closed months: backup 2, restored 1')
        ->and($run->message)->not->toContain('patients:')
        ->and(BackupMark::count())->toBe(0);
});

it('drops the scratch database even when loading the dump fails', function () {
    goodBackup($this);
    $this->mysql->loadFails = true;

    $this->artisan('backup:test-restore')->assertFailed();

    expect(SetupLog::names())->toBe(['scratch-reset', 'scratch-load', 'scratch-drop'])
        ->and(TestRestoreRun::sole()->status)->toBe(BackupStatus::Failed)
        ->and(BackupMark::count())->toBe(0);
});

it('stops before loading anything when the picture zip is bad', function () {
    $backup = goodBackup($this);
    // Cut-off zip: opens as a zip but is damaged.
    file_put_contents($this->folder.'/'.$backup->zip_name, 'not a zip');
    $before = leftoverTempFolders();

    $this->artisan('backup:test-restore')->assertFailed();

    // The zip is checked before anything is loaded, so nothing was created.
    expect(SetupLog::names())->toBe([])
        ->and(TestRestoreRun::sole()->message)->toBe('The picture zip of this backup cannot be opened. Choose another backup.')
        ->and(leftoverTempFolders())->toBe($before);
});

it('fails with a plain message when the dump is cut off, the files are missing or there is no backup', function () {
    $this->artisan('backup:test-restore')->assertFailed();
    expect(TestRestoreRun::latest('id')->first()->message)->toBe('There is no successful backup to test.');

    $backup = goodBackup($this);
    file_put_contents($this->folder.'/'.$backup->file_name, "-- MySQL dump\nCREATE TABLE visits (id int);\n");
    $this->artisan('backup:test-restore')->assertFailed();
    expect(TestRestoreRun::latest('id')->first()->message)->toBe('This backup file is incomplete. Choose another one.');

    unlink($this->folder.'/'.$backup->file_name);
    $this->artisan('backup:test-restore')->assertFailed();
    expect(TestRestoreRun::latest('id')->first()->message)->toContain('is missing from the backup folder');

    expect(SetupLog::names())->toBe([]);
});

it('fails when the backup has no saved counts (made by an older version)', function () {
    goodBackup($this, counts: null);

    $this->artisan('backup:test-restore')->assertFailed();

    expect(TestRestoreRun::sole()->message)->toContain('no saved record counts')
        ->and(SetupLog::names())->toBe([]);
});

it('fails when the test restore account is not set up', function () {
    goodBackup($this);
    $this->mysql->scratchReady = false;

    $this->artisan('backup:test-restore')->assertFailed();

    expect(TestRestoreRun::sole()->message)->toContain('census:install --repair');
});

it('keeps passwords out of the stored error', function () {
    goodBackup($this);
    $this->mysql = new class extends FakeMysqlAdmin
    {
        public function loadScratchDump(string $dumpPath, string $database): void
        {
            throw new RuntimeException("Access denied for user 'census_restore_test'@'localhost' (using password: scratch-secret-pw db-secret-pass)");
        }
    };
    app()->instance(MysqlAdmin::class, $this->mysql);

    $this->artisan('backup:test-restore')->assertFailed();

    $message = TestRestoreRun::sole()->message;
    expect($message)->not->toContain('scratch-secret-pw')->not->toContain('db-secret-pass')->not->toContain("'census_restore_test'@'localhost'");
});

// --- Result: mark, audit, red bar ---------------------------------------------

it('records the "Test restore" mark by "System (automatic)" and an audit entry on success', function () {
    $backup = goodBackup($this);

    $this->artisan('backup:test-restore')->assertSuccessful();

    $mark = BackupMark::sole();
    expect($mark->type)->toBe(BackupMarkType::TestRestore)
        ->and($mark->marked_by)->toBeNull()
        ->and($mark->marked_at->equalTo(now()))->toBeTrue();

    $entry = AuditLog::where('action', 'backup.test_restore')->sole();
    expect($entry->user_id)->toBeNull()
        ->and($entry->subject_type)->toBe('test_restore_runs')
        ->and($entry->changes['trigger'])->toBe('automatic')
        ->and($entry->changes['result'])->toBe('success')
        ->and($entry->changes['backup'])->toBe($backup->file_name);

    $this->actingAs($this->admin)->get('/backups')->assertInertia(fn ($page) => $page
        ->where('marks.1.type', 'test-restore')
        ->where('marks.1.by', 'System (automatic)')
        ->where('marks.1.overdue', false)
        ->where('testRestore.result', 'success')
        ->where('testRestore.trigger', 'automatic'));
});

it('clears the 35-day yellow reminder by itself', function () {
    goodBackup($this);
    BackupMark::create(['type' => BackupMarkType::UsbCopy, 'marked_by' => $this->admin->id, 'marked_at' => now()->subDay()]);

    expect(collect(alertMessages($this))->pluck('message')->join(' '))->toContain('monthly test restore is overdue');

    $this->artisan('backup:test-restore')->assertSuccessful();

    expect(alertMessages($this))->toBe([]);
});

it('shows a red bar for Admin on every page and on the Backups page after a failure', function () {
    goodBackup($this);
    $this->mysql->restoredCounts = ['patients' => 0, 'visits' => 0, 'users' => 0, 'closed_months' => 0];
    BackupMark::create(['type' => BackupMarkType::UsbCopy, 'marked_by' => $this->admin->id, 'marked_at' => now()->subDay()]);
    BackupMark::create(['type' => BackupMarkType::TestRestore, 'marked_by' => $this->admin->id, 'marked_at' => now()->subDay()]);

    $this->artisan('backup:test-restore')->assertFailed();

    expect(alertMessages($this))->toBe([
        ['level' => 'error', 'message' => 'The automatic test restore failed. Tell IT.'],
    ]);

    $this->actingAs($this->admin)->get('/backups')->assertInertia(fn ($page) => $page
        ->where('status.test_restore_problem', fn ($text) => str_starts_with($text, 'The automatic test restore failed on 01-Nov-2026 03:00: The restored copy does not match the backup (patients: backup 12, restored 0;')
            && str_ends_with($text, '.. Tell IT / see the operations guide.'))
        ->where('testRestore.result', 'failed'));

    // The other roles never see it.
    foreach (['encoder', 'viewer'] as $role) {
        $this->actingAs(userWithRole($role))->get($role === 'viewer' ? '/reports' : '/encode')
            ->assertInertia(fn ($page) => $page->where('backupAlerts', []));
    }
});

it('clears the red bar when a later test restore passes', function () {
    goodBackup($this);
    $this->mysql->restoredCounts = ['patients' => 1, 'visits' => 1, 'users' => 1, 'closed_months' => 1];
    $this->artisan('backup:test-restore')->assertFailed();
    expect(app(BackupMonitor::class)->testRestoreProblem())->not->toBeNull();

    $this->mysql->restoredCounts = ['patients' => 12, 'visits' => 40, 'users' => 4, 'closed_months' => 2];
    $this->travelTo(now()->addMinute());
    $this->artisan('backup:test-restore')->assertSuccessful();

    expect(app(BackupMonitor::class)->testRestoreProblem())->toBeNull()
        ->and(TestRestoreRun::count())->toBe(2);
});

it('says "The test restore" (not automatic) for a manual failure', function () {
    goodBackup($this);
    $this->mysql->loadFails = true;

    $this->actingAs($this->admin)->post('/backups/test-restore');

    expect(app(BackupMonitor::class)->testRestoreProblem()['short'])->toBe('The test restore failed. Tell IT.');
});

// --- Locking and the schedule ----------------------------------------------------

it('records a failure when a backup still holds the lock at the scheduled time', function () {
    goodBackup($this);
    $lock = Cache::lock(BackupService::LOCK, 60);
    $lock->get();

    $this->artisan('backup:test-restore')->assertFailed();

    expect(SetupLog::names())->toBe([])
        ->and(TestRestoreRun::sole()->message)->toContain('A backup was still running');
});

it('is scheduled on the 1st of every month at 03:00', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'backup:test-restore'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 1 * *')
        ->and(BackupMonitor::TEST_RESTORE_AT)->toBe('03:00');
});

// --- Run test restore now ----------------------------------------------------------

it('runs a test restore for Admin, records who, the mark and an audit entry', function () {
    $backup = goodBackup($this);

    $this->actingAs($this->admin)->from('/backups')->post('/backups/test-restore')->assertRedirect('/backups');

    $run = TestRestoreRun::sole();
    expect($run->trigger)->toBe('manual')
        ->and($run->started_by)->toBe($this->admin->id)
        ->and($run->status)->toBe(BackupStatus::Success);

    expect(BackupMark::sole()->marked_by)->toBe($this->admin->id);

    $entry = AuditLog::where('action', 'backup.test_restore')->sole();
    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->changes['trigger'])->toBe('manual')
        ->and($entry->changes['result'])->toBe('success')
        ->and($entry->changes['backup'])->toBe($backup->file_name);
});

it('audits a failed manual test restore with a short error', function () {
    goodBackup($this);
    $this->mysql->loadFails = true;

    $this->actingAs($this->admin)->post('/backups/test-restore');

    $entry = AuditLog::where('action', 'backup.test_restore')->sole();
    expect($entry->changes['result'])->toBe('failed')
        ->and($entry->changes['error'])->toBe('ERROR 1064 (42000) at line 12: You have an error in your SQL syntax');
});

it('refuses a second test restore while a backup or another test runs, and runs nothing', function () {
    goodBackup($this);
    $lock = Cache::lock(BackupService::LOCK, 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs($this->admin)->from('/backups')->post('/backups/test-restore')->assertRedirect('/backups');

    expect(TestRestoreRun::count())->toBe(0)
        ->and(SetupLog::names())->toBe([])
        ->and(AuditLog::where('action', 'backup.test_restore')->count())->toBe(0);

    $lock->release();
    $this->actingAs($this->admin)->post('/backups/test-restore');

    expect(TestRestoreRun::count())->toBe(1);

    // The lock is free again afterwards, even after a failure.
    $this->mysql->loadFails = true;
    $this->actingAs($this->admin)->post('/backups/test-restore');
    $this->actingAs($this->admin)->post('/backups/test-restore');

    expect(TestRestoreRun::count())->toBe(3);
});

it('gives encoder, viewer and system admin a 403 and runs nothing', function (string $role) {
    goodBackup($this);

    $this->actingAs(userWithRole($role))->post('/backups/test-restore')->assertForbidden();

    expect(TestRestoreRun::count())->toBe(0)->and(SetupLog::names())->toBe([]);
})->with(['encoder', 'viewer', 'system_admin']);

it('sends a signed-out visitor to the login page', function () {
    $this->post('/backups/test-restore')->assertRedirect(route('login'));
});

// --- The backup remembers its counts ---------------------------------------------------

it('saves the patient, visit, user and closed-month counts on every successful backup', function () {
    Process::fake(function ($process) {
        $result = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--result-file='));
        file_put_contents(substr($result, strlen('--result-file=')), "-- MySQL dump\n-- Dump completed on x\n");

        return Process::result();
    });

    Patient::factory()->count(3)->create();
    $before = ['users' => User::count(), 'patients' => Patient::count(), 'visits' => Visit::count()];

    $this->actingAs($this->admin)->post('/backups/run');

    $run = BackupRun::sole();
    expect($run->status)->toBe(BackupStatus::Success)
        ->and($run->patients_count)->toBe($before['patients'])
        ->and($run->visits_count)->toBe($before['visits'])
        ->and($run->users_count)->toBe($before['users'])
        ->and($run->closed_months_count)->toBe(0);
});

it('accepts any restored count between the lowest and highest the backup saw', function () {
    $backup = goodBackup($this);
    // A visit was encoded while mysqldump ran: 40 before, 42 after.
    $backup->update(['visits_count' => 40, 'visits_count_max' => 42, 'patients_count_max' => 13]);

    foreach ([40, 41, 42] as $visits) {
        $this->mysql->restoredCounts = ['patients' => 13, 'visits' => $visits, 'users' => 4, 'closed_months' => 2];
        $this->artisan('backup:test-restore')->assertSuccessful();
    }

    expect(TestRestoreRun::where('status', 'success')->count())->toBe(3);

    foreach ([39, 43] as $visits) {
        $this->mysql->restoredCounts = ['patients' => 12, 'visits' => $visits, 'users' => 4, 'closed_months' => 2];
        $this->artisan('backup:test-restore')->assertFailed();

        expect(TestRestoreRun::latest('id')->first()->message)->toContain("visits: backup 40 to 42, restored {$visits}");
    }
});

it('saves the lowest and highest count when a patient is encoded while the dump runs', function () {
    Patient::factory()->count(2)->create();
    $patients = Patient::count();

    Process::fake(function ($process) {
        $result = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--result-file='));
        file_put_contents(substr($result, strlen('--result-file=')), "-- MySQL dump\n-- Dump completed on x\n");

        // Someone encodes a patient while the main mysqldump runs (the second run only dumps table structures).
        if (! in_array('--no-data', (array) $process->command, true)) {
            Patient::factory()->create();
        }

        return Process::result();
    });

    $this->actingAs($this->admin)->post('/backups/run');

    $run = BackupRun::sole()->fresh();
    expect($run->patients_count)->toBe($patients)
        ->and($run->patients_count_max)->toBe($patients + 1)
        // Nothing else moved: low and high are the same.
        ->and($run->users_count)->toBe($run->users_count_max)
        ->and($run->visits_count)->toBe($run->visits_count_max)
        ->and($run->closed_months_count)->toBe($run->closed_months_count_max);

    // Put real files where the test restore looks for them.
    file_put_contents($this->folder.'/'.$run->file_name, "-- MySQL dump\n-- Dump completed on x\n");
    $zip = new ZipArchive;
    $zip->open($this->folder.'/'.$run->zip_name, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('backup-info.txt', 'i');
    $zip->close();

    // A restored copy with either number passes; one outside the range does not.
    foreach ([$patients, $patients + 1] as $found) {
        $this->mysql->restoredCounts = ['patients' => $found, 'visits' => $run->visits_count, 'users' => $run->users_count, 'closed_months' => $run->closed_months_count];
        $this->artisan('backup:test-restore')->assertSuccessful();
    }

    $this->mysql->restoredCounts = ['patients' => $patients + 2, 'visits' => $run->visits_count, 'users' => $run->users_count, 'closed_months' => $run->closed_months_count];
    $this->artisan('backup:test-restore')->assertFailed();
});

it('casts the count columns to integers', function () {
    $run = goodBackup($this)->fresh();

    expect($run->patients_count)->toBeInt()->and($run->visits_count)->toBeInt()
        ->and($run->users_count)->toBeInt()->and($run->closed_months_count)->toBeInt()
        ->and($run->patients_count_max)->toBeNull();

    $run->update(['patients_count_max' => '13']);

    expect($run->fresh()->patients_count_max)->toBeInt()->toBe(13);
});

// --- Lock length and throttle -----------------------------------------------------------------

it('holds the lock longer than the longest program run, for backup, test restore and USB copy', function () {
    // A run is the program (mysqldump or mysql, at most PROCESS_TIMEOUT) plus counting,
    // zipping or unzipping and a safety margin of at least half an hour.
    expect(BackupService::LOCK_SECONDS)->toBeGreaterThanOrEqual(BackupService::PROCESS_TIMEOUT + 1800)
        ->and(BackupService::PROCESS_TIMEOUT)->toBe(3600);

    $source = collect(['BackupService', 'TestRestoreService', 'UsbCopyService'])
        ->map(fn ($class) => file_get_contents(app_path("Services/{$class}.php")))->join("\n");

    expect(substr_count($source, 'BackupService::LOCK, BackupService::LOCK_SECONDS') + substr_count($source, 'self::LOCK, self::LOCK_SECONDS'))->toBe(3)
        ->and($source)->not->toContain('3700');
});

it('allows 6 backup actions a minute per user, shared by the three buttons, then answers 429', function () {
    $this->mysql->scratchReady = false; // cheap: the test restore stops at once

    foreach (range(1, 6) as $i) {
        $this->actingAs($this->admin)->post('/backups/test-restore')->assertRedirect();
    }

    $this->actingAs($this->admin)->post('/backups/test-restore')->assertStatus(429);
    // The same bucket: the other two buttons are blocked too.
    $this->actingAs($this->admin)->post('/backups/run')->assertStatus(429);
    $this->actingAs($this->admin)->post('/backups/usb-copy', ['drive' => 'E:'])->assertStatus(429);

    // A minute later it works again.
    $this->travelTo(now()->addSeconds(61));
    $this->actingAs($this->admin)->post('/backups/test-restore')->assertRedirect();
});

it('shows the friendly message on a throttled backup action', function () {
    $this->mysql->scratchReady = false;

    foreach (range(1, 6) as $i) {
        $this->actingAs($this->admin)->post('/backups/test-restore');
    }

    $this->actingAs($this->admin)->post('/backups/test-restore', [], ['Accept' => 'application/json'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Too many requests, wait a minute.');
});
