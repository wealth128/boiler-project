<?php

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Services\BackupMonitor;
use App\Services\Setup\MysqlAdmin;
use App\Services\TestRestoreService;
use Illuminate\Console\Command;

/**
 * php artisan backup:test-restore
 *
 * The automatic monthly test restore. The scheduler runs it on the 1st at
 * 03:00 (routes/console.php). See App\Services\TestRestoreService.
 *
 * The scratch database name is fixed. --database exists only so that the
 * command can refuse any other name: it never accepts one.
 */
class TestRestoreCommand extends Command
{
    protected $signature = 'backup:test-restore {--database= : Only '.MysqlAdmin::SCRATCH_DATABASE.' is accepted}';

    protected $description = 'Load the latest backup into the scratch database '.MysqlAdmin::SCRATCH_DATABASE.' and check the counts';

    public function handle(TestRestoreService $service, BackupMonitor $monitor): int
    {
        $name = $this->option('database');

        if ($name !== null && $name !== MysqlAdmin::SCRATCH_DATABASE) {
            $this->error('The test restore only uses the database '.MysqlAdmin::SCRATCH_DATABASE.'. It refuses "'.$name.'".');

            return self::FAILURE;
        }

        set_time_limit(0);

        $run = $service->runIfIdle('automatic');

        if ($run === null) {
            // The red bar must not stay silent when the 03:00 slot was taken.
            $run = $service->recordFailure('automatic', 'A backup was still running at the scheduled time. It will be tested next month, or click "Run test restore now".');
        }

        if ($run->status === BackupStatus::Failed) {
            $this->error('Test restore failed: '.$monitor->shortError($run->message));

            return self::FAILURE;
        }

        $this->info('Test restore passed: the latest backup loads and the counts match.');

        return self::SUCCESS;
    }
}
