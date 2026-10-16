<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesRootLogin;
use App\Enums\BackupStatus;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\BackupMonitor;
use App\Services\BackupService;
use App\Services\FolderZipper;
use App\Services\Setup\AppMaintenance;
use App\Services\Setup\EnvFile;
use App\Services\Setup\Installation;
use App\Services\Setup\MysqlAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Throwable;

/**
 * php artisan census:restore {file}
 *
 * Puts a backup back (planning/deployment-handover.md, section 5). The file
 * must be one of this system's backup files inside BACKUP_PATH. Steps:
 * show its date and size, ask to type RESTORE, take a safety backup of the
 * current data, maintenance mode, ask for the MySQL root password (memory
 * only), load the dump into patient_census, unzip the matching pictures,
 * migrate, rebuild the caches, sign everyone out, bring the site back, print
 * record counts, write the audit entry "system.restored".
 *
 * Anything encoded after the backup was taken is gone: it must be
 * re-encoded from paper. The safety backup is the way back if this was a
 * mistake. If loading fails, the site stays in maintenance mode on purpose.
 */
class RestoreCommand extends Command
{
    use UsesRootLogin;

    protected $signature = 'census:restore {file : A backup file (patient_census_YYYY-MM-DD_HHMMSS.sql) in BACKUP_PATH}';

    protected $description = 'Restore the database and pictures from a backup file in BACKUP_PATH';

    public function handle(
        Installation $installation,
        BackupService $backups,
        BackupMonitor $monitor,
        FolderZipper $zipper,
        MysqlAdmin $mysql,
        AppMaintenance $maintenance,
        AuditLogger $audit,
    ): int {
        if (! $installation->isInstalled()) {
            $this->error('The system is not installed yet. Run: php artisan census:install (a restore needs an installed system).');

            return self::FAILURE;
        }

        // a. Check the file, show what it is.
        $dump = $this->resolveBackup((string) $this->argument('file'));

        if ($dump === null) {
            return self::FAILURE;
        }

        $stamp = $this->stampOf($dump);
        $zip = dirname($dump).DIRECTORY_SEPARATOR.(BackupService::zipNameFor(basename($dump)) ?? '');
        $hasZip = is_file($zip);

        $this->line('Backup file : '.basename($dump));
        $this->line('Taken       : '.$stamp->format('d-M-Y H:i'));
        $this->line('Size        : '.Number::fileSize((int) filesize($dump)));
        $this->line('Pictures    : '.($hasZip ? basename($zip).' ('.Number::fileSize((int) filesize($zip)).')' : 'no picture zip found'));
        $this->newLine();
        $this->warn('Everything encoded after this backup was taken will be gone. It must be re-encoded from paper.');

        if ($this->ask('Type RESTORE to continue') !== 'RESTORE') {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        // The root login is asked while the site is still up.
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! $this->loginAsRoot($mysql)) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        // b. Safety backup of what is there now. It never deletes old files:
        //    the one being restored must survive.
        $this->info('Taking a safety backup of the current data...');
        set_time_limit(0);
        $safety = $backups->runIfIdle(prune: false);

        if ($safety === null) {
            $this->error('A backup is already running. Try again in a few minutes. Nothing was changed.');

            return self::FAILURE;
        }

        if ($safety->status === BackupStatus::Failed) {
            $this->error('The safety backup failed, so the restore was stopped: '.$monitor->shortError($safety->message));
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        $this->line("Safety backup: {$safety->file_name} (keep it until you are sure the restore is right)");

        $maintenance->down();
        $this->line('The system is now in maintenance mode.');

        // c, d. Load the data, put the pictures back, bring the structure up to date.
        try {
            $mysql->recreateDatabase($database);
            $mysql->importDump($dump, $database);
            $this->line('The database is loaded.');

            if ($hasZip) {
                $count = $zipper->unzip($zip, (string) config('census.backup.files_path'));
                $this->line("Pictures restored ({$count} files).");
            } else {
                $this->warn('There is no matching picture zip, so the logo and signature pictures were NOT restored. Add them again in Hospital settings.');
            }

            $ran = $mysql->migrate();
            $this->line($ran > 0 ? "The backup was from an older version: {$ran} database changes applied." : 'The database structure is up to date.');

            $maintenance->clearCaches();
            $maintenance->rebuildCaches((new EnvFile((string) config('census.paths.env')))->keys());
            $this->line('Caches rebuilt.');
        } catch (Throwable $e) {
            $this->error('The restore failed: '.$e->getMessage());
            $this->line('The system is STILL IN MAINTENANCE MODE. Office PCs cannot use it.');
            $this->newLine();
            $this->line('Next steps (tell IT):');
            $this->line('  1. Fix the problem above, then bring the site back with:');
            $this->line('       php artisan up');
            $this->line('  2. Or undo the restore with the backup taken just before it:');
            $this->line("       php artisan census:restore {$safety->file_name}");

            return self::FAILURE;
        }

        // Old sessions came back with the data: everyone logs in again.
        $mysql->refreshAppConnection();
        DB::table('sessions')->delete();

        $this->auditSystem($audit, 'system.restored', [
            'label' => 'System restored from '.basename($dump),
            'backup' => basename($dump),
            'taken' => $stamp->format('Y-m-d H:i'),
            'pictures_restored' => $hasZip,
            'safety_backup' => $safety->file_name,
        ]);

        $maintenance->up();

        // e. What came back.
        $lastVisit = Visit::query()->max('visited_at');

        $this->newLine();
        $this->info('Restore finished. Everyone must log in again.');
        $this->table(['Records', 'Count'], [
            ['Patients', Patient::query()->count()],
            ['Visits', Visit::query()->count()],
            ['Users', User::query()->count()],
        ]);
        $this->line('Last encoded visit: '.($lastVisit === null ? 'none' : CarbonImmutable::parse($lastVisit)->format('d-M-Y H:i')));
        $this->line('Check that these match what you expect before letting users back in.');

        return self::SUCCESS;
    }

    /**
     * The backup file to restore, or null (after saying why). Only a file
     * inside BACKUP_PATH, named like a backup this system made.
     */
    private function resolveBackup(string $argument): ?string
    {
        $folder = realpath((string) config('census.backup.path'));

        if ($folder === false) {
            $this->error('The backup folder could not be found. Check BACKUP_PATH in .env.');

            return null;
        }

        $name = basename(str_replace('\\', '/', $argument));

        if (preg_match(BackupService::FILE_PATTERN, $name) !== 1) {
            $this->error('That is not a backup file name. It must look like patient_census_2026-10-09_020000.sql.');

            return null;
        }

        $path = realpath($argument) ?: realpath($folder.DIRECTORY_SEPARATOR.$argument);

        if ($path === false || ! is_file($path)) {
            $this->error('That backup file was not found.');

            return null;
        }

        if (dirname($path) !== $folder) {
            $this->error('Only files inside the backup folder (BACKUP_PATH) can be restored.');

            return null;
        }

        return $path;
    }

    private function stampOf(string $dump): CarbonImmutable
    {
        preg_match(BackupService::FILE_PATTERN, basename($dump), $match);

        return CarbonImmutable::createFromFormat('Y-m-d_His', $match[1]) ?: CarbonImmutable::now();
    }
}
