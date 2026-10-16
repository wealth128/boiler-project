<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesRootLogin;
use App\Enums\BackupStatus;
use App\Services\AuditLogger;
use App\Services\BackupMonitor;
use App\Services\BackupService;
use App\Services\Setup\AppMaintenance;
use App\Services\Setup\EnvFile;
use App\Services\Setup\Installation;
use App\Services\Setup\MysqlAdmin;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan census:update
 *
 * Run after copying the new version's files over the old ones (keep .env
 * and storage/). Backs up first and stops if that fails, puts the site in
 * maintenance mode, runs the migrations with the MySQL root login (asked
 * for, kept in memory only), rebuilds the caches, brings the site back and
 * writes the audit entry "system.updated".
 *
 * If the migrations fail, the site stays in maintenance mode on purpose and
 * the command says which backup to restore.
 */
class UpdateCommand extends Command
{
    use UsesRootLogin;

    protected $signature = 'census:update';

    protected $description = 'Update the system after copying new files: backup, maintenance mode, migrations, caches';

    public function handle(
        Installation $installation,
        BackupService $backups,
        BackupMonitor $monitor,
        MysqlAdmin $mysql,
        AppMaintenance $maintenance,
        AuditLogger $audit,
    ): int {
        if (! $installation->isInstalled()) {
            $this->error('The system is not installed yet. Run: php artisan census:install');

            return self::FAILURE;
        }

        // 1. A backup first. No backup, no update.
        $this->info('Backing up first...');
        set_time_limit(0);
        $run = $backups->runIfIdle();

        if ($run === null) {
            $this->error('A backup is already running. Try again in a few minutes. Nothing was changed.');

            return self::FAILURE;
        }

        if ($run->status === BackupStatus::Failed) {
            $this->error('The backup failed, so the update was stopped: '.$monitor->shortError($run->message));
            $this->line('Nothing was changed. Fix the backup first (see System health), then run this again.');

            return self::FAILURE;
        }

        $this->line("Backup saved: {$run->file_name} and {$run->zip_name}");

        // 2. Ask for the root login while the site is still up, so a wrong
        //    password never leaves the office PCs on the "back soon" page.
        if (! $this->loginAsRoot($mysql, (string) config('database.connections.'.config('database.default').'.database'))) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        // 3. Maintenance mode, migrations, caches, back up.
        $maintenance->down();
        $this->line('The system is now in maintenance mode.');

        try {
            $ran = $mysql->migrate();
            $this->line("Migrations done ({$ran} run).");

            $maintenance->clearCaches();
            $maintenance->rebuildCaches((new EnvFile((string) config('census.paths.env')))->keys());
            $this->line('Caches rebuilt.');
        } catch (Throwable $e) {
            $this->error('The update failed: '.$e->getMessage());
            $this->line('The system is STILL IN MAINTENANCE MODE. Office PCs cannot use it.');
            $this->newLine();
            $this->line('Next steps (tell IT):');
            $this->line('  1. Fix the problem above, then bring the site back with:');
            $this->line('       php artisan up');
            $this->line('  2. Or undo the update with the backup taken just before it:');
            $this->line("       php artisan census:restore {$run->file_name}");

            return self::FAILURE;
        }

        $this->auditSystem($audit, 'system.updated', [
            'label' => 'System updated to version '.config('census.version'),
            'version' => (string) config('census.version'),
            'migrations_run' => $ran,
            'backup' => $run->file_name,
        ]);

        $maintenance->up();

        $this->info('Update finished. Version '.config('census.version').' is running'.($ran === 0 ? ' (no database changes were needed).' : " ({$ran} database changes applied)."));

        return self::SUCCESS;
    }
}
