<?php

namespace App\Services;

use App\Services\Setup\MysqlAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Throwable;

/**
 * The System health checks, shown on the System health page (Admin) and by
 * php artisan census:health. Each check is OK, Warning or Problem and says
 * in one plain line what to do.
 *
 * Nothing here returns a password, the .env contents or connection
 * details: only yes/no results, sizes and times.
 *
 * The small protected methods at the bottom (database ping, writable
 * folders, disk space, php.ini values, PHP version) exist so tests can
 * replace them.
 */
class HealthChecker
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const PROBLEM = 'problem';

    public const MIN_PHP = '8.4.1';

    /** Free disk space: a warning below 10 GB, a problem below 2 GB. */
    private const WARN_BYTES = 10 * 1024 ** 3;

    private const PROBLEM_BYTES = 2 * 1024 ** 3;

    private const MIN_UPLOAD_BYTES = 2 * 1024 ** 2;

    public function __construct(
        private readonly BackupMonitor $backups,
        private readonly SchedulerHeartbeat $heartbeat,
    ) {}

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, todo: string}>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->lastBackup(),
            $this->pictureBackup(),
            $this->backupFolder(),
            $this->serverDisk(),
            $this->scheduler(),
            $this->environment(),
            $this->storage(),
            $this->uploadLimits(),
            $this->php(),
            $this->mysqlClient(),
            $this->mysqlRoot(),
            $this->version(),
        ];
    }

    /** @param  list<array{status: string}>  $checks */
    public static function hasProblem(array $checks): bool
    {
        return collect($checks)->contains(fn (array $check): bool => $check['status'] === self::PROBLEM);
    }

    /**
     * The worst result of all checks, for the summary at the top of the page.
     *
     * @param  list<array{status: string}>  $checks
     */
    public static function overall(array $checks): string
    {
        if (self::hasProblem($checks)) {
            return self::PROBLEM;
        }

        return collect($checks)->contains(fn (array $check): bool => $check['status'] === self::WARNING)
            ? self::WARNING
            : self::OK;
    }

    /** "2M", "512K", "1G" or plain bytes, as written in php.ini. -1 means no limit. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function database(): array
    {
        try {
            $this->pingDatabase();

            return $this->row('database', 'Database', self::OK, 'Connected.', 'Nothing to do.');
        } catch (Throwable) {
            return $this->row('database', 'Database', self::PROBLEM, 'The system cannot reach the database.', 'Tell IT: check that MySQL is running on the server.');
        }
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function lastBackup(): array
    {
        $success = $this->safely(fn () => $this->backups->lastSuccess());

        if ($success === null) {
            return $this->row('last_backup', 'Last backup', self::PROBLEM, 'No successful backup has been recorded.', 'Open Backups and click "Run backup now". If it fails, tell IT.');
        }

        if ($success->finished_at->lt(now()->subHours(BackupMonitor::STALE_HOURS))) {
            return $this->row('last_backup', 'Last backup', self::PROBLEM,
                'The last good backup was on '.$success->finished_at->format('d-M-Y H:i').', more than '.BackupMonitor::STALE_HOURS.' hours ago.',
                'Open Backups and click "Run backup now". If it fails, tell IT.');
        }

        return $this->row('last_backup', 'Last backup', self::OK, 'Last good backup: '.$success->finished_at->format('d-M-Y H:i').'.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function pictureBackup(): array
    {
        $label = 'Backup of pictures';
        $success = $this->safely(fn () => $this->backups->lastSuccess());

        if ($success === null) {
            return $this->row('picture_backup', $label, self::WARNING, 'No backup to check yet.', 'Run a backup first.');
        }

        if ($success->zip_name === null) {
            return $this->row('picture_backup', $label, self::WARNING, 'The last backup has no picture zip (it was made by an older version).', 'Run a new backup now.');
        }

        $folder = rtrim((string) config('census.backup.path'), '\\/');

        if (! is_file($folder.DIRECTORY_SEPARATOR.$success->zip_name)) {
            return $this->row('picture_backup', $label, self::PROBLEM, 'The picture zip of the last backup is missing from the backup folder.', 'Tell IT: someone removed it. Run a new backup now.');
        }

        return $this->row('picture_backup', $label, self::OK, 'The picture zip of the last backup is in the backup folder.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function backupFolder(): array
    {
        $folder = rtrim((string) config('census.backup.path'), '\\/');
        $label = 'Backup folder';

        if (! is_dir($folder) || ! $this->isWritable($folder)) {
            return $this->row('backup_folder', $label, self::PROBLEM, 'The backup folder cannot be reached or written to.', 'Tell IT: check that the backup PC is on and the shared folder is available.');
        }

        return $this->diskRow('backup_folder', $label, $this->freeSpace($folder), 'Tell IT: free some space on the backup PC.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function serverDisk(): array
    {
        return $this->diskRow('server_disk', 'Server disk space', $this->freeSpace(base_path()), 'Tell IT: free some space on the server disk.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function diskRow(string $key, string $label, ?int $free, string $todo): array
    {
        if ($free === null) {
            return $this->row($key, $label, self::WARNING, 'The free space could not be read.', 'Tell IT to check the disk.');
        }

        $text = Number::fileSize($free).' free.';

        if ($free < self::PROBLEM_BYTES) {
            return $this->row($key, $label, self::PROBLEM, $text, $todo);
        }

        if ($free < self::WARN_BYTES) {
            return $this->row($key, $label, self::WARNING, $text, $todo);
        }

        return $this->row($key, $label, self::OK, $text, 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function scheduler(): array
    {
        $label = 'Automatic tasks (scheduler)';
        $last = $this->heartbeat->lastBeat();

        if ($last === null) {
            return $this->row('scheduler', $label, self::PROBLEM, 'The scheduler has never run.', 'Tell IT: set up the Windows Task Scheduler task that runs "php artisan schedule:run" every minute.');
        }

        if ($this->heartbeat->isStale()) {
            return $this->row('scheduler', $label, self::PROBLEM, 'The scheduler last ran on '.$last->format('d-M-Y H:i').'. There is no automatic backup or 8 PM sign-out until it runs.', 'Tell IT: check the Windows Task Scheduler task (it runs every minute).');
        }

        return $this->row('scheduler', $label, self::OK, 'Ran at '.$last->format('H:i').'.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function environment(): array
    {
        $label = 'Safe settings';
        $what = [];

        if (! app()->environment('production')) {
            $what[] = 'APP_ENV is not "production"';
        }

        if (config('app.debug')) {
            $what[] = 'APP_DEBUG is on';
        }

        if ($what !== []) {
            return $this->row('environment', $label, self::PROBLEM, implode(' and ', $what).'.', 'Tell IT: set APP_ENV=production and APP_DEBUG=false in the server settings file (.env), then rebuild the caches.');
        }

        return $this->row('environment', $label, self::OK, 'Production mode, debug off.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function storage(): array
    {
        foreach ([storage_path(), storage_path('logs'), storage_path('framework'), base_path('bootstrap/cache')] as $path) {
            if (! is_dir($path) || ! $this->isWritable($path)) {
                return $this->row('storage', 'Storage folders', self::PROBLEM, 'The system cannot write to its storage folders.', 'Tell IT: give the server account write access to the storage and bootstrap/cache folders.');
            }
        }

        return $this->row('storage', 'Storage folders', self::OK, 'Writable.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function uploadLimits(): array
    {
        $upload = self::iniBytes($this->ini('upload_max_filesize'));
        $post = self::iniBytes($this->ini('post_max_size'));

        if ($upload < self::MIN_UPLOAD_BYTES || $post < self::MIN_UPLOAD_BYTES) {
            return $this->row('upload_limits', 'Upload limits', self::PROBLEM, 'The upload limit is under 2 MB, so the logo and signature pictures cannot be uploaded.', 'Tell IT: set upload_max_filesize and post_max_size to at least 2M in php.ini, then restart the web server.');
        }

        return $this->row('upload_limits', 'Upload limits', self::OK, 'At least 2 MB.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function php(): array
    {
        $version = $this->phpVersion();

        if (version_compare($version, self::MIN_PHP, '<')) {
            return $this->row('php', 'PHP version', self::PROBLEM, "PHP {$version} is too old.", 'Tell IT: install PHP 8.4.1 or newer.');
        }

        return $this->row('php', 'PHP version', self::OK, "PHP {$version}.", 'Nothing to do.');
    }

    /**
     * The mysql program that census:restore uses to load a backup. Only a
     * Warning when it is missing: backups and everything else still work, and
     * the restore would just ask for its path.
     *
     * @return array{key: string, label: string, status: string, detail: string, todo: string}
     */
    private function mysqlClient(): array
    {
        $label = 'MySQL program (for restore)';

        if (! $this->mysqlClientWorks()) {
            return $this->row('mysql_client', $label, self::WARNING, 'The mysql program was not found, or it does not start.', 'Tell IT: set MYSQL_PATH in the server settings file (.env) to the full path of mysql.exe, then run "php artisan census:install --repair".');
        }

        return $this->row('mysql_client', $label, self::OK, 'Found.', 'Nothing to do.');
    }

    /**
     * MySQL root must have a password. The web app only holds the census_app login,
     * so it cannot read the account list. It tries one root login with an empty
     * password instead, which stores nothing.
     *
     * @return array{key: string, label: string, status: string, detail: string, todo: string}
     */
    private function mysqlRoot(): array
    {
        $label = 'MySQL root password';

        if ($this->safely(fn () => $this->rootHasNoPassword()) === true) {
            return $this->row('mysql_root', $label, self::PROBLEM, 'MySQL lets root in with no password, so anyone on this PC can open the database.', 'Tell IT: set a MySQL root password of at least 12 characters and write it in the sealed envelope (docs/requirements.md, "MySQL root password").');
        }

        return $this->row('mysql_root', $label, self::OK, 'Root needs a password.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function version(): array
    {
        return $this->row('version', 'System version', self::OK, (string) config('census.version').'.', 'Nothing to do.');
    }

    /** Throws when the database cannot be reached. */
    protected function pingDatabase(): void
    {
        DB::connection()->getPdo();
        DB::select('select 1');
    }

    /** One root login with an empty password. */
    protected function rootHasNoPassword(): bool
    {
        return app(MysqlAdmin::class)->rootHasNoPassword();
    }

    /** Runs "mysql --version". */
    protected function mysqlClientWorks(): bool
    {
        return app(MysqlAdmin::class)->clientWorks();
    }

    protected function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    protected function freeSpace(string $path): ?int
    {
        $free = @disk_free_space($path);

        return $free === false ? null : (int) $free;
    }

    protected function ini(string $key): string
    {
        return (string) ini_get($key);
    }

    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function row(string $key, string $label, string $status, string $detail, string $todo): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail, 'todo' => $todo];
    }

    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
