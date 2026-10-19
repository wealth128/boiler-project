<?php

use App\Console\Commands\RestoreCommand;
use App\Models\BackupRun;
use App\Services\BackupService;
use App\Services\Setup\AppMaintenance;
use App\Services\Setup\BackupFileChecker;
use App\Services\Setup\MysqlAdmin;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAppMaintenance;
use Tests\Support\FakeMysqlAdmin;
use Tests\Support\SetupLog;

require_once __DIR__.'/../../Support/SetupFakes.php';

/*
 * census:restore checks everything BEFORE it drops the database: the dump
 * can be read and is not cut off, the mysql program starts, and the picture
 * zip opens. Each refusal leaves the site up and changes nothing: no root
 * login, no safety backup, no maintenance mode, no DROP DATABASE.
 */

const CHECK_DUMP = 'patient_census_2026-09-01_020000.sql';

beforeEach(function () {
    SetupLog::$calls = [];
    $this->travelTo(Carbon::parse('2026-10-09 14:00:00', 'Asia/Manila'));

    $this->dir = storage_path('framework/testing/restore-checks-'.uniqid());
    $this->backupDir = $this->dir.'/backups';
    File::ensureDirectoryExists($this->backupDir);
    File::ensureDirectoryExists($this->dir.'/private');

    config([
        'census.backup.path' => $this->backupDir,
        'census.backup.files_path' => $this->dir.'/private',
        'census.backup.mysqldump_path' => 'C:/fake/mysqldump.exe',
        'census.paths.env' => $this->dir.'/.env',
    ]);

    $this->mysql = new FakeMysqlAdmin;
    app()->instance(MysqlAdmin::class, $this->mysql);
    app()->instance(AppMaintenance::class, new FakeAppMaintenance);
    userWithRole('admin', ['username' => 'admin']);

    Process::fake(function (PendingProcess $process) {
        $result = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--result-file='));

        if ($result) {
            file_put_contents(substr($result, strlen('--result-file=')), "-- dump\n-- Dump completed on 2026-10-09  14:00:01\n");
        }

        return Process::result();
    });
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function putCheckedBackup(object $test, ?string $contents = null, bool $zip = true): string
{
    $path = $test->backupDir.'/'.CHECK_DUMP;
    file_put_contents($path, $contents ?? "-- MySQL dump\nCREATE TABLE visits (...);\n-- Dump completed on 2026-09-01  2:00:01\n");

    if ($zip) {
        $archive = new ZipArchive;
        $archive->open($test->backupDir.'/'.BackupService::zipNameFor(CHECK_DUMP), ZipArchive::CREATE);
        $archive->addFromString('logo.png', 'LOGO');
        $archive->close();
    }

    return $path;
}

/** Nothing was changed: no login, no safety backup, no maintenance mode, nothing dropped. */
function expectNothingTouched(): void
{
    expect(SetupLog::names())->not->toContain('connect', 'down', 'recreate', 'import', 'up')
        ->and(BackupRun::count())->toBe(0);
}

// --- (a, b) the dump ------------------------------------------------------------------

it('accepts a complete dump and a zip that opens', function () {
    putCheckedBackup($this);

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsOutputToContain('The backup files are complete and the mysql program works.')
        ->expectsQuestion('Type RESTORE to continue', 'RESTORE')
        ->expectsQuestion(RestoreCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->assertSuccessful();

    expect(SetupLog::names())->toContain('recreate', 'import');
});

it('refuses a dump that was cut off, before anything is changed', function (string $contents) {
    putCheckedBackup($this, $contents);

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsOutputToContain('This backup file is incomplete. Choose another one.')
        ->expectsOutputToContain('Nothing was changed. The system is still running.')
        ->assertFailed();

    expectNothingTouched();
})->with([
    'ends in the middle of a table' => ["-- MySQL dump\nCREATE TABLE visits (id int);\nINSERT INTO visits VALUES (1,'Cru"],
    'no end line at all' => ["-- MySQL dump\nCREATE TABLE visits (id int);\n"],
    'empty file' => [''],
]);

it('looks for the end line only at the end of a big file', function () {
    // "Dump completed" near the start (a cut-off file with a copied header) does not count.
    putCheckedBackup($this, "-- Dump completed on 2026-09-01\n".str_repeat('INSERT INTO t VALUES (1);'."\n", 400));

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsOutputToContain('This backup file is incomplete.')
        ->assertFailed();

    expectNothingTouched();
});

it('accepts a big dump whose end line is at the very end', function () {
    putCheckedBackup($this, str_repeat('INSERT INTO t VALUES (1);'."\n", 5000)."-- Dump completed on 2026-09-01  2:00:01\n");

    expect((new BackupFileChecker)->dumpProblem($this->backupDir.'/'.CHECK_DUMP))->toBeNull();
});

it('says a file that cannot be read cannot be read', function () {
    expect((new BackupFileChecker)->dumpProblem($this->backupDir.'/missing.sql'))->toBe(BackupFileChecker::UNREADABLE);
});

// --- (c) the mysql program --------------------------------------------------------------

it('runs the mysql version check, and refuses when it fails and no path is given', function () {
    putCheckedBackup($this);
    $this->mysql->clientOk = false;

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsOutputToContain('The mysql program was not found, or it does not start.')
        ->expectsQuestion('Full path to mysql.exe (press Enter to cancel)', '')
        ->expectsOutputToContain('The restore needs the mysql program to load the backup.')
        ->expectsOutputToContain('Nothing was changed. The system is still running.')
        ->assertFailed();

    expectNothingTouched();
});

it('asks for the path of mysql.exe, like the install asks for mysqldump, and uses it', function () {
    putCheckedBackup($this);
    $client = $this->dir.'/mysql.exe';
    file_put_contents($client, 'x');
    $this->mysql->clientOk = false;
    $this->mysql->workingClient = str_replace('\\', '/', $client);

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsQuestion('Full path to mysql.exe (press Enter to cancel)', $client)
        ->expectsQuestion('Type RESTORE to continue', 'RESTORE')
        ->expectsQuestion(RestoreCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->assertSuccessful();

    expect($this->mysql->clientUsed)->toBe(str_replace('\\', '/', $client));
});

it('asks again after a path that does not work, and can be cancelled', function () {
    putCheckedBackup($this);
    $this->mysql->clientOk = false;

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsQuestion('Full path to mysql.exe (press Enter to cancel)', 'C:/nowhere/mysql.exe')
        ->expectsOutputToContain('That does not work as the mysql program.')
        ->expectsQuestion('Full path to mysql.exe (press Enter to cancel)', '')
        ->assertFailed();

    expectNothingTouched();
    expect($this->mysql->clientUsed)->toBeNull();
});

it('finds the mysql program next to the configured mysqldump, or by a set path', function () {
    $admin = new MysqlAdmin;
    Process::fake(fn () => Process::result(output: 'mysql  Ver 8.0.36'));

    expect($admin->mysqlClientPath())->toBe('C:/fake/mysql.exe')
        ->and($admin->clientWorks())->toBeTrue();

    Process::assertRan(fn (PendingProcess $p) => $p->command === ['C:/fake/mysql.exe', '--version']);

    $admin->useClient('D:/tools/mysql.exe');
    expect($admin->mysqlClientPath())->toBe('D:/tools/mysql.exe');
    config(['census.backup.mysql_path' => null]);
});

it('says the mysql program does not work when it fails or cannot start', function () {
    $admin = new MysqlAdmin;

    Process::fake(fn () => Process::result(errorOutput: 'boom', exitCode: 1));
    expect($admin->clientWorks())->toBeFalse();

    Process::fake(fn () => throw new RuntimeException('The system cannot find the file specified.'));
    expect($admin->clientWorks())->toBeFalse();
});

// --- (d) the picture zip ------------------------------------------------------------------

it('refuses a picture zip that cannot be opened, before anything is changed', function () {
    putCheckedBackup($this, zip: false);
    file_put_contents($this->backupDir.'/'.BackupService::zipNameFor(CHECK_DUMP), 'this is not a zip file');

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsOutputToContain('The picture zip of this backup cannot be opened. Choose another backup.')
        ->expectsOutputToContain('Nothing was changed. The system is still running.')
        ->assertFailed();

    expectNothingTouched();
});

it('refuses a picture zip with a damaged part', function () {
    putCheckedBackup($this);
    $zipPath = $this->backupDir.'/'.BackupService::zipNameFor(CHECK_DUMP);
    $bytes = file_get_contents($zipPath);
    // Cut off the central directory at the end: the zip no longer opens.
    file_put_contents($zipPath, substr($bytes, 0, (int) (strlen($bytes) / 2)));

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsOutputToContain('The picture zip of this backup cannot be opened.')
        ->assertFailed();

    expectNothingTouched();
});

it('does not need a picture zip: a backup without one still passes the checks', function () {
    putCheckedBackup($this, zip: false);

    expect(app(BackupFileChecker::class)->dumpProblem($this->backupDir.'/'.CHECK_DUMP))->toBeNull();

    $this->artisan('census:restore', ['file' => CHECK_DUMP])
        ->expectsOutputToContain('no picture zip')
        ->expectsQuestion('Type RESTORE to continue', 'RESTORE')
        ->expectsQuestion(RestoreCommand::ROOT_PROMPT, 'root-secret-Pw1')
        ->assertSuccessful();
});

it('runs every check before the RESTORE question, so a bad file is never confirmed', function () {
    putCheckedBackup($this, '');

    // No expectsQuestion for RESTORE: asking it would fail the test.
    $this->artisan('census:restore', ['file' => CHECK_DUMP])->assertFailed();

    expectNothingTouched();
});
