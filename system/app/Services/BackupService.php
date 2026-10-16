<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Models\BackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Daily database backup (planning/architecture.md, section 4).
 *
 * 1. Runs mysqldump (MYSQLDUMP_PATH) into BACKUP_PATH as
 *    patient_census_YYYY-MM-DD_HHMMSS.sql.
 * 2. After a successful backup, deletes this app's backup files older than
 *    30 days from that folder. Other files are never touched, and nothing
 *    is deleted after a failed run, so old copies survive a broken backup.
 * 3. Records the run in backup_runs: success, or failed with the error.
 *    Failures also go to the Laravel log. Admin sees the last run.
 *
 * The database password goes to mysqldump in a temporary option file
 * (deleted right after), not on the command line where other programs on
 * the PC could see it.
 */
class BackupService
{
    public const FILE_PREFIX = 'patient_census_';

    private const FILE_PATTERN = '/^patient_census_(\d{4}-\d{2}-\d{2}_\d{6})\.sql$/';

    /** Name of the lock that keeps two backups from running together. */
    public const LOCK = 'backup-run';

    /**
     * Run a backup unless one is already running (from the scheduler, the
     * command or the Backups screen). Returns null when it is busy.
     */
    public function runIfIdle(): ?BackupRun
    {
        // Longer than the mysqldump timeout, so a crash frees the lock by itself.
        $lock = Cache::lock(self::LOCK, 3700);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->run();
        } finally {
            $lock->release();
        }
    }

    public function run(): BackupRun
    {
        $startedAt = CarbonImmutable::now();
        $folder = rtrim((string) config('census.backup.path'), '\\/');
        $fileName = self::FILE_PREFIX.$startedAt->format('Y-m-d_His').'.sql';
        $file = $folder.DIRECTORY_SEPARATOR.$fileName;
        $optionFile = null;

        try {
            if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
                throw new RuntimeException("The backup folder {$folder} does not exist and could not be created.");
            }

            $optionFile = $this->writeOptionFile();

            $result = Process::timeout(3600)
                ->env($this->windowsEnvironment())
                ->run($this->command($optionFile, $file));

            if ($result->failed()) {
                $error = trim($result->errorOutput());

                throw new RuntimeException($error !== '' ? $error : 'mysqldump stopped with exit code '.$result->exitCode().'.');
            }

            clearstatcache(true, $file);

            if (! is_file($file) || filesize($file) === 0) {
                throw new RuntimeException('mysqldump finished, but the backup file is missing or empty.');
            }

            $size = (int) filesize($file);
            $removed = $this->deleteOldBackups($folder);

            return $this->record($startedAt, BackupStatus::Success, $fileName, $size,
                $removed > 0 ? "Removed {$removed} backup(s) older than ".config('census.backup.keep_days').' days.' : null);
        } catch (Throwable $e) {
            if (is_file($file)) {
                @unlink($file); // never leave half a backup behind
            }

            Log::error('Backup failed: '.$e->getMessage(), ['folder' => $folder]);

            return $this->record($startedAt, BackupStatus::Failed, null, null, mb_substr($e->getMessage(), 0, 2000));
        } finally {
            if ($optionFile !== null && is_file($optionFile)) {
                @unlink($optionFile);
            }
        }
    }

    /**
     * On Windows, a program started from the web server (not the command
     * line) can come up without SystemRoot. mysqldump then cannot start
     * Winsock and stops with "Can't create TCP/IP socket (10106)". Pass it
     * on explicitly. Nothing is added on other systems.
     *
     * @return array<string, string>
     */
    private function windowsEnvironment(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [];
        }

        $root = (string) (getenv('SystemRoot') ?: getenv('windir') ?: ($_SERVER['SystemRoot'] ?? '') ?: 'C:\\Windows');

        return ['SystemRoot' => $root, 'windir' => $root];
    }

    /**
     * @return list<string>
     */
    private function command(string $optionFile, string $file): array
    {
        return [
            (string) config('census.backup.mysqldump_path'),
            '--defaults-extra-file='.$optionFile, // must come first
            '--single-transaction', // consistent copy without locking the tables
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces', // needs no extra MySQL privilege
            '--default-character-set=utf8mb4',
            '--result-file='.$file,
            (string) $this->connection()['database'],
        ];
    }

    /**
     * Temporary MySQL option file with the login, readable only by this user.
     */
    private function writeOptionFile(): string
    {
        $db = $this->connection();
        $quote = fn (mixed $value): string => '"'.addcslashes((string) $value, '"\\').'"';

        $path = tempnam(sys_get_temp_dir(), 'census-backup-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the database login.');
        }

        chmod($path, 0600);
        file_put_contents($path, implode(PHP_EOL, [
            '[client]',
            'host='.$quote($db['host'] ?? '127.0.0.1'),
            'port='.(int) ($db['port'] ?? 3306),
            'user='.$quote($db['username'] ?? ''),
            'password='.$quote($db['password'] ?? ''),
            '',
        ]));

        return $path;
    }

    /**
     * Delete this app's backup files older than keep_days, by the date in
     * the file name.
     */
    private function deleteOldBackups(string $folder): int
    {
        $limit = CarbonImmutable::now()->subDays((int) config('census.backup.keep_days'));
        $removed = 0;

        foreach (scandir($folder) ?: [] as $name) {
            if (! preg_match(self::FILE_PATTERN, $name, $match)) {
                continue;
            }

            $takenAt = CarbonImmutable::createFromFormat('Y-m-d_His', $match[1]);

            if ($takenAt !== null && $takenAt->lessThan($limit) && @unlink($folder.DIRECTORY_SEPARATOR.$name)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function record(CarbonImmutable $startedAt, BackupStatus $status, ?string $fileName, ?int $size, ?string $message): BackupRun
    {
        return BackupRun::create([
            'started_at' => $startedAt,
            'finished_at' => now(),
            'status' => $status,
            'file_name' => $fileName,
            'size_bytes' => $size,
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }
}
