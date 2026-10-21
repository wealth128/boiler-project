<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Models\BackupRun;
use App\Services\Import\ImportFileStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Daily database backup (planning/architecture.md, section 4).
 *
 * 1. Runs mysqldump (MYSQLDUMP_PATH) into BACKUP_PATH as
 *    patient_census_YYYY-MM-DD_HHMMSS.sql.
 * 2. Zips the picture files (storage/app/private: logo, signatures, frozen
 *    signature folders) next to it, with the same date stamp:
 *    patient_census_files_YYYY-MM-DD_HHMMSS.zip.
 *    A run is a success only if BOTH files were written.
 * 3. After a successful backup, deletes this app's backup files (dumps and
 *    zips) older than 30 days from that folder. Other files are never
 *    touched, and nothing is deleted after a failed run, so old copies
 *    survive a broken backup.
 * 4. Records the run in backup_runs: success (with the patient, visit, user and
 *    closed-month counts, for the test restore), or failed with the error.
 *    Failures also go to the Laravel log. Admin sees the last run.
 *
 * The database password goes to mysqldump in a temporary option file
 * (deleted right after), not on the command line where other programs on
 * the PC could see it.
 */
class BackupService
{
    public const FILE_PREFIX = 'patient_census_';

    public const FILE_PATTERN = '/^patient_census_(\d{4}-\d{2}-\d{2}_\d{6})\.sql$/';

    public const ZIP_PATTERN = '/^patient_census_files_(\d{4}-\d{2}-\d{2}_\d{6})\.zip$/';

    /** Name of the lock that keeps two backups from running together. */
    public const LOCK = 'backup-run';

    /** The longest the mysqldump or mysql program may run (backup and test restore). */
    public const PROCESS_TIMEOUT = 3600;

    /**
     * How long the lock is held before it frees itself after a crash. The
     * backup, the test restore and the USB copy all use it, so it must outlast
     * the longest of them: a program run (PROCESS_TIMEOUT) plus counting,
     * zipping or unzipping and a safety margin. Twice the timeout leaves an
     * hour for everything else. A longer lock only matters after a crash.
     */
    public const LOCK_SECONDS = 2 * self::PROCESS_TIMEOUT;

    /**
     * Run a backup unless one is already running (from the scheduler, the
     * command or the Backups screen). Returns null when it is busy.
     */
    public function runIfIdle(bool $prune = true): ?BackupRun
    {
        // Longer than the longest run (see LOCK_SECONDS), so a crash frees the lock by itself.
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->run($prune);
        } finally {
            $lock->release();
        }
    }

    public function __construct(private readonly FolderZipper $zipper) {}

    /** The picture zip that belongs to a database dump, by the shared date stamp. */
    public static function zipNameFor(string $sqlName): ?string
    {
        return preg_match(self::FILE_PATTERN, $sqlName, $match) === 1 ? 'patient_census_files_'.$match[1].'.zip' : null;
    }

    /**
     * @param  bool  $prune  Delete backups older than 30 days after a success.
     *                       census:restore passes false for its safety backup, so
     *                       cleaning up can never delete the file being restored.
     */
    public function run(bool $prune = true): BackupRun
    {
        $startedAt = CarbonImmutable::now();
        $folder = rtrim((string) config('census.backup.path'), '\\/');
        $stamp = $startedAt->format('Y-m-d_His');
        $fileName = self::FILE_PREFIX.$stamp.'.sql';
        $zipName = 'patient_census_files_'.$stamp.'.zip';
        $file = $folder.DIRECTORY_SEPARATOR.$fileName;
        $zipFile = $folder.DIRECTORY_SEPARATOR.$zipName;
        $optionFile = null;

        try {
            if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
                throw new RuntimeException("The backup folder {$folder} does not exist and could not be created.");
            }

            $optionFile = $this->writeOptionFile();

            // Counted just before and just after the dump. mysqldump reads one
            // snapshot taken somewhere in between, so a visit encoded meanwhile
            // can only move a count between these two numbers.
            $before = BackupCounts::forConnection(DB::connection());

            $result = Process::timeout(self::PROCESS_TIMEOUT)
                ->env(self::windowsEnvironment())
                ->run($this->command($optionFile, $file));

            if ($result->failed()) {
                $error = trim($result->errorOutput());

                throw new RuntimeException($error !== '' ? $error : 'mysqldump stopped with exit code '.$result->exitCode().'.');
            }

            $counts = BackupCounts::range($before, BackupCounts::forConnection(DB::connection()));

            clearstatcache(true, $file);

            if (! is_file($file) || filesize($file) === 0) {
                throw new RuntimeException('mysqldump finished, but the backup file is missing or empty.');
            }

            $size = (int) filesize($file);

            // The backup folder itself and the files waiting for an import are left out
            // (an uploaded import file holds patient data and lives at most an hour).
            $this->zipper->zip((string) config('census.backup.files_path'), $zipFile, [$folder, ImportFileStore::path()]);

            clearstatcache(true, $zipFile);

            if (! is_file($zipFile) || filesize($zipFile) === 0) {
                throw new RuntimeException('The picture backup (zip) is missing or empty.');
            }

            $zipSize = (int) filesize($zipFile);
            $removed = $prune ? $this->deleteOldBackups($folder) : 0;

            return $this->record($startedAt, BackupStatus::Success, $fileName, $size,
                $removed > 0 ? "Removed {$removed} old backup file(s) older than ".config('census.backup.keep_days').' days.' : null,
                $zipName, $zipSize, $counts);
        } catch (Throwable $e) {
            // Never leave half a backup behind: both files go, or neither stays.
            foreach ([$file, $zipFile] as $partial) {
                if (is_file($partial)) {
                    @unlink($partial);
                }
            }

            // The error text is saved and shown to Admin: no password or key, even if the program repeated one.
            $error = RedactSecretsInLogs::redact(mb_substr($e->getMessage(), 0, 2000));

            Log::error('Backup failed: '.$error, ['folder' => $folder]);

            return $this->record($startedAt, BackupStatus::Failed, null, null, $error);
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
    public static function windowsEnvironment(): array
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
     * Temporary MySQL option file with the login, readable only by this user
     * (see LoginFile, which also removes leftovers of crashed runs).
     */
    private function writeOptionFile(): string
    {
        $db = $this->connection();

        return LoginFile::create(sys_get_temp_dir(), [
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => (int) ($db['port'] ?? 3306),
            'user' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
        ]);
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
            if (! preg_match(self::FILE_PATTERN, $name, $match) && ! preg_match(self::ZIP_PATTERN, $name, $match)) {
                continue;
            }

            $takenAt = CarbonImmutable::createFromFormat('Y-m-d_His', $match[1]);

            if ($takenAt !== null && $takenAt->lessThan($limit) && @unlink($folder.DIRECTORY_SEPARATOR.$name)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function record(CarbonImmutable $startedAt, BackupStatus $status, ?string $fileName, ?int $size, ?string $message, ?string $zipName = null, ?int $zipSize = null, ?array $counts = null): BackupRun
    {
        return BackupRun::create([
            'started_at' => $startedAt,
            'finished_at' => now(),
            'status' => $status,
            'file_name' => $fileName,
            'size_bytes' => $size,
            'zip_name' => $zipName,
            'zip_size_bytes' => $zipSize,
            'patients_count' => $counts['patients'][0] ?? null,
            'patients_count_max' => $counts['patients'][1] ?? null,
            'visits_count' => $counts['visits'][0] ?? null,
            'visits_count_max' => $counts['visits'][1] ?? null,
            'users_count' => $counts['users'][0] ?? null,
            'users_count_max' => $counts['users'][1] ?? null,
            'closed_months_count' => $counts['closed_months'][0] ?? null,
            'closed_months_count_max' => $counts['closed_months'][1] ?? null,
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
