<?php

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Models\AuditLog;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Services\AuditLogger;
use App\Services\BackupMonitor;
use App\Services\BackupService;
use App\Services\Setup\BackupFileChecker;
use App\Services\UsbCopyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * "Copy to USB" (POST /backups/usb-copy). The server copies the latest backup
 * to census-backups on a removable drive plugged into the server. The list of
 * drives is replaced by a folder per "drive", so no real USB drive is needed.
 * Rules: planning/architecture.md section 4, planning/security.md.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 10:00', 'Asia/Manila'));
    $this->base = storage_path('framework/testing/usb-'.uniqid());
    $this->folder = $this->base.'/backups';
    $this->usb = $this->base.'/E';
    File::ensureDirectoryExists($this->folder);
    File::ensureDirectoryExists($this->usb);
    config(['census.backup.path' => $this->folder]);

    $this->admin = userWithRole('admin', ['name' => 'Dr. Garcia']);
    fakeDrives($this, [['DeviceID' => 'E:', 'VolumeName' => 'CENSUS-USB', 'Size' => 16 * 1024 ** 3, 'FreeSpace' => 8 * 1024 ** 3]]);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

/**
 * Stand-in for the Windows drive query. "E:" is a folder inside the test
 * folder, so the copy really happens, but only there.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function fakeDrives(object $test, array $rows): void
{
    $usb = $test->usb;

    app()->bind(UsbCopyService::class, fn ($app) => new class($app->make(BackupMonitor::class), $app->make(BackupFileChecker::class), $app->make(AuditLogger::class), $rows, $usb) extends UsbCopyService
    {
        public function __construct(BackupMonitor $m, BackupFileChecker $c, AuditLogger $a, private array $rows, private string $usbFolder)
        {
            parent::__construct($m, $c, $a);
        }

        protected function rawDrives(): array
        {
            return $this->rows;
        }
    });
}

/** A backup on disk and its run, $hoursAgo hours old. */
function usbBackup(object $test, string $stamp = '2026-10-19_090000'): BackupRun
{
    $zip = new ZipArchive;
    $zip->open($test->folder."/patient_census_files_{$stamp}.zip", ZipArchive::CREATE);
    $zip->addFromString('backup-info.txt', 'info');
    $zip->close();

    file_put_contents($test->folder."/patient_census_{$stamp}.sql", "-- MySQL dump\nCREATE TABLE a (id int);\n-- Dump completed on x\n");

    return BackupRun::create([
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addMinute(),
        'status' => BackupStatus::Success,
        'file_name' => "patient_census_{$stamp}.sql",
        'size_bytes' => filesize($test->folder."/patient_census_{$stamp}.sql"),
        'zip_name' => "patient_census_files_{$stamp}.zip",
        'zip_size_bytes' => filesize($test->folder."/patient_census_files_{$stamp}.zip"),
    ]);
}

/** The service with its drive "E:" mapped to the test folder (a real drive path cannot exist here). */
function copyToUsb(object $test, string $drive = 'E:')
{
    return $test->actingAs($test->admin)->from('/backups')->post('/backups/usb-copy', ['drive' => $drive]);
}

// --- Which drives are offered ------------------------------------------------

it('lists only removable drives and never the system drive or the backup drive', function () {
    config(['census.backup.path' => 'D:/census-backups']);
    putenv('SystemDrive=C:');

    fakeDrives($this, [
        ['DeviceID' => 'C:', 'VolumeName' => 'Windows', 'Size' => 100, 'FreeSpace' => 50],
        ['DeviceID' => 'D:', 'VolumeName' => 'Backups', 'Size' => 100, 'FreeSpace' => 50],
        ['DeviceID' => 'E:', 'VolumeName' => 'CENSUS-USB', 'Size' => 16 * 1048576, 'FreeSpace' => 8 * 1048576],
        ['DeviceID' => '\\\\server\\share', 'VolumeName' => 'Network', 'Size' => 1, 'FreeSpace' => 1],
    ]);

    expect(app(UsbCopyService::class)->drives())->toBe([
        ['letter' => 'E:', 'label' => 'CENSUS-USB', 'free_bytes' => 8 * 1048576, 'size_bytes' => 16 * 1048576],
    ]);
});

it('asks for the drives only when the window opens (a partial reload)', function () {
    $this->actingAs($this->admin)->get('/backups')
        ->assertInertia(fn (Assert $page) => $page->missing('drives'));

    $this->actingAs($this->admin)
        ->get('/backups', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'backups/index', 'X-Inertia-Partial-Data' => 'drives', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => inertia()->getVersion()])
        ->assertOk()
        ->assertJsonPath('props.drives.0.letter', 'E:')
        ->assertJsonPath('props.drives.0.label', 'CENSUS-USB')
        ->assertJsonPath('props.drives.0.free_mb', 8192);
});

it('shows an empty list when no USB drive is plugged in', function () {
    fakeDrives($this, []);

    expect(app(UsbCopyService::class)->drives())->toBe([]);
});

it('refuses a drive that is not a removable drive in the list', function (string $letter) {
    $backup = usbBackup($this);
    fakeDrives($this, [
        ['DeviceID' => 'E:', 'VolumeName' => 'USB', 'Size' => 100, 'FreeSpace' => 100],
    ]);

    // F: is a fixed or network drive: the query only returns removable ones, so it is not in the list.
    copyToUsb($this, $letter);

    expect(BackupMark::count())->toBe(0)
        ->and(AuditLog::where('action', 'backup.usb_copy')->sole()->changes['result'])->toBe('failed')
        ->and($this->usb.'/census-backups')->not->toBeDirectory();
})->with(['F', 'F:', 'c', 'Z:']);

it('refuses a drive letter that is not one letter', function (string $bad) {
    usbBackup($this);

    $this->actingAs($this->admin)->from('/backups')->post('/backups/usb-copy', ['drive' => $bad])->assertSessionHasErrors('drive');

    expect(BackupMark::count())->toBe(0);
})->with(['', '../..', 'E:\\evil', 'EE', '//server/share']);

// --- The copy ---------------------------------------------------------------------

it('copies the sql and the zip to census-backups, checks the sizes and records the mark', function () {
    $run = usbBackup($this);
    $files = [$run->file_name, $run->zip_name];

    $result = withUsbRoot($this, fn () => app(UsbCopyService::class)->copyLatestIfIdle('E:', $this->admin));

    foreach ($files as $name) {
        expect($this->usb.'/census-backups/'.$name)->toBeFile()
            ->and(filesize($this->usb.'/census-backups/'.$name))->toBe(filesize($this->folder.'/'.$name));
    }

    expect($result['drive'])->toBe('E:')
        ->and($result['files'])->toBe($files)
        ->and($result['message'])->toStartWith('Copied patient_census_2026-10-19_090000.sql and patient_census_files_2026-10-19_090000.zip to E: (')
        ->and($result['message'])->toEndWith('MB). Store the USB in another room.');

    $mark = BackupMark::sole();
    expect($mark->type)->toBe(BackupMarkType::UsbCopy)
        ->and($mark->marked_by)->toBe($this->admin->id)
        ->and($mark->marked_at->equalTo(now()))->toBeTrue();

    $entry = AuditLog::where('action', 'backup.usb_copy')->sole();
    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->subject_type)->toBe('backup_runs')
        ->and($entry->changes['drive'])->toBe('E:')
        ->and($entry->changes['files'])->toBe(implode(', ', $files))
        ->and($entry->changes['result'])->toBe('success');
});

/**
 * Run $callback with drive E: pointing to the test folder. The service builds
 * the target as "E:\census-backups"; on a PC without an E: drive that is
 * not possible, so the test points it at a folder through a subclass.
 */
function withUsbRoot(object $test, Closure $callback): mixed
{
    $usb = $test->usb;
    $rows = [['DeviceID' => 'E:', 'VolumeName' => 'CENSUS-USB', 'Size' => 16 * 1024 ** 3, 'FreeSpace' => $test->freeSpace ?? 8 * 1024 ** 3]];
    $copyHook = $test->copyHook ?? null;

    app()->bind(UsbCopyService::class, fn ($app) => new class($app->make(BackupMonitor::class), $app->make(BackupFileChecker::class), $app->make(AuditLogger::class), $rows, $usb, $copyHook) extends UsbCopyService
    {
        public function __construct(BackupMonitor $m, BackupFileChecker $c, AuditLogger $a, private array $rows, private string $usbFolder, private ?Closure $hook)
        {
            parent::__construct($m, $c, $a);
        }

        protected function rawDrives(): array
        {
            return $this->rows;
        }

        protected function driveRoot(string $drive): string
        {
            return $this->usbFolder;
        }

        protected function copyFile(string $from, string $to): bool
        {
            $ok = parent::copyFile($from, $to);

            if ($this->hook !== null) {
                ($this->hook)($to);
            }

            return $ok;
        }
    });

    return $callback();
}

it('shows the plain success message through the button', function () {
    usbBackup($this);

    $response = withUsbRoot($this, fn () => copyToUsb($this));

    $response->assertRedirect('/backups');

    expect(BackupMark::count())->toBe(1);
    $response->assertSessionHas('inertia.flash_data.toast.type', 'success');
    expect($response->getSession()->get('inertia.flash_data.toast.message'))->toEndWith('Store the USB in another room.');
});

it('fails with a plain message and records nothing when the sizes do not match', function () {
    $run = usbBackup($this);
    // The copy of the zip comes out one byte short (a bad stick or a pulled plug).
    $this->copyHook = function (string $to) use ($run) {
        if (str_ends_with($to, $run->zip_name.'.part')) {
            file_put_contents($to, 'x');
        }
    };

    $response = withUsbRoot($this, fn () => copyToUsb($this));
    $response->assertRedirect('/backups');

    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(BackupMark::count())->toBe(0)
        ->and(glob($this->usb.'/census-backups/*') ?: [])->toBe([]); // half a copy is removed

    $entry = AuditLog::where('action', 'backup.usb_copy')->sole();
    expect($entry->changes['result'])->toBe('failed')
        ->and($entry->changes['error'])->toContain('not the same size');
});

it('fails with a plain message when the drive is full, and copies nothing', function () {
    usbBackup($this);
    $this->freeSpace = 10; // bytes

    withUsbRoot($this, fn () => copyToUsb($this));

    expect(BackupMark::count())->toBe(0)
        ->and(glob($this->usb.'/census-backups/*') ?: [])->toBe([])
        ->and(AuditLog::where('action', 'backup.usb_copy')->sole()->changes['error'])->toBe('The USB drive is full. Free some space or use another drive.');
});

it('fails with a plain message when no drive is plugged in', function () {
    usbBackup($this);
    fakeDrives($this, []);

    copyToUsb($this)->assertRedirect('/backups');

    expect(BackupMark::count())->toBe(0)
        ->and(AuditLog::where('action', 'backup.usb_copy')->sole()->changes['error'])
        ->toBe('Drive E: is not a removable USB drive plugged into the server. Plug the USB into the server PC and try again.');
});

it('does not copy a backup that is cut off', function () {
    $run = usbBackup($this);
    file_put_contents($this->folder.'/'.$run->file_name, "-- MySQL dump\nCREATE TABLE a (id int);\n");

    withUsbRoot($this, fn () => copyToUsb($this));

    expect(BackupMark::count())->toBe(0)
        ->and(glob($this->usb.'/census-backups/*') ?: [])->toBe([]);
});

// --- Keep 8 ---------------------------------------------------------------------------

it('keeps only the newest 8 backups on the USB and touches nothing else there', function () {
    $folder = $this->usb.'/census-backups';
    File::ensureDirectoryExists($folder);

    // Ten old backups (two files each), a photo and a note that are not ours, and a lookalike name.
    foreach (range(1, 10) as $day) {
        $stamp = sprintf('2026-09-%02d_020000', $day);
        file_put_contents("{$folder}/patient_census_{$stamp}.sql", 'old');
        file_put_contents("{$folder}/patient_census_files_{$stamp}.zip", 'old');
    }

    file_put_contents($folder.'/holiday.jpg', 'photo');
    file_put_contents($folder.'/patient_census_notes.txt', 'note');
    file_put_contents($this->usb.'/important.docx', 'doc');
    File::ensureDirectoryExists($this->usb.'/other-folder');
    file_put_contents($this->usb.'/other-folder/patient_census_2020-01-01_000000.sql', 'not in census-backups');

    usbBackup($this);

    withUsbRoot($this, fn () => app(UsbCopyService::class)->copyLatestIfIdle('E:', $this->admin));

    $sql = collect(glob($folder.'/patient_census_2*.sql'))->map('basename')->sort()->values()->all();

    // The new one plus the 7 newest old ones: 8 backups.
    expect($sql)->toHaveCount(UsbCopyService::KEEP)
        ->and(UsbCopyService::KEEP)->toBe(8)
        ->and($sql)->toContain('patient_census_2026-10-19_090000.sql', 'patient_census_2026-09-10_020000.sql', 'patient_census_2026-09-04_020000.sql')
        ->and($sql)->not->toContain('patient_census_2026-09-03_020000.sql');

    expect(glob($folder.'/patient_census_files_*.zip'))->toHaveCount(8)
        ->and($folder.'/patient_census_files_2026-09-03_020000.zip')->not->toBeFile()
        ->and($folder.'/holiday.jpg')->toBeFile()
        ->and($folder.'/patient_census_notes.txt')->toBeFile()
        ->and($this->usb.'/important.docx')->toBeFile()
        ->and($this->usb.'/other-folder/patient_census_2020-01-01_000000.sql')->toBeFile();
});

it('keeps all older backups when the copy fails', function () {
    $folder = $this->usb.'/census-backups';
    File::ensureDirectoryExists($folder);

    foreach (range(1, 9) as $day) {
        file_put_contents(sprintf('%s/patient_census_2026-09-%02d_020000.sql', $folder, $day), 'old');
    }

    usbBackup($this);
    $this->freeSpace = 5;

    withUsbRoot($this, fn () => copyToUsb($this));

    expect(glob($folder.'/patient_census_2026-09-*.sql'))->toHaveCount(9);
});

// --- Lock and roles -----------------------------------------------------------------------

it('refuses to copy while a backup is running', function () {
    usbBackup($this);
    $lock = Cache::lock(BackupService::LOCK, 60);
    expect($lock->get())->toBeTrue();

    withUsbRoot($this, fn () => copyToUsb($this))->assertRedirect('/backups');

    expect(BackupMark::count())->toBe(0)
        ->and(AuditLog::where('action', 'backup.usb_copy')->count())->toBe(0)
        ->and(glob($this->usb.'/census-backups/*') ?: [])->toBe([]);

    $lock->release();
    withUsbRoot($this, fn () => copyToUsb($this));

    expect(BackupMark::count())->toBe(1);
});

it('gives encoder, viewer and system admin a 403 and copies nothing', function (string $role) {
    usbBackup($this);

    $this->actingAs(userWithRole($role))->post('/backups/usb-copy', ['drive' => 'E:'])->assertForbidden();

    expect(BackupMark::count())->toBe(0)->and(AuditLog::where('action', 'backup.usb_copy')->count())->toBe(0);
})->with(['encoder', 'viewer', 'system_admin']);

it('sends a signed-out visitor to the login page', function () {
    $this->post('/backups/usb-copy', ['drive' => 'E:'])->assertRedirect(route('login'));
});

it('sends nothing to the browser: the answer is a redirect with a message, never a file', function () {
    usbBackup($this);

    $response = withUsbRoot($this, fn () => copyToUsb($this));

    $response->assertRedirect('/backups');
    expect($response->headers->get('Content-Disposition'))->toBeNull()
        ->and($response->headers->get('Content-Type'))->not->toContain('zip')->not->toContain('sql');
});

// --- Safe copy: ".part" files ---------------------------------------------------------------

it('copies to .part files and renames them only after the sizes are checked', function () {
    $run = usbBackup($this);
    $seen = [];
    $this->copyHook = function (string $to) use (&$seen) {
        $seen[] = basename($to);
    };

    withUsbRoot($this, fn () => app(UsbCopyService::class)->copyLatestIfIdle('E:', $this->admin));

    expect($seen)->toBe([$run->file_name.'.part', $run->zip_name.'.part'])
        ->and(glob($this->usb.'/census-backups/*.part') ?: [])->toBe([])
        ->and($this->usb.'/census-backups/'.$run->file_name)->toBeFile()
        ->and($this->usb.'/census-backups/'.$run->zip_name)->toBeFile();
});

it('never replaces an existing good copy when the new copy fails its size check', function () {
    $run = usbBackup($this);
    $folder = $this->usb.'/census-backups';
    File::ensureDirectoryExists($folder);

    // The same backup was copied before (a good copy).
    copy($this->folder.'/'.$run->file_name, $folder.'/'.$run->file_name);
    copy($this->folder.'/'.$run->zip_name, $folder.'/'.$run->zip_name);
    $goodSql = file_get_contents($folder.'/'.$run->file_name);
    $goodZip = file_get_contents($folder.'/'.$run->zip_name);

    // The new copy of the zip comes out short.
    $this->copyHook = function (string $to) use ($run) {
        if (str_ends_with($to, $run->zip_name.'.part')) {
            file_put_contents($to, 'x');
        }
    };

    withUsbRoot($this, fn () => copyToUsb($this));

    expect(file_get_contents($folder.'/'.$run->file_name))->toBe($goodSql)
        ->and(file_get_contents($folder.'/'.$run->zip_name))->toBe($goodZip)
        ->and(glob($folder.'/*.part') ?: [])->toBe([])
        ->and(BackupMark::count())->toBe(0);
});

it('needs room for the new copy while the old one is still there', function () {
    $run = usbBackup($this);
    $folder = $this->usb.'/census-backups';
    File::ensureDirectoryExists($folder);
    copy($this->folder.'/'.$run->file_name, $folder.'/'.$run->file_name);
    copy($this->folder.'/'.$run->zip_name, $folder.'/'.$run->zip_name);

    // Room for one set of files is not enough: the old copy stays until the new one is verified.
    $this->freeSpace = filesize($this->folder.'/'.$run->file_name) + filesize($this->folder.'/'.$run->zip_name) - 1;

    withUsbRoot($this, fn () => copyToUsb($this));

    expect(AuditLog::where('action', 'backup.usb_copy')->sole()->changes['error'])->toContain('USB drive is full')
        ->and(BackupMark::count())->toBe(0);
});

it('deletes leftover .part files of an earlier try and ignores them when counting the 8', function () {
    $folder = $this->usb.'/census-backups';
    File::ensureDirectoryExists($folder);

    foreach (range(1, 7) as $day) {
        $stamp = sprintf('2026-09-%02d_020000', $day);
        file_put_contents("{$folder}/patient_census_{$stamp}.sql", 'old');
    }

    // Leftovers of a stick that was pulled out, and a file that only looks similar.
    file_put_contents($folder.'/patient_census_2026-10-18_020000.sql.part', 'half');
    file_put_contents($folder.'/patient_census_files_2026-10-18_020000.zip.part', 'half');
    file_put_contents($folder.'/notes.part', 'mine');

    usbBackup($this);

    withUsbRoot($this, fn () => copyToUsb($this));

    expect(glob($folder.'/patient_census_*.part') ?: [])->toBe([])
        ->and($folder.'/notes.part')->toBeFile()
        // 7 old + the new one = 8 kept: the .part files were not counted as a backup.
        ->and(glob($folder.'/patient_census_2*.sql'))->toHaveCount(8)
        ->and($folder.'/patient_census_2026-09-01_020000.sql')->toBeFile();
});

it('leaves no .part file behind when the copy itself fails', function () {
    $run = usbBackup($this);
    $this->copyHook = function (string $to) use ($run) {
        if (str_ends_with($to, $run->zip_name.'.part')) {
            unlink($to); // the stick vanished
        }
    };

    withUsbRoot($this, fn () => copyToUsb($this));

    expect(glob($this->usb.'/census-backups/*') ?: [])->toBe([])
        ->and(BackupMark::count())->toBe(0);
});

it('fails when there is no successful backup yet, and still writes an audit entry', function () {
    copyToUsb($this)->assertRedirect('/backups');

    $entry = AuditLog::where('action', 'backup.usb_copy')->sole();
    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->subject_type)->toBe('users')
        ->and($entry->changes['result'])->toBe('failed')
        ->and($entry->changes['drive'])->toBe('E:')
        ->and($entry->changes['error'])->toBe('There is no successful backup to copy yet. Run a backup first.');
});

it('also copies the newest audit archive file, and leaves older archives on the USB alone', function () {
    $run = usbBackup($this);
    file_put_contents($this->folder.'/audit-archive_2026-01-05.csv.gz', 'older');
    file_put_contents($this->folder.'/audit-archive_2026-10-18.csv.gz', 'newest');
    touch($this->folder.'/audit-archive_2026-01-05.csv.gz', time() - 9000);
    File::ensureDirectoryExists($this->usb.'/census-backups');
    file_put_contents($this->usb.'/census-backups/audit-archive_2025-01-01.csv.gz', 'on usb already');

    $result = withUsbRoot($this, fn () => app(UsbCopyService::class)->copyLatestIfIdle('E:', $this->admin));

    expect($result['files'])->toBe([$run->file_name, $run->zip_name, 'audit-archive_2026-10-18.csv.gz'])
        ->and($this->usb.'/census-backups/audit-archive_2026-10-18.csv.gz')->toBeFile()
        ->and($this->usb.'/census-backups/audit-archive_2026-01-05.csv.gz')->not->toBeFile()
        ->and($this->usb.'/census-backups/audit-archive_2025-01-01.csv.gz')->toBeFile();
});
