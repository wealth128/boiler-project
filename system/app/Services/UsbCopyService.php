<?php

namespace App\Services;

use App\Enums\BackupMarkType;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Setup\BackupFileChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * "Copy to USB" (planning/architecture.md section 4). The server copies the
 * latest successful backup (the .sql and the picture .zip) to a folder
 * census-backups on a removable drive plugged into the SERVER PC. Nothing is
 * sent to the browser.
 *
 * - Only Windows removable drives (drive type 2) are listed. Network and
 *   system drives, and the drive the backups live on, never are, and the
 *   drive letter Admin sends is checked against that list again.
 * - After copying, the sizes of the copies must match the originals.
 * - Then only the newest 8 backups stay on the USB: older census backup
 *   files are deleted in that one folder. Other files are never touched.
 * - A success records the "USB copy" mark. A failure records no mark.
 *
 * It uses the backup lock, so a copy never runs while a backup is being made.
 */
class UsbCopyService
{
    public const FOLDER = 'census-backups';

    /** How many backups stay on the USB. */
    public const KEEP = 8;

    /** A half-written copy: patient_census_...sql.part or ...zip.part. */
    public const PART_PATTERN = '/^patient_census_(files_)?\d{4}-\d{2}-\d{2}_\d{6}\.(sql|zip)\.part$/';

    public function __construct(
        private readonly BackupMonitor $monitor,
        private readonly BackupFileChecker $checker,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Removable drives plugged into this PC.
     *
     * @return list<array{letter: string, label: string, free_bytes: int, size_bytes: int}>
     */
    public function drives(): array
    {
        $own = strtoupper(substr((string) config('census.backup.path'), 0, 2));
        $system = strtoupper((string) (getenv('SystemDrive') ?: 'C:'));
        $drives = [];

        foreach ($this->rawDrives() as $row) {
            $id = strtoupper((string) ($row['DeviceID'] ?? ''));

            if (preg_match('/^[A-Z]:$/', $id) !== 1 || $id === $system || $id === $own) {
                continue;
            }

            $drives[] = [
                'letter' => $id,
                'label' => (string) ($row['VolumeName'] ?? ''),
                'free_bytes' => (int) ($row['FreeSpace'] ?? 0),
                'size_bytes' => (int) ($row['Size'] ?? 0),
            ];
        }

        return $drives;
    }

    /**
     * Copy the latest backup to the drive. Null when a backup or a test
     * restore is running.
     *
     * @return array{drive: string, files: list<string>, size_mb: string, message: string}|null
     *
     * @throws UsbCopyFailed with a plain message when nothing was copied
     */
    public function copyLatestIfIdle(string $letter, ?User $by): ?array
    {
        $lock = Cache::lock(BackupService::LOCK, BackupService::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->copyLatest($letter, $by);
        } finally {
            $lock->release();
        }
    }

    /** @return array{drive: string, files: list<string>, size_mb: string, message: string} */
    private function copyLatest(string $letter, ?User $by): array
    {
        $backup = $this->monitor->lastSuccess();
        $drive = strtoupper(rtrim($letter, ':\\/')).':';
        $files = [];

        try {
            if ($backup === null) {
                throw new UsbCopyFailed('There is no successful backup to copy yet. Run a backup first.');
            }

            $found = collect($this->drives())->firstWhere('letter', $drive);

            if ($found === null) {
                throw new UsbCopyFailed('Drive '.$drive.' is not a removable USB drive plugged into the server. Plug the USB into the server PC and try again.');
            }

            $sources = $this->sourcesOf($backup);
            $files = array_keys($sources);
            $target = rtrim($this->driveRoot($drive), '/'.chr(92)).DIRECTORY_SEPARATOR.self::FOLDER;

            $this->prepare($target);
            $this->checkSpace($sources, $target, (int) $found['free_bytes']);
            $this->copyFiles($sources, $target);
            $this->prune($target);

            $size = BackupMonitor::megabytes((int) array_sum($sources));
            $message = 'Copied '.implode(' and ', $files)." to {$drive} ({$size}). Store the USB in another room.";

            BackupMark::create(['type' => BackupMarkType::UsbCopy, 'marked_by' => $by?->id, 'marked_at' => now()]);
            $this->audit->log('backup.usb_copy', $backup, [
                'label' => "Copy to USB {$drive}",
                'drive' => $drive,
                'files' => implode(', ', $files),
                'result' => 'success',
                'size' => $size,
            ], $by);

            return ['drive' => $drive, 'files' => $files, 'size_mb' => $size, 'message' => $message];
        } catch (UsbCopyFailed $e) {
            $this->auditFailure($backup, $drive, $files, $e->getMessage(), $by);

            throw $e;
        } catch (Throwable $e) {
            report($e);
            $failure = new UsbCopyFailed('The copy to the USB drive failed. Check the drive and try again.', 0, $e);
            $this->auditFailure($backup, $drive, $files, $failure->getMessage(), $by);

            throw $failure;
        }
    }

    /**
     * The files of a backup, checked: name => size in bytes.
     *
     * @return array<string, int>
     */
    private function sourcesOf(BackupRun $backup): array
    {
        $folder = rtrim((string) config('census.backup.path'), '\\/');
        $sources = [];

        foreach ([$backup->file_name, $backup->zip_name] as $name) {
            $path = $folder.DIRECTORY_SEPARATOR.(string) $name;

            if ($name === null || ! is_file($path)) {
                throw new UsbCopyFailed('The latest backup files are missing from the backup folder. Run a new backup first.');
            }

            $sources[$name] = (int) filesize($path);
        }

        $problem = $this->checker->dumpProblem($folder.DIRECTORY_SEPARATOR.$backup->file_name)
            ?? $this->checker->zipProblem($folder.DIRECTORY_SEPARATOR.$backup->zip_name);

        if ($problem !== null) {
            throw new UsbCopyFailed('The latest backup is damaged, so it was not copied. Run a new backup first.');
        }

        return $sources;
    }

    private function prepare(string $target): void
    {
        if (! is_dir($target) && ! @mkdir($target, 0755, true) && ! is_dir($target)) {
            throw new UsbCopyFailed('The folder '.self::FOLDER.' could not be created on the USB drive. Is it write-protected?');
        }

        // Half-written copies of an earlier try (a stick pulled out). Only our own
        // backup names ending in .part are deleted, nothing else in the folder.
        foreach (scandir($target) ?: [] as $name) {
            if (preg_match(self::PART_PATTERN, $name) === 1) {
                @unlink($target.DIRECTORY_SEPARATOR.$name);
            }
        }
    }

    /** @param  array<string, int>  $sources */
    private function checkSpace(array $sources, string $target, int $free): void
    {
        // An older copy of the same backup stays until the new one is checked,
        // so the full size is needed, not just the difference.
        if (array_sum($sources) > $free) {
            throw new UsbCopyFailed('The USB drive is full. Free some space or use another drive.');
        }
    }

    /**
     * Copy each file to "<name>.part", check its size, and only when ALL are
     * good rename them to their real names. An existing good copy is never
     * touched before the new one is verified, and a failed try leaves no
     * .part file behind.
     *
     * @param  array<string, int>  $sources
     */
    private function copyFiles(array $sources, string $target): void
    {
        $folder = rtrim((string) config('census.backup.path'), '\\/');
        $parts = [];

        try {
            foreach ($sources as $name => $size) {
                $part = $target.DIRECTORY_SEPARATOR.$name.'.part';
                $parts[$name] = $part;

                if (! $this->copyFile($folder.DIRECTORY_SEPARATOR.$name, $part)) {
                    throw new UsbCopyFailed('Copying '.$name.' to the USB drive failed. Is the drive full or removed?');
                }

                clearstatcache(true, $part);

                if ((int) @filesize($part) !== $size) {
                    throw new UsbCopyFailed('The copy of '.$name.' on the USB drive is not the same size as the original. Nothing was kept. Try again.');
                }
            }

            foreach ($parts as $name => $part) {
                if (! @rename($part, $target.DIRECTORY_SEPARATOR.$name)) {
                    throw new UsbCopyFailed('The copy of '.$name.' on the USB drive could not be finished. Is the drive full or removed?');
                }
            }
        } finally {
            // Whatever is still a .part file did not make it.
            foreach ($parts as $part) {
                if (is_file($part)) {
                    @unlink($part);
                }
            }
        }
    }

    /** Keep the newest KEEP backups (by the date in the name); delete older census files here only. */
    private function prune(string $target): void
    {
        $stamps = [];

        foreach (scandir($target) ?: [] as $name) {
            if (preg_match(BackupService::FILE_PATTERN, $name, $m) === 1 || preg_match(BackupService::ZIP_PATTERN, $name, $m) === 1) {
                $stamps[$m[1]][] = $name;
            }
        }

        krsort($stamps);

        foreach (array_slice($stamps, self::KEEP, null, true) as $names) {
            foreach ($names as $name) {
                @unlink($target.DIRECTORY_SEPARATOR.$name);
            }
        }
    }

    /**
     * The failure goes to the audit log too. With no backup to point at, the
     * entry points at the person who clicked.
     *
     * @param  list<string>  $files
     */
    private function auditFailure(?BackupRun $backup, string $drive, array $files, string $error, ?User $by): void
    {
        $subject = $backup ?? $by;

        if ($subject === null) {
            return;
        }

        $this->audit->log('backup.usb_copy', $subject, array_filter([
            'label' => "Copy to USB {$drive}",
            'drive' => $drive,
            'files' => implode(', ', $files),
            'result' => 'failed',
            'error' => $error,
        ]), $by);
    }

    /** The top folder of a drive, e.g. "E:\". Tests point it at a plain folder. */
    protected function driveRoot(string $drive): string
    {
        return $drive.DIRECTORY_SEPARATOR;
    }

    protected function copyFile(string $from, string $to): bool
    {
        return @copy($from, $to);
    }

    /**
     * Windows removable drives (type 2) from the system: DeviceID, VolumeName,
     * Size, FreeSpace. Empty on any other system or when the query fails.
     * Tests replace this method.
     *
     * @return list<array<string, mixed>>
     */
    protected function rawDrives(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [];
        }

        try {
            $result = Process::timeout(20)
                ->env(BackupService::windowsEnvironment())
                ->run([
                    'powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
                    "Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=2' | Select-Object DeviceID,VolumeName,Size,FreeSpace | ConvertTo-Json -Compress",
                ]);
        } catch (Throwable) {
            return [];
        }

        $json = json_decode(trim($result->output()), true);

        if (! $result->successful() || ! is_array($json)) {
            return [];
        }

        return array_is_list($json) ? $json : [$json];
    }
}
