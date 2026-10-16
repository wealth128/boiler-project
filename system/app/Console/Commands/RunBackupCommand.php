<?php

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * php artisan backup:run
 *
 * Backs up the database now. The scheduler runs it daily at 02:00
 * (routes/console.php). See App\Services\BackupService.
 */
class RunBackupCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Back up the database to BACKUP_PATH and remove backups older than 30 days';

    public function handle(BackupService $backups): int
    {
        $run = $backups->runIfIdle();

        if ($run === null) {
            $this->error('A backup is already running.');

            return self::FAILURE;
        }

        if ($run->status === BackupStatus::Failed) {
            $this->error('Backup failed: '.$run->message);

            return self::FAILURE;
        }

        $this->info("Backup saved: {$run->file_name} (".Number::fileSize((int) $run->size_bytes).')');

        if ($run->message) {
            $this->line($run->message);
        }

        return self::SUCCESS;
    }
}
