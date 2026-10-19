<?php

use App\Console\Commands\RestoreCommand;
use App\Console\Commands\UpdateCommand;
use App\Enums\BackupStatus;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Visit;
use App\Services\BackupService;
use App\Services\FolderZipper;
use App\Services\Setup\AppMaintenance;
use App\Services\Setup\MysqlAdmin;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAppMaintenance;
use Tests\Support\FakeMysqlAdmin;
use Tests\Support\SetupLog;

require_once __DIR__.'/../../Support/SetupFakes.php';

/*
 * php artisan census:update and census:restore. The root login, maintenance
 * mode and the caches are faked, and so is mysqldump: a test never loads a
 * real dump or takes the real site down.
 */

const OLD_DUMP = 'patient_census_2026-09-01_020000.sql'; // 38 days old: a normal cleanup would delete it

beforeEach(function () {
    SetupLog::$calls = [];
    $this->travelTo(Carbon::parse('2026-10-09 14:00:00', 'Asia/Manila'));

    $this->dir = storage_path('framework/testing/setup-'.uniqid());
    $this->backupDir = $this->dir.'/backups';
    $this->pictures = $this->dir.'/private';
    File::ensureDirectoryExists($this->backupDir);
    File::ensureDirectoryExists($this->pictures);

    config([
        'census.backup.path' => $this->backupDir,
        'census.backup.files_path' => $this->pictures,
        'census.backup.mysqldump_path' => 'C:/fake/mysqldump.exe',
        'census.paths.env' => $this->dir.'/.env',
    ]);

    $this->mysql = new FakeMysqlAdmin;
    app()->instance(MysqlAdmin::class, $this->mysql);
    app()->instance(AppMaintenance::class, new FakeAppMaintenance);

    $this->admin = userWithRole('admin', ['username' => 'admin']);
    fakeDumpWorks();
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/** mysqldump that works, or fails with an error. */
function fakeDumpWorks(bool $works = true): void
{
    Process::fake(function (PendingProcess $process) use ($works) {
        $result = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--result-file='));

        if ($works && $result) {
            file_put_contents(substr($result, strlen('--result-file=')), "-- MySQL dump\nCREATE TABLE visits (...);\n");
        }

        return Process::result(errorOutput: $works ? '' : 'mysqldump: Got error: 1045 Access denied', exitCode: $works ? 0 : 2);
    });
}

/** A backup file (and optionally its picture zip) in the backup folder. */
function putBackup(object $test, string $name = OLD_DUMP, bool $withZip = true): string
{
    $path = $test->backupDir.'/'.$name;
    file_put_contents($path, "-- MySQL dump\nCREATE TABLE visits (...);\n-- Dump completed on 2026-09-01  2:00:01\n");

    if ($withZip) {
        $zip = new ZipArchive;
        $zip->open($test->backupDir.'/'.BackupService::zipNameFor($name), ZipArchive::CREATE);
        $zip->addFromString('logo.png', 'RESTORED-LOGO');
        $zip->addFromString('signatures/2026-09/prepared.png', 'RESTORED-SIGNATURE');
        $zip->close();
    }

    return $path;
}

function restore(object $test, string $file = OLD_DUMP, string $typed = 'RESTORE', string $rootPassword = 'root-secret-Pw1')
{
    return $test->artisan('census:restore', ['file' => $file])
        ->expectsQuestion('Type RESTORE to continue', $typed)
        ->expectsQuestion(RestoreCommand::ROOT_PROMPT, $rootPassword);
}

// --- census:update -----------------------------------------------------------

it('update: backs up, goes into maintenance mode, migrates, rebuilds the caches and comes back', function () {
    $this->artisan('census:update')
        ->expectsQuestion(UpdateCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->expectsOutputToContain('Backup saved: patient_census_2026-10-09_140000.sql and patient_census_files_2026-10-09_140000.zip')
        ->expectsOutputToContain('Update finished. Version '.config('census.version'))
        ->assertSuccessful();

    // The backup comes first, then maintenance mode, and "up" is last.
    expect(SetupLog::names())->toBe(['connect', 'down', 'migrate', 'clear-caches', 'rebuild-caches', 'up'])
        ->and(SetupLog::detail('down')['backups'])->toBe(1)
        ->and(BackupRun::sole()->status)->toBe(BackupStatus::Success);

    $entry = AuditLog::where('action', 'system.updated')->sole();
    expect($entry->subject_id)->toBe($this->admin->id)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->changes['version'])->toBe(config('census.version'))
        ->and($entry->changes['migrations_run'])->toBe(2)
        ->and(json_encode($entry->changes))->not->toContain('root-secret-Pw1');
});

it('update: stops when the backup fails, before maintenance mode or any change', function () {
    fakeDumpWorks(false);

    $this->artisan('census:update')
        ->expectsOutputToContain('The backup failed, so the update was stopped')
        ->assertFailed();

    expect(SetupLog::names())->toBe([])
        ->and(AuditLog::where('action', 'system.updated')->exists())->toBeFalse();
});

it('update: stops when the picture zip fails, because that is a failed backup too', function () {
    app()->bind(FolderZipper::class, fn () => new class extends FolderZipper
    {
        public function zip(string $source, string $zipPath, array $skip = []): int
        {
            throw new RuntimeException('The picture backup could not be written to the backup folder.');
        }
    });

    $this->artisan('census:update')->expectsOutputToContain('The backup failed')->assertFailed();

    expect(SetupLog::names())->toBe([]);
});

it('update: stops when a backup is already running', function () {
    $lock = Cache::lock(BackupService::LOCK, 60);
    $lock->get();

    $this->artisan('census:update')->expectsOutputToContain('A backup is already running')->assertFailed();

    expect(SetupLog::names())->toBe([]);
    $lock->release();
});

it('update: a wrong root password leaves the site up', function () {
    $this->artisan('census:update')
        ->expectsQuestion(UpdateCommand::ROOT_PROMPT, 'nope')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertFailed();

    expect(SetupLog::names())->toBe(['connect']);
});

it('update: keeps the site in maintenance mode when a migration fails', function () {
    $this->mysql->migrationFails = true;

    $this->artisan('census:update')
        ->expectsQuestion(UpdateCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->expectsOutputToContain('STILL IN MAINTENANCE MODE')
        ->expectsOutputToContain('php artisan up')
        ->expectsOutputToContain('php artisan census:restore patient_census_2026-10-09_140000.sql')
        ->assertFailed();

    expect(SetupLog::names())->toBe(['connect', 'down', 'migrate'])
        ->and(AuditLog::where('action', 'system.updated')->exists())->toBeFalse();
});

it('update: needs an installed system', function () {
    DB::table('users')->delete();

    $this->artisan('census:update')->expectsOutputToContain('not installed')->assertFailed();

    expect(SetupLog::names())->toBe([]);
});

// --- census:restore -----------------------------------------------------------

it('restore: shows the date and size, and does nothing unless RESTORE is typed', function (string $typed) {
    putBackup($this);

    $this->artisan('census:restore', ['file' => OLD_DUMP])
        ->expectsOutputToContain('Taken       : 01-Sep-2026 02:00')
        ->expectsOutputToContain('Size        :')
        ->expectsQuestion('Type RESTORE to continue', $typed)
        ->assertFailed();

    expect(SetupLog::names())->toBe([])
        ->and(BackupRun::count())->toBe(0);
})->with(['lowercase' => 'restore', 'no' => 'no', 'empty' => '', 'with spaces' => ' RESTORE ']);

it('restore: takes a safety backup first, then loads the data, the pictures and migrates', function () {
    $dump = putBackup($this);
    file_put_contents($this->pictures.'/logo.png', 'CURRENT-LOGO');
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $this->admin->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
    Visit::factory()->create(['visited_at' => '2026-08-31 09:30:00']);

    restore($this)
        ->expectsOutputToContain('Safety backup: patient_census_2026-10-09_140000.sql')
        ->expectsOutputToContain('Pictures restored (2 files)')
        ->expectsOutputToContain('Restore finished. Everyone must log in again.')
        ->expectsOutputToContain('Last encoded visit: 31-Aug-2026 09:30')
        ->assertSuccessful();

    // Safety backup BEFORE maintenance mode, then load, migrate, caches, up.
    expect(SetupLog::names())->toBe(['connect', 'down', 'recreate', 'import', 'migrate', 'clear-caches', 'rebuild-caches', 'refresh-connection', 'up'])
        ->and(SetupLog::detail('down')['backups'])->toBe(1)
        ->and(SetupLog::detail('import')['file'])->toBe(OLD_DUMP)
        ->and(BackupRun::sole()->status)->toBe(BackupStatus::Success)
        ->and(file_exists($this->backupDir.'/patient_census_2026-10-09_140000.sql'))->toBeTrue()
        ->and(file_exists($this->backupDir.'/patient_census_files_2026-10-09_140000.zip'))->toBeTrue();

    // The safety backup must not clean up the very file that is being restored.
    expect(file_exists($dump))->toBeTrue();

    // Pictures are back, and old sessions are gone.
    expect(file_get_contents($this->pictures.'/logo.png'))->toBe('RESTORED-LOGO')
        ->and(file_exists($this->pictures.'/signatures/2026-09/prepared.png'))->toBeTrue()
        ->and(DB::table('sessions')->count())->toBe(0);

    $entry = AuditLog::where('action', 'system.restored')->sole();
    expect($entry->changes['backup'])->toBe(OLD_DUMP)
        ->and($entry->changes['pictures_restored'])->toBeTrue()
        ->and($entry->changes['safety_backup'])->toBe('patient_census_2026-10-09_140000.sql')
        ->and(json_encode($entry->changes))->not->toContain('root-secret-Pw1');
});

it('restore: warns and continues when the picture zip is missing', function () {
    putBackup($this, withZip: false);

    restore($this)
        ->expectsOutputToContain('no matching picture zip')
        ->assertSuccessful();

    expect(SetupLog::names())->toContain('import')
        ->and(AuditLog::where('action', 'system.restored')->sole()->changes['pictures_restored'])->toBeFalse();
});

it('restore: accepts the full path of a file inside the backup folder', function () {
    $dump = putBackup($this);

    restore($this, $dump)->assertSuccessful();

    expect(SetupLog::detail('import')['file'])->toBe(OLD_DUMP);
});

it('restore: refuses a file outside the backup folder', function () {
    $outside = $this->dir.'/'.OLD_DUMP;
    file_put_contents($outside, 'x');

    foreach ([$outside, $this->backupDir.'/../'.OLD_DUMP] as $path) {
        $this->artisan('census:restore', ['file' => $path])
            ->expectsOutputToContain('Only files inside the backup folder')
            ->assertFailed();
    }

    expect(SetupLog::names())->toBe([])
        ->and(BackupRun::count())->toBe(0);
});

it('restore: refuses names that are not this system\'s backup names', function (string $name) {
    putBackup($this, 'patient_census_2026-09-01_020000.sql');
    file_put_contents($this->backupDir.'/'.basename($name), 'x');

    $this->artisan('census:restore', ['file' => $name])
        ->expectsOutputToContain('not a backup file name')
        ->assertFailed();

    expect(SetupLog::names())->toBe([]);
})->with([
    'any sql file' => 'dump.sql',
    'no time in the name' => 'patient_census_2026-09-01.sql',
    'a picture zip' => 'patient_census_files_2026-09-01_020000.zip',
    'wrong prefix' => 'other_2026-09-01_020000.sql',
    'extra ending' => 'patient_census_2026-09-01_020000.sql.bak',
    'a text file' => 'notes.txt',
]);

it('restore: says so when the file does not exist', function () {
    $this->artisan('census:restore', ['file' => 'patient_census_2026-01-01_020000.sql'])
        ->expectsOutputToContain('was not found')
        ->assertFailed();

    expect(SetupLog::names())->toBe([]);
});

it('restore: a wrong root password changes nothing and takes no safety backup', function () {
    putBackup($this);

    restore($this, rootPassword: 'nope')->expectsOutputToContain('Nothing was changed.')->assertFailed();

    expect(SetupLog::names())->toBe(['connect'])
        ->and(BackupRun::count())->toBe(0);
});

it('restore: stops before maintenance mode when the safety backup fails', function () {
    putBackup($this);
    fakeDumpWorks(false);

    restore($this)->expectsOutputToContain('The safety backup failed')->assertFailed();

    expect(SetupLog::names())->toBe(['connect'])
        ->and(AuditLog::where('action', 'system.restored')->exists())->toBeFalse();
});

it('restore: stays in maintenance mode and names the safety backup when loading fails', function () {
    putBackup($this);
    $this->mysql = new class extends FakeMysqlAdmin
    {
        public function importDump(string $dumpPath, string $database): void
        {
            SetupLog::add('import');

            throw new RuntimeException('ERROR 1064: syntax error');
        }
    };
    app()->instance(MysqlAdmin::class, $this->mysql);

    restore($this)
        ->expectsOutputToContain('The restore failed: ERROR 1064')
        ->expectsOutputToContain('STILL IN MAINTENANCE MODE')
        ->expectsOutputToContain('php artisan up')
        ->expectsOutputToContain('php artisan census:restore patient_census_2026-10-09_140000.sql')
        ->assertFailed();

    expect(SetupLog::names())->not->toContain('up')
        ->and(AuditLog::where('action', 'system.restored')->exists())->toBeFalse();
});

it('restore: needs an installed system', function () {
    putBackup($this);
    DB::table('users')->delete();

    $this->artisan('census:restore', ['file' => OLD_DUMP])->expectsOutputToContain('not installed')->assertFailed();

    expect(SetupLog::names())->toBe([]);
});

it('restore: also clears the cache and cache_locks tables, so old locks and the old heartbeat do not come back', function () {
    putBackup($this);
    DB::table('cache')->insert(['key' => 'scheduler.heartbeat', 'value' => 'i:1;', 'expiration' => 2000000000]);
    DB::table('cache')->insert(['key' => 'something-else', 'value' => 's:1:"x";', 'expiration' => 2000000000]);
    DB::table('cache_locks')->insert(['key' => 'backup-run', 'owner' => 'old-process', 'expiration' => 2000000000]);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $this->admin->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);

    restore($this)->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(0)
        ->and(DB::table('cache_locks')->count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('restore: leaves the cache alone when the restore is refused or fails', function () {
    putBackup($this);
    DB::table('cache_locks')->insert(['key' => 'backup-run', 'owner' => 'live-process', 'expiration' => 2000000000]);

    $this->artisan('census:restore', ['file' => OLD_DUMP])
        ->expectsQuestion('Type RESTORE to continue', 'no')
        ->assertFailed();

    expect(DB::table('cache_locks')->count())->toBe(1);
});
