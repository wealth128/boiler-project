<?php

use App\Enums\BackupStatus;
use App\Models\BackupRun;
use App\Services\BackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Daily backup. mysqldump is never really run here: Process::fake() stands
 * in for it and writes a small file where mysqldump would.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 02:00:00', 'Asia/Manila'));
    $this->folder = storage_path('framework/testing/backups-'.uniqid());
    config([
        'census.backup.path' => $this->folder,
        'census.backup.mysqldump_path' => 'C:/fake/mysqldump.exe',
        'database.connections.mysql.password' => 'db-secret-pass',
    ]);
    $GLOBALS['mysqldumpCalls'] = [];
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

/**
 * Fake mysqldump. It writes $contents to the --result-file (or nothing when
 * null) and ends with $exitCode. Each call is kept for dumpCalls().
 */
function fakeMysqldump(?string $contents = "-- MySQL dump\nCREATE TABLE visits (...);\n", int $exitCode = 0, string $error = ''): void
{
    Process::fake(function (PendingProcess $process) use ($contents, $exitCode, $error) {
        $args = (array) $process->command;
        $option = collect($args)->first(fn ($a) => str_starts_with($a, '--defaults-extra-file='));
        $optionFile = substr((string) $option, strlen('--defaults-extra-file='));

        $GLOBALS['mysqldumpCalls'][] = [
            'args' => $args,
            'option_file' => $optionFile,
            'option_contents' => is_file($optionFile) ? file_get_contents($optionFile) : null,
        ];

        $result = collect($args)->first(fn ($a) => str_starts_with($a, '--result-file='));

        if ($contents !== null && $result) {
            file_put_contents(substr($result, strlen('--result-file=')), $contents);
        }

        return Process::result(output: '', errorOutput: $error, exitCode: $exitCode);
    });
}

/** @return list<array{args: list<string>, option_file: string, option_contents: ?string}> */
function dumpCalls(): array
{
    return $GLOBALS['mysqldumpCalls'];
}

function backupFile(string $folder, string $takenAt): string
{
    File::ensureDirectoryExists($folder);
    $path = $folder.DIRECTORY_SEPARATOR.'patient_census_'.Carbon::parse($takenAt)->format('Y-m-d_His').'.sql';
    file_put_contents($path, 'old');

    return $path;
}

it('saves a backup file and records the run', function () {
    fakeMysqldump();

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Success)
        ->and($run->file_name)->toBe('patient_census_2026-10-09_020000.sql')
        ->and($run->size_bytes)->toBeGreaterThan(0)
        ->and($run->message)->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and(file_exists($this->folder.DIRECTORY_SEPARATOR.$run->file_name))->toBeTrue()
        ->and(BackupRun::count())->toBe(1);
});

it('runs mysqldump with a consistent copy of this database', function () {
    fakeMysqldump();

    app(BackupService::class)->run();

    $args = dumpCalls()[0]['args'];
    expect($args[0])->toBe('C:/fake/mysqldump.exe')
        ->and($args[1])->toStartWith('--defaults-extra-file=')
        ->and($args)->toContain('--single-transaction')
        ->and(end($args))->toBe('patient_census_test');
});

it('keeps the database password off the command line and deletes the login file', function () {
    fakeMysqldump();

    app(BackupService::class)->run();

    $call = dumpCalls()[0];
    expect(implode(' ', $call['args']))->not->toContain('db-secret-pass')
        ->and($call['option_contents'])->toContain('[client]')
        ->and($call['option_contents'])->toContain('password="db-secret-pass"')
        ->and(file_exists($call['option_file']))->toBeFalse();
});

it('records a failed run with the error, and leaves no half file', function () {
    fakeMysqldump(contents: '-- partial', exitCode: 2, error: "mysqldump: Got error: 1045: Access denied for user 'census_app'");

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->message)->toContain('Access denied')
        ->and($run->file_name)->toBeNull()
        ->and(glob($this->folder.'/*.sql'))->toBe([])
        ->and(file_exists(dumpCalls()[0]['option_file']))->toBeFalse();
});

it('fails when mysqldump leaves an empty file', function () {
    fakeMysqldump(contents: '');

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->message)->toContain('missing or empty');
});

it('fails clearly when mysqldump cannot be started', function () {
    Process::fake(fn () => throw new RuntimeException('The system cannot find the file specified.'));

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->message)->toContain('cannot find the file');
});

it('fails clearly when the backup folder cannot be created', function () {
    File::ensureDirectoryExists($this->folder);
    file_put_contents($this->folder.'/not-a-folder', 'x');
    config(['census.backup.path' => $this->folder.'/not-a-folder/backups']);
    fakeMysqldump();

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->message)->toContain('could not be created')
        ->and(dumpCalls())->toBe([]);
});

it('keeps the last 30 days and deletes older backups', function () {
    $old = backupFile($this->folder, '2026-09-08 02:00:00');      // 31 days
    $recent = backupFile($this->folder, '2026-09-10 02:00:00');   // 29 days
    file_put_contents($this->folder.'/notes.txt', 'keep me');
    file_put_contents($this->folder.'/other_app_2020-01-01_000000.sql', 'keep me');
    fakeMysqldump();

    $run = app(BackupService::class)->run();

    expect(file_exists($old))->toBeFalse()
        ->and(file_exists($recent))->toBeTrue()
        ->and(file_exists($this->folder.'/notes.txt'))->toBeTrue()
        ->and(file_exists($this->folder.'/other_app_2020-01-01_000000.sql'))->toBeTrue()
        ->and($run->message)->toBe('Removed 1 old backup file(s) older than 30 days.');
});

it('deletes no old backups after a failed run', function () {
    $old = backupFile($this->folder, '2026-08-01 02:00:00');
    fakeMysqldump(exitCode: 1, error: 'boom');

    app(BackupService::class)->run();

    expect(file_exists($old))->toBeTrue();
});

it('has a backup:run command that reports the result', function () {
    fakeMysqldump();
    $this->artisan('backup:run')
        ->expectsOutputToContain('Backup saved: patient_census_2026-10-09_020000.sql')
        ->assertSuccessful();

    $this->travel(1)->minutes();
    fakeMysqldump(exitCode: 1, error: 'Access denied');
    $this->artisan('backup:run')
        ->expectsOutputToContain('Backup failed: Access denied')
        ->assertFailed();

    expect(BackupRun::pluck('status')->all())->toBe([BackupStatus::Success, BackupStatus::Failed]);
});

it('is scheduled daily at 2:00 AM', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'backup:run'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('passes SystemRoot to mysqldump on Windows, so it can open a network socket', function () {
    fakeMysqldump();

    app(BackupService::class)->run();

    $seen = null;
    Process::assertRan(function (PendingProcess $process) use (&$seen) {
        $seen = $process->environment;

        return true;
    });

    if (DIRECTORY_SEPARATOR === '\\') {
        expect($seen)->toHaveKeys(['SystemRoot', 'windir'])
            ->and($seen['SystemRoot'])->not->toBe('');
    } else {
        expect($seen)->toBe([]);
    }
});
