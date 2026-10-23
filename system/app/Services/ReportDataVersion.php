<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * One number that says "the data a report is made from has changed".
 *
 * It lives in one row of the settings table (key report_data_version). Reading
 * it is one primary-key query. It goes up by one on every create, update, move
 * to Trash, restore and permanent delete of a visit, patient, branch, rank,
 * diagnosis, age range, month closure, saved report copy or import batch (the
 * BumpsReportVersion trait on those models), and on the places that change
 * these tables without model events (a whole month to Trash, saved copies
 * deleted in one query, a restore of a backup). ReportCache puts the number in
 * its key, so any change means the next report is counted again.
 *
 * The increase runs inside the same transaction as the change, so a change
 * that is rolled back leaves the number as it was.
 */
final class ReportDataVersion
{
    public const KEY = 'report_data_version';

    /** The current number, as text. "0" until the first change. */
    public static function current(): string
    {
        $value = DB::table('settings')->where('key', self::KEY)->value('value');

        return $value === null ? '0' : (string) $value;
    }

    /** Go up by one. Creates the row on the first change. */
    public static function bump(): void
    {
        DB::statement(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = CAST(`value` AS UNSIGNED) + 1',
            [self::KEY, '1'],
        );
    }
}
