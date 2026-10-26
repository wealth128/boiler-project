<?php

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Services\AuditPruner;
use Illuminate\Console\Command;

/**
 * php artisan census:prune-audit
 *
 * Archives and removes audit log entries older than one year. The scheduler
 * runs it daily at 04:30 (routes/console.php). See App\Services\AuditPruner.
 */
class PruneAuditCommand extends Command
{
    protected $signature = 'census:prune-audit';

    protected $description = 'Archive audit log entries older than 1 year to the backup folder, then remove them';

    public function handle(AuditPruner $pruner): int
    {
        $run = $pruner->runIfIdle();

        if ($run === null) {
            $this->warn('A backup or test restore is running. The clean-up will try again tomorrow.');

            return self::SUCCESS;
        }

        if ($run->status === BackupStatus::Failed) {
            $this->error('Audit log clean-up failed: '.$run->message);

            return self::FAILURE;
        }

        $this->info($run->rows_count === 0
            ? 'No audit entries are older than '.config('census.audit.retention_days').' days.'
            : "Archived and removed {$run->rows_count} entries ({$run->archive_name}).");

        return self::SUCCESS;
    }
}
