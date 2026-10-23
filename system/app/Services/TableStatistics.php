<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Asks MySQL to refresh its statistics about the tables the reports read
 * most (ANALYZE TABLE). After a restore or a big import MySQL's picture of
 * those tables can be out of date for a while, and the first report then
 * runs a very slow plan (minutes on a year of data). Refreshing right away
 * means the first report afterwards is as fast as any other.
 *
 * It never stops what called it: if MySQL refuses (no right to analyze, a
 * different database), the report is still correct, only that first one may
 * be slow, so the failure is only logged.
 */
class TableStatistics
{
    /** The tables the reports read the most (the statement in refresh() names the same two). */
    public const TABLES = ['visits', 'patients'];

    public function refresh(): void
    {
        // ANALYZE TABLE ends any open transaction (MySQL commits it first), so it never runs inside one.
        // The restore command and a confirmed import call this after their own work is saved, outside any
        // transaction; only a caller that is itself inside one (a test that wraps everything) skips it.
        if (DB::transactionLevel() > 0) {
            return;
        }

        try {
            // A fixed statement (the names are TABLES, written out): nothing typed by anyone goes into the SQL.
            DB::select('ANALYZE TABLE `visits`, `patients`');
        } catch (Throwable $e) {
            report(new \RuntimeException('ANALYZE TABLE failed: '.$e::class));
        }
    }
}
