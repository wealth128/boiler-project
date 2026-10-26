<?php

namespace App\Services;

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Services\Setup\MysqlAdmin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
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
            $this->speed(),
            $this->storage(),
            $this->uploadLimits(),
            $this->php(),
            $this->mysqlClient(),
            $this->mysqlRoot(),
            $this->testRestore(),
            $this->usbCopy(),
            $this->auditCleanup(),
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

    /**
     * Problems first, then Warnings, then OK. The order inside each group is
     * the order the checks run in.
     *
     * @template T of array{status: string}
     *
     * @param  list<T>  $checks
     * @return list<T>
     */
    public static function sortBySeverity(array $checks): array
    {
        $rank = [self::PROBLEM => 0, self::WARNING => 1, self::OK => 2];

        return collect($checks)
            ->values()
            ->sortBy(fn (array $check, int $index): array => [$rank[$check['status']] ?? 3, $index])
            ->values()
            ->all();
    }

    /**
     * How many checks are in each state, for "2 problems, 1 warning" at the
     * top of the page.
     *
     * @param  list<array{status: string}>  $checks
     * @return array{problem: int, warning: int, ok: int, total: int}
     */
    public static function counts(array $checks): array
    {
        $by = collect($checks)->countBy('status');

        return [
            'problem' => $by[self::PROBLEM] ?? 0,
            'warning' => $by[self::WARNING] ?? 0,
            'ok' => $by[self::OK] ?? 0,
            'total' => count($checks),
        ];
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

    /**
     * The speed settings of docs/operations-guide.md ("Speed settings"):
     * OPcache on, the config, route and event caches built and the log not
     * at debug level. Missing ones are a Warning: everything still works,
     * only slower. A public/hot file is a Problem: the pages would load their
     * scripts from a developer's Vite server, which does not run on the server.
     *
     * @return array{key: string, label: string, status: string, detail: string, todo: string}
     */
    private function speed(): array
    {
        $label = 'Speed settings';
        ['problems' => $problems, 'warnings' => $warnings] = $this->speedFindings();

        if ($problems !== []) {
            return $this->row('speed', $label, self::PROBLEM, implode(' ', $problems), 'Tell IT: delete the file public\\hot in the system folder (it is only for developers), then reload the page.');
        }

        if ($warnings !== []) {
            return $this->row('speed', $label, self::WARNING, implode(' ', $warnings), 'Tell IT: see "Speed settings" in the operations guide (OPcache in php.ini, then "php artisan optimize").');
        }

        return $this->row('speed', $label, self::OK, 'OPcache on, caches built, log level '.$this->logLevel().'.', 'Nothing to do.');
    }

    /**
     * @return array{problems: list<string>, warnings: list<string>}
     */
    protected function speedFindings(): array
    {
        $warnings = [];

        if (! $this->opcacheOn()) {
            $warnings[] = 'OPcache is off.';
        }

        if ($missing = $this->missingCaches()) {
            $warnings[] = 'The '.implode(', ', $missing).' cache is not built.';
        }

        if ($this->logLevel() === 'debug') {
            $warnings[] = 'The log level is debug.';
        }

        return [
            'problems' => $this->viteHotFile() ? ['The Vite development file public/hot is present.'] : [],
            'warnings' => $warnings,
        ];
    }

    /** OPcache is loaded and switched on for the web server (php.ini opcache.enable). */
    protected function opcacheOn(): bool
    {
        return extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> the caches "php artisan optimize" builds that are missing */
    protected function missingCaches(): array
    {
        return array_keys(array_filter([
            'config' => ! app()->configurationIsCached(),
            'route' => ! app()->routesAreCached(),
            'event' => ! app()->eventsAreCached(),
        ]));
    }

    /** Laravel's own test: with public/hot the pages load their scripts from a Vite dev server. */
    protected function viteHotFile(): bool
    {
        return Vite::isRunningHot();
    }

    /** The lowest level written by the default log channel (or the channels of its stack). */
    private function logLevel(): string
    {
        $default = (string) config('logging.default');
        $channels = config("logging.channels.{$default}.driver") === 'stack'
            ? (array) config("logging.channels.{$default}.channels")
            : [$default];

        $levels = array_map(fn ($channel): string => strtolower((string) config("logging.channels.{$channel}.level", 'debug')), $channels);

        return in_array('debug', $levels, true) ? 'debug' : (string) ($levels[0] ?? 'debug');
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

    /**
     * The monthly automatic test restore: OK when one passed in the last 35
     * days, Problem when the latest one failed, Warning when none passed in time.
     *
     * @return array{key: string, label: string, status: string, detail: string, todo: string}
     */
    private function testRestore(): array
    {
        $label = 'Test restore of backups';
        $latest = $this->safely(fn () => $this->backups->lastTestRestore());

        if ($latest?->status === BackupStatus::Failed) {
            $why = $this->backups->shortError($latest->message);

            return $this->row('test_restore', $label, self::PROBLEM,
                'The last test restore failed on '.$latest->started_at->format('d-M-Y H:i').': '.$why.'.',
                'Tell IT: see the operations guide, then open Backups and click "Run test restore now".');
        }

        $mark = $this->safely(fn () => $this->backups->mark(BackupMarkType::TestRestore));

        if ($mark === null || $mark['overdue']) {
            $last = $mark['last'] ?? null;

            return $this->row('test_restore', $label, self::WARNING,
                $last === null ? 'No test restore has been done yet.' : 'The last good test restore was on '.$last->marked_at->format('d-M-Y').', more than '.BackupMarkType::TestRestore->overdueAfterDays().' days ago.',
                'Open Backups and click "Run test restore now". It also runs by itself on the 1st of each month.');
        }

        return $this->row('test_restore', $label, self::OK, 'Last good test restore: '.$mark['last']->marked_at->format('d-M-Y').'.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function usbCopy(): array
    {
        $label = 'Copy to USB';
        $mark = $this->safely(fn () => $this->backups->mark(BackupMarkType::UsbCopy));

        if ($mark === null || $mark['overdue']) {
            $last = $mark['last'] ?? null;

            return $this->row('usb_copy', $label, self::WARNING,
                $last === null ? 'No backup has been copied to a USB drive yet.' : 'The last copy to USB was on '.$last->marked_at->format('d-M-Y').', more than '.BackupMarkType::UsbCopy->overdueAfterDays().' days ago.',
                'Plug the USB drive into the server PC, open Backups and click "Copy to USB".');
        }

        return $this->row('usb_copy', $label, self::OK, 'Last copy to USB: '.$mark['last']->marked_at->format('d-M-Y').'.', 'Nothing to do.');
    }

    /** @return array{key: string, label: string, status: string, detail: string, todo: string} */
    private function auditCleanup(): array
    {
        $label = 'Audit log clean-up';
        $last = $this->safely(fn () => app(AuditPruner::class)->latest());

        if ($last === null) {
            return $this->row('audit_cleanup', $label, self::WARNING, 'The clean-up of old audit entries has not run yet. It runs every day at 04:30.', 'Nothing to do yet. If this stays after tomorrow, check the Automatic tasks line.');
        }

        if ($last->status === BackupStatus::Failed) {
            return $this->row('audit_cleanup', $label, self::PROBLEM, 'The last clean-up failed on '.$last->started_at->format('d-M-Y H:i').': '.$this->backups->shortError($last->message).'. Old entries stay in the audit log until it works.', 'Tell IT: check that the backup folder is reachable and has free space. The clean-up tries again at 04:30.');
        }

        if ($last->started_at->lt(now()->subDays(2))) {
            return $this->row('audit_cleanup', $label, self::WARNING, 'The last clean-up ran on '.$last->started_at->format('d-M-Y H:i').', more than 2 days ago.', 'Tell IT: check that no backup is stuck and the scheduler is running.');
        }

        return $this->row('audit_cleanup', $label, self::OK, 'Last ran on '.$last->started_at->format('d-M-Y H:i').'.', 'Nothing to do.');
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

    /**
     * Runs "mysql --version". Starting the program takes about 0.2 seconds, so
     * the System health page remembers the answer for 10 minutes for the same
     * program path (a new MYSQL_PATH is asked at once). census:health always runs it.
     */
    protected function mysqlClientWorks(): bool
    {
        $mysql = app(MysqlAdmin::class);

        if (app()->runningInConsole()) {
            return $mysql->clientWorks();
        }

        return Cache::remember('health.mysql-client:'.md5($mysql->mysqlClientPath()), 600, fn (): bool => $mysql->clientWorks());
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
