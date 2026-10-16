<?php

use App\Enums\BackupStatus;
use App\Services\BackupService;
use App\Services\FolderZipper;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Every backup also zips the picture files (storage/app/private) next to
 * the database dump, with the same date stamp. A run is a success only if
 * both files were written (planning/architecture.md, section 4).
 * mysqldump is faked; the zip is real unless a test replaces it.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 02:00:00', 'Asia/Manila'));
    $base = storage_path('framework/testing/pictures-'.uniqid());
    $this->folder = $base.'/backups';
    $this->pictures = $base.'/private';
    $this->base = $base;

    File::ensureDirectoryExists($this->pictures.'/signatures/2026-09');
    file_put_contents($this->pictures.'/logo.png', 'LOGO-BYTES');
    file_put_contents($this->pictures.'/signatures/2026-09/prepared.png', 'SIGNATURE-BYTES');

    config([
        'census.backup.path' => $this->folder,
        'census.backup.files_path' => $this->pictures,
        'census.backup.mysqldump_path' => 'C:/fake/mysqldump.exe',
    ]);

    Process::fake(function (PendingProcess $process) {
        $result = collect((array) $process->command)->first(fn ($a) => str_starts_with($a, '--result-file='));
        file_put_contents(substr($result, strlen('--result-file=')), "-- MySQL dump\nCREATE TABLE visits (...);\n");

        return Process::result();
    });
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

/** @return list<string> */
function zipEntries(string $zipPath): array
{
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $zip->close();
    sort($names);

    return $names;
}

it('writes the database dump and a picture zip with the same date stamp', function () {
    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Success)
        ->and($run->file_name)->toBe('patient_census_2026-10-09_020000.sql')
        ->and($run->zip_name)->toBe('patient_census_files_2026-10-09_020000.zip')
        ->and($run->size_bytes)->toBeGreaterThan(0)
        ->and($run->zip_size_bytes)->toBeGreaterThan(0)
        ->and(file_exists($this->folder.'/'.$run->file_name))->toBeTrue()
        ->and(file_exists($this->folder.'/'.$run->zip_name))->toBeTrue()
        ->and($run->zip_size_bytes)->toBe(filesize($this->folder.'/'.$run->zip_name));
});

it('puts the logo and the signature folders in the zip, and not the backup folder itself', function () {
    // The backup folder inside the pictures folder must not be zipped into itself.
    config(['census.backup.path' => $this->pictures.'/backups']);
    File::ensureDirectoryExists($this->pictures.'/backups');
    file_put_contents($this->pictures.'/backups/patient_census_2026-10-01_020000.sql', 'OLD-DUMP');

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Success)
        ->and(zipEntries($this->pictures.'/backups/'.$run->zip_name))->toBe([
            'backup-info.txt',
            'logo.png',
            'signatures/2026-09/prepared.png',
        ]);
});

it('still makes a valid zip when there are no pictures yet', function () {
    File::deleteDirectory($this->pictures);

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Success)
        ->and(zipEntries($this->folder.'/'.$run->zip_name))->toBe(['backup-info.txt']);
});

it('fails the run, and leaves no half backup, when the zip cannot be made', function () {
    app()->bind(FolderZipper::class, fn () => new class extends FolderZipper
    {
        public function zip(string $source, string $zipPath, array $skip = []): int
        {
            file_put_contents($zipPath, 'half'); // a broken zip is left behind

            throw new RuntimeException('The picture backup could not be written to the backup folder.');
        }
    });

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->message)->toContain('picture backup')
        ->and($run->file_name)->toBeNull()
        ->and($run->zip_name)->toBeNull()
        ->and(glob($this->folder.'/*'))->toBe([]);
});

it('fails the run when the zip is missing or empty', function () {
    app()->bind(FolderZipper::class, fn () => new class extends FolderZipper
    {
        public function zip(string $source, string $zipPath, array $skip = []): int
        {
            return 0; // says it worked, wrote nothing
        }
    });

    $run = app(BackupService::class)->run();

    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->message)->toContain('missing or empty')
        ->and(glob($this->folder.'/*'))->toBe([]);
});

it('removes old picture zips with the old dumps, and keeps the new ones and other files', function () {
    File::ensureDirectoryExists($this->folder);
    $files = [
        'patient_census_2026-09-08_020000.sql' => false,        // 31 days
        'patient_census_files_2026-09-08_020000.zip' => false,
        'patient_census_files_2026-08-01_020000.zip' => false,  // a zip whose dump is already gone
        'patient_census_2026-09-10_020000.sql' => true,         // 29 days
        'patient_census_files_2026-09-10_020000.zip' => true,
        'notes.txt' => true,
        'patient_census_files_old.zip' => true,                 // not this app's naming
    ];

    foreach (array_keys($files) as $name) {
        file_put_contents($this->folder.'/'.$name, 'x');
    }

    $run = app(BackupService::class)->run();

    foreach ($files as $name => $kept) {
        expect(file_exists($this->folder.'/'.$name))->toBe($kept, $name);
    }

    expect($run->message)->toBe('Removed 3 old backup file(s) older than 30 days.');
});

it('deletes nothing old after a failed run, zips included', function () {
    File::ensureDirectoryExists($this->folder);
    $oldZip = $this->folder.'/patient_census_files_2026-08-01_020000.zip';
    file_put_contents($oldZip, 'x');

    Process::fake(fn () => Process::result(errorOutput: 'boom', exitCode: 1));

    app(BackupService::class)->run();

    expect(file_exists($oldZip))->toBeTrue();
});

it('can skip the cleanup, for the safety backup before a restore', function () {
    File::ensureDirectoryExists($this->folder);
    $old = $this->folder.'/patient_census_2026-08-01_020000.sql';
    file_put_contents($old, 'x');

    $run = app(BackupService::class)->runIfIdle(prune: false);

    expect($run->status)->toBe(BackupStatus::Success)
        ->and(file_exists($old))->toBeTrue();
});

it('names the zip of a dump by its date stamp, and only for real backup names', function () {
    expect(BackupService::zipNameFor('patient_census_2026-10-09_020000.sql'))->toBe('patient_census_files_2026-10-09_020000.zip')
        ->and(BackupService::zipNameFor('patient_census_2026-10-09.sql'))->toBeNull()
        ->and(BackupService::zipNameFor('other.sql'))->toBeNull();
});

it('unzips pictures back, and skips entries that try to leave the folder', function () {
    $zipPath = $this->base.'/evil.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('logo.png', 'NEW-LOGO');
    $zip->addFromString('signatures/2026-09/noted.png', 'NOTED');
    $zip->addFromString('../escaped.txt', 'NO');
    $zip->addFromString('C:/Windows/escaped.txt', 'NO');
    $zip->addFromString(FolderZipper::MANIFEST, 'info');
    $zip->close();

    $target = $this->base.'/restored';
    $count = app(FolderZipper::class)->unzip($zipPath, $target);

    expect($count)->toBe(2)
        ->and(file_get_contents($target.'/logo.png'))->toBe('NEW-LOGO')
        ->and(file_exists($target.'/signatures/2026-09/noted.png'))->toBeTrue()
        ->and(file_exists($this->base.'/escaped.txt'))->toBeFalse()
        ->and(file_exists($target.'/'.FolderZipper::MANIFEST))->toBeFalse();
});
