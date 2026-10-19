<?php

namespace App\Services;

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Models\TestRestoreRun;
use App\Models\User;
use App\Services\Setup\BackupFileChecker;
use App\Services\Setup\MysqlAdmin;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The test restore (planning/architecture.md section 4). It proves the
 * latest backup can really be loaded:
 *
 * 1. checks the dump is complete and the picture zip opens,
 * 2. loads the dump into the scratch database patient_census_restore_test
 *    (the live database is never touched),
 * 3. compares the patient, visit, user and closed-month counts with the range
 *    (lowest to highest around the dump) the backup run saved,
 * 4. opens the picture zip in a temporary folder,
 * 5. ALWAYS drops the scratch database and deletes the temporary folder,
 *    also when anything above failed.
 *
 * A pass records the "Test restore" mark (by "System (automatic)" or by the
 * Admin who clicked), which clears the 35-day reminder. A failure is kept in
 * test_restore_runs and shows as a red bar until a later test passes.
 *
 * It uses the same lock as the backup, so a test and a backup never run
 * together.
 */
class TestRestoreService
{
    public function __construct(
        private readonly BackupMonitor $monitor,
        private readonly BackupFileChecker $checker,
        private readonly FolderZipper $zipper,
        private readonly MysqlAdmin $mysql,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Run a test restore unless a backup or another test is running (then
     * null). $by is the Admin who clicked, or null for the scheduler.
     *
     * @param  'automatic'|'manual'  $trigger
     */
    public function runIfIdle(string $trigger, ?User $by = null): ?TestRestoreRun
    {
        // Longer than the longest run (BackupService::LOCK_SECONDS), so a crash frees it by itself.
        $lock = Cache::lock(BackupService::LOCK, BackupService::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->run($trigger, $by);
        } finally {
            $lock->release();
        }
    }

    /** Record a test that could not even start (for example a backup was still running). */
    public function recordFailure(string $trigger, string $reason, ?User $by = null): TestRestoreRun
    {
        return $this->finish($trigger, $by, now(), null, $reason);
    }

    private function run(string $trigger, ?User $by): TestRestoreRun
    {
        $startedAt = now();
        $backup = $this->monitor->lastSuccess();
        $database = MysqlAdmin::SCRATCH_DATABASE;
        $temp = null;
        $touched = false;
        $error = null;

        try {
            if ($backup === null) {
                throw new RuntimeException('There is no successful backup to test.');
            }

            [$dump, $zip] = $this->filesOf($backup);

            if (! $this->mysql->scratchConfigured()) {
                throw new RuntimeException('The test restore account is not set up. Tell IT to run "php artisan census:install --repair".');
            }

            $expected = BackupCounts::fromRun($backup)
                ?? throw new RuntimeException('The latest backup has no saved record counts (it was made by an older version). Run a new backup, then test again.');

            $this->check($dump, $zip);

            $touched = true;
            $this->mysql->resetScratchDatabase($database);
            $this->mysql->loadScratchDump($dump, $database);

            $this->compare($expected, $this->mysql->scratchCounts($database));

            $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'census-restore-test-'.bin2hex(random_bytes(6));
            $this->zipper->unzip($zip, $temp);
        } catch (Throwable $e) {
            $error = $this->clean($e->getMessage());
            Log::error('Test restore failed: '.$error);
        } finally {
            // The scratch database and the temp folder never stay behind.
            if ($touched) {
                try {
                    $this->mysql->dropScratchDatabase($database);
                } catch (Throwable $e) {
                    Log::error('The scratch database could not be dropped: '.$this->clean($e->getMessage()));
                    $error ??= 'The scratch database could not be dropped: '.$this->clean($e->getMessage());
                }
            }

            if ($temp !== null && is_dir($temp)) {
                File::deleteDirectory($temp);
            }
        }

        return $this->finish($trigger, $by, $startedAt, $backup, $error);
    }

    /**
     * The dump and zip of a backup run, as paths in the backup folder.
     *
     * @return array{0: string, 1: string}
     */
    private function filesOf(BackupRun $backup): array
    {
        $folder = rtrim((string) config('census.backup.path'), '\\/');
        $dump = $folder.DIRECTORY_SEPARATOR.$backup->file_name;
        $zip = $folder.DIRECTORY_SEPARATOR.($backup->zip_name ?? '');

        if (! is_file($dump)) {
            throw new RuntimeException('The backup file '.$backup->file_name.' is missing from the backup folder.');
        }

        if ($backup->zip_name === null || ! is_file($zip)) {
            throw new RuntimeException('The picture zip of the latest backup is missing from the backup folder.');
        }

        return [$dump, $zip];
    }

    private function check(string $dump, string $zip): void
    {
        $problem = $this->checker->dumpProblem($dump) ?? $this->checker->zipProblem($zip);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }

    /**
     * Each restored count must lie in the range the backup saw: its lowest
     * and highest count around the dump.
     *
     * @param  array<string, array{0: int, 1: int}>  $expected
     * @param  array<string, int>  $found
     */
    private function compare(array $expected, array $found): void
    {
        $differences = [];

        foreach (BackupCounts::LABELS as $key => $label) {
            $value = $found[$key] ?? null;

            if ($value === null || $value < $expected[$key][0] || $value > $expected[$key][1]) {
                $differences[] = "{$label}: backup ".BackupCounts::describe($expected[$key]).', restored '.($value ?? 'none');
            }
        }

        if ($differences !== []) {
            throw new RuntimeException('The restored copy does not match the backup ('.implode('; ', $differences).').');
        }
    }

    private function finish(string $trigger, ?User $by, CarbonInterface $startedAt, ?BackupRun $backup, ?string $error): TestRestoreRun
    {
        $run = TestRestoreRun::create([
            'trigger' => $trigger,
            'status' => $error === null ? BackupStatus::Success : BackupStatus::Failed,
            'backup_run_id' => $backup?->id,
            'started_by' => $by?->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'message' => $error === null ? null : mb_substr($error, 0, 2000),
        ]);

        if ($error === null) {
            BackupMark::create([
                'type' => BackupMarkType::TestRestore,
                'marked_by' => $by?->id,
                'marked_at' => now(),
            ]);
        }

        $this->audit->log('backup.test_restore', $run, array_filter([
            'label' => 'Test restore ('.$trigger.')',
            'trigger' => $trigger,
            'result' => $error === null ? 'success' : 'failed',
            'backup' => $backup?->file_name,
            'error' => $error === null ? null : $this->monitor->shortError($error),
        ]), $by);

        return $run;
    }

    /** No database password or login in a stored message. */
    private function clean(string $message): string
    {
        $password = (string) config('census.restore_test.password');

        if ($password !== '') {
            $message = str_replace($password, '***', $message);
        }

        return $this->monitor->scrub($message);
    }
}
