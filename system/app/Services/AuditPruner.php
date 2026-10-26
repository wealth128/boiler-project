<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Models\AuditPruneRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Automatic audit log retention (php artisan census:prune-audit, daily 04:30).
 *
 * Entries older than census.audit.retention_days (365) are
 *  1. written FIRST to audit-archive_YYYY-MM-DD.csv.gz in the backup folder,
 *     and the file is read back and its rows counted;
 *  2. only then deleted, exactly those rows, 1,000 at a time in transactions;
 *  3. summed up in one "audit.pruned" audit entry (which itself is removed by
 *     this same rule one year later).
 *
 * If the archive cannot be written or checked, nothing is deleted and the run
 * is recorded as failed: Admin sees a red bar until a later run succeeds.
 *
 * This job is the ONLY way entries leave the audit log. There is no screen,
 * route or button for it. It uses the backup lock, so it never runs during a
 * backup, a test restore or a copy to USB.
 */
class AuditPruner
{
    public const CHUNK = 1000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BackupMonitor $monitor,
    ) {}

    /** The first moment still kept: older entries are archived and removed. */
    public static function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays((int) config('census.audit.retention_days'));
    }

    /**
     * Run unless a backup, test restore or USB copy holds the lock.
     * Returns null when busy (nothing is recorded; tomorrow's run tries again).
     */
    public function runIfIdle(): ?AuditPruneRun
    {
        $lock = Cache::lock(BackupService::LOCK, BackupService::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->run();
        } finally {
            $lock->release();
        }
    }

    public function run(): AuditPruneRun
    {
        $startedAt = CarbonImmutable::now();
        $cutoff = self::cutoff();
        $part = null;

        try {
            $total = DB::table('audit_logs')->where('created_at', '<', $cutoff)->count();

            if ($total === 0) {
                return $this->record($startedAt, BackupStatus::Success, 0, null, null);
            }

            $folder = rtrim((string) config('census.backup.path'), '\\/');

            if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
                throw new RuntimeException("The backup folder {$folder} does not exist and could not be created.");
            }

            $name = AuditArchive::nextName($folder, $startedAt);
            $part = $folder.DIRECTORY_SEPARATOR.$name.'.part';

            // 1. Archive first, then read the file back and count.
            $written = AuditArchive::write($part, $cutoff);

            if ($written !== $total) {
                throw new RuntimeException("The archive has {$written} rows but {$total} entries are due. Nothing was deleted.");
            }

            $counted = AuditArchive::countRows($part);

            if ($counted !== $written) {
                throw new RuntimeException("The archive check found {$counted} rows, expected {$written}. Nothing was deleted.");
            }

            if (! @rename($part, $folder.DIRECTORY_SEPARATOR.$name)) {
                throw new RuntimeException('The archive file could not be finished in the backup folder.');
            }

            $part = null;

            // 2. Only now delete exactly those rows.
            $range = DB::table('audit_logs')->where('created_at', '<', $cutoff)->selectRaw('min(created_at) as oldest, max(created_at) as newest')->first();
            $deleted = $this->delete($cutoff);

            $run = $this->record($startedAt, BackupStatus::Success, $deleted, $name, null);

            // 3. One entry about it: only counts, dates and the file name.
            $this->audit->log('audit.pruned', $run, [
                'label' => 'Audit log clean-up',
                'count' => $deleted,
                'oldest' => CarbonImmutable::parse((string) $range->oldest)->toDateString(),
                'newest' => CarbonImmutable::parse((string) $range->newest)->toDateString(),
                'archive_file' => $name,
            ]);

            return $run;
        } catch (Throwable $e) {
            if ($part !== null && is_file($part)) {
                @unlink($part);
            }

            $error = RedactSecretsInLogs::redact(mb_substr($e->getMessage(), 0, 2000));
            Log::error('Audit log clean-up failed: '.$error);

            return $this->record($startedAt, BackupStatus::Failed, 0, null, $error);
        }
    }

    /**
     * Delete the old entries in chunks of 1,000, each chunk in its own
     * transaction. The model refuses delete() on purpose, so this uses the
     * query builder, and only here.
     */
    private function delete(CarbonImmutable $cutoff): int
    {
        $deleted = 0;

        do {
            $ids = DB::table('audit_logs')->where('created_at', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids !== []) {
                $deleted += DB::transaction(fn (): int => DB::table('audit_logs')->whereIn('id', $ids)->delete());
            }
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    /** The red Admin bar: the latest run failed. Null once a later run succeeds. */
    public function alert(): ?string
    {
        $latest = $this->latest();

        return $latest?->status === BackupStatus::Failed
            ? 'Audit log clean-up failed: '.$this->monitor->shortError($latest->message)
            : null;
    }

    public function latest(): ?AuditPruneRun
    {
        return AuditPruneRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    private function record(CarbonImmutable $startedAt, BackupStatus $status, int $rows, ?string $archive, ?string $message): AuditPruneRun
    {
        return AuditPruneRun::create([
            'started_at' => $startedAt,
            'finished_at' => now(),
            'status' => $status,
            'rows_count' => $rows,
            'archive_name' => $archive,
            'message' => $message,
        ]);
    }
}
