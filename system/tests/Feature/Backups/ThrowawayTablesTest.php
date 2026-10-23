<?php

use App\Enums\BackupStatus;
use App\Services\BackupService;
use App\Services\CacheCleaner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Step 7d-4, fix 2: expired rows of the cache tables are deleted every night, and
 * the backup does not carry the rows of the cache, cache_locks and sessions tables
 * (only their structure, so a restore still finds the tables, empty).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 02:00:00', 'Asia/Manila'));
    $this->folder = storage_path('framework/testing/throwaway-'.uniqid());
    config([
        'census.backup.path' => $this->folder,
        'census.backup.mysqldump_path' => 'C:/fake/mysqldump.exe',
        'database.connections.mysql.password' => 'db-secret-pass',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
});

it('deletes expired rows from the cache and cache_locks tables and keeps the valid ones', function () {
    $now = now()->getTimestamp();

    DB::table('cache')->insert([
        ['key' => 'old-report', 'value' => 'x', 'expiration' => $now - 10],
        ['key' => 'just-expired', 'value' => 'x', 'expiration' => $now],
        ['key' => 'fresh-report', 'value' => 'x', 'expiration' => $now + 3600],
    ]);
    DB::table('cache_locks')->insert([
        ['key' => 'old-lock', 'owner' => 'a', 'expiration' => $now - 10],
        ['key' => 'backup-run', 'owner' => 'b', 'expiration' => $now + 600],
    ]);

    $deleted = app(CacheCleaner::class)->purgeExpired();

    expect($deleted)->toBe(3)
        ->and(DB::table('cache')->pluck('key')->all())->toBe(['fresh-report'])
        ->and(DB::table('cache_locks')->pluck('key')->all())->toBe(['backup-run']);
});

it('is scheduled every day', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'cache-purge-expired');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 1 * * *');
});

/** A fake mysqldump that writes a different text for the data dump and the structure dump. @return list<list<string>> the commands, as they are run */
function fakeTwoDumps(): ArrayObject
{
    $calls = new ArrayObject;

    Process::fake(function (PendingProcess $process) use ($calls) {
        $args = (array) $process->command;
        $calls[] = $args;
        $target = collect($args)->first(fn ($a) => str_starts_with($a, '--result-file='));

        if (in_array('--no-data', $args, true)) {
            file_put_contents(substr($target, strlen('--result-file=')), "-- structure\nCREATE TABLE `cache` (...);\n");
        } else {
            file_put_contents(substr($target, strlen('--result-file=')), "-- data\nCREATE TABLE visits (...);\n");
        }

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });

    return $calls;
}

it('leaves the rows of the cache, cache_locks and sessions tables out of the backup', function () {
    $calls = fakeTwoDumps();
    $database = (string) config('database.connections.mysql.database');

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Success)
        ->and($calls)->toHaveCount(2);

    $data = $calls[0];

    expect($data)->toContain("--ignore-table={$database}.cache")
        ->toContain("--ignore-table={$database}.cache_locks")
        ->toContain("--ignore-table={$database}.sessions")
        ->not->toContain('--no-data');
});

it('adds the structure of those tables to the same backup file, and leaves no temporary file', function () {
    $calls = fakeTwoDumps();
    $database = (string) config('database.connections.mysql.database');

    $run = app(BackupService::class)->run();
    $file = $this->folder.DIRECTORY_SEPARATOR.$run->file_name;
    $structure = $calls[1];

    expect($structure)->toContain('--no-data')
        ->and(array_slice($structure, -4))->toBe([$database, 'cache', 'cache_locks', 'sessions'])
        ->and(file_get_contents($file))->toContain('-- data')->toContain('CREATE TABLE `cache`')
        ->and(File::glob($this->folder.DIRECTORY_SEPARATOR.'*.structure'))->toBeEmpty();
});

it('fails the backup, and keeps no half file, when the structure dump fails', function () {
    $count = 0;

    Process::fake(function (PendingProcess $process) use (&$count) {
        $args = (array) $process->command;
        $target = collect($args)->first(fn ($a) => str_starts_with($a, '--result-file='));
        $count++;

        if ($count === 2) {
            file_put_contents(substr($target, strlen('--result-file=')), 'half');

            return Process::result(output: '', errorOutput: 'mysqldump: Got error: 1045', exitCode: 2);
        }

        file_put_contents(substr($target, strlen('--result-file=')), "-- data\n");

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and(File::glob($this->folder.DIRECTORY_SEPARATOR.'patient_census_*'))->toBeEmpty();
});

it('names the throwaway tables from the configuration', function () {
    config(['cache.stores.database.table' => 'my_cache', 'cache.stores.database.lock_table' => 'my_locks', 'session.table' => 'my_sessions']);

    expect(BackupService::throwawayTables())->toBe(['my_cache', 'my_locks', 'my_sessions']);
});
