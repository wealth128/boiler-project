<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesRootLogin;
use App\Services\AuditLogger;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * php artisan census:rebuild-snapshots
 *
 * After a future report change (a new ReportBuilder::VERSION), the saved
 * copies of closed months, quarters and years are still in the old shape and
 * are shown through a slower path. This counts every one of them again with
 * the current version. The signature block of each period (names, titles and
 * picture copies frozen when it was closed) is kept as it was. Asks to type
 * REBUILD, and writes the audit entry "snapshots.rebuilt" with the count.
 * For Admin or IT; run it on the server. Not needed on a fresh install.
 */
class RebuildSnapshotsCommand extends Command
{
    use UsesRootLogin;

    protected $signature = 'census:rebuild-snapshots';

    protected $description = 'Count the saved copy of every closed month, quarter and year again with the current report version';

    public function handle(MonthService $months, AuditLogger $audit): int
    {
        [$total, $old] = $months->snapshotCounts();

        if ($total === 0) {
            $this->line('There are no saved copies yet (no month is closed). Nothing to rebuild.');

            return self::SUCCESS;
        }

        $this->line("Saved copies: {$total} (closed months, quarters and years). In an older report version: {$old}.");
        $this->warn('Every saved copy will be counted again from the visits. The signatures kept with each period stay as they were.');

        if ($this->ask('Type REBUILD to continue') !== 'REBUILD') {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $count = $months->rebuildSnapshots();
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first() ?? 'A month is being closed or reopened right now. Try again in a minute.');
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        $this->auditSystem($audit, 'snapshots.rebuilt', [
            'label' => "Saved report copies rebuilt ({$count})",
            'count' => $count,
            'version' => ReportBuilder::VERSION,
        ]);

        $this->info("Rebuilt {$count} saved copies at report version ".ReportBuilder::VERSION.'.');

        return self::SUCCESS;
    }
}
