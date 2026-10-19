<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Database\ConnectionInterface;

/**
 * The four record counts a backup run remembers and the test restore checks
 * against the restored copy: patients, visits, users and closed months.
 * Rows in Trash count too, because the dump holds them too.
 *
 * Counts are taken before and after the dump and saved as a range, because
 * mysqldump reads one snapshot somewhere in between and a visit encoded in
 * that time must not make a good backup fail its test.
 *
 * One function reads both the live database (at backup time) and the
 * scratch database (at test restore time), so the two can never count
 * differently.
 */
final class BackupCounts
{
    /** Names of the counts, with the words used in messages. */
    public const LABELS = [
        'patients' => 'patients',
        'visits' => 'visits',
        'users' => 'users',
        'closed_months' => 'closed months',
    ];

    /**
     * @return array{patients: int, visits: int, users: int, closed_months: int}
     */
    public static function forConnection(ConnectionInterface $connection): array
    {
        return [
            'patients' => $connection->table('patients')->count(),
            'visits' => $connection->table('visits')->count(),
            'users' => $connection->table('users')->count(),
            'closed_months' => $connection->table('month_closures')->where('is_closed', true)->count(),
        ];
    }

    /**
     * Two countings (before and after the dump) as one range per item:
     * [lowest, highest]. When both agree, low and high are the same number.
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @return array<string, array{0: int, 1: int}>
     */
    public static function range(array $before, array $after): array
    {
        $range = [];

        foreach (array_keys(self::LABELS) as $key) {
            $range[$key] = [min($before[$key], $after[$key]), max($before[$key], $after[$key])];
        }

        return $range;
    }

    /**
     * The range saved on a backup run, per item, or null for a run made
     * before the counts were kept. A run saved before ranges existed has no
     * maximum: its single number is both ends.
     *
     * @return array<string, array{0: int, 1: int}>|null
     */
    public static function fromRun(BackupRun $run): ?array
    {
        $range = [];

        foreach (array_keys(self::LABELS) as $key) {
            $low = $run->{$key.'_count'};
            $high = $run->{$key.'_count_max'};

            if ($low === null) {
                return null;
            }

            $range[$key] = [(int) $low, (int) ($high ?? $low)];
        }

        return $range;
    }

    /** "40" or "40 to 42", for a message. */
    public static function describe(array $range): string
    {
        return $range[0] === $range[1] ? (string) $range[0] : $range[0].' to '.$range[1];
    }
}
