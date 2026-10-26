<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The archive files of the audit log clean-up: audit-archive_YYYY-MM-DD.csv.gz
 * in the backup folder (UTF-8, one row per entry, all columns, the details as
 * JSON). They are small and are kept: the 30-day backup cleanup never deletes
 * them, and Copy to USB copies the newest one.
 */
final class AuditArchive
{
    public const PATTERN = '/^audit-archive_\d{4}-\d{2}-\d{2}(_\d+)?\.csv\.gz$/';

    public const COLUMNS = ['id', 'created_at', 'user_id', 'action', 'subject_type', 'subject_id', 'ip_address', 'details'];

    /** A name not used yet, so an earlier archive of the same day is never overwritten. */
    public static function nextName(string $folder, CarbonImmutable $day): string
    {
        $base = 'audit-archive_'.$day->format('Y-m-d');
        $name = $base.'.csv.gz';

        for ($n = 2; is_file($folder.DIRECTORY_SEPARATOR.$name); $n++) {
            $name = "{$base}_{$n}.csv.gz";
        }

        return $name;
    }

    /** The newest archive file name in the folder, or null. */
    public static function newest(string $folder): ?string
    {
        $newest = null;
        $time = -1;

        foreach (is_dir($folder) ? (scandir($folder) ?: []) : [] as $name) {
            if (preg_match(self::PATTERN, $name) !== 1) {
                continue;
            }

            $modified = (int) @filemtime($folder.DIRECTORY_SEPARATOR.$name);

            if ($modified > $time || ($modified === $time && $newest !== null && strcmp($name, $newest) > 0)) {
                $newest = $name;
                $time = $modified;
            }
        }

        return $newest;
    }

    /**
     * Write every entry older than the cutoff to the file. Returns the number of rows.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public static function write(string $path, CarbonImmutable $cutoff): int
    {
        $gz = @gzopen($path, 'wb9');

        if ($gz === false) {
            throw new RuntimeException('The archive file could not be created in the backup folder.');
        }

        $rows = 0;

        try {
            self::put($gz, self::COLUMNS);

            DB::table('audit_logs')->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(1000, function ($chunk) use ($gz, &$rows): void {
                foreach ($chunk as $row) {
                    self::put($gz, [
                        $row->id,
                        $row->created_at,
                        $row->user_id,
                        $row->action,
                        $row->subject_type,
                        $row->subject_id,
                        $row->ip_address,
                        $row->changes,
                    ]);
                    $rows++;
                }
            });
        } finally {
            if (! @gzclose($gz)) {
                throw new RuntimeException('The archive file could not be finished (is the disk full?).');
            }
        }

        return $rows;
    }

    /** Rows in an archive, without the header. Reads the file back, so a short write shows. */
    public static function countRows(string $path): int
    {
        $gz = @gzopen($path, 'rb');

        if ($gz === false) {
            throw new RuntimeException('The archive file could not be read back.');
        }

        $rows = -1; // the header
        $buffer = '';

        try {
            while (($line = gzgets($gz)) !== false) {
                $buffer .= $line;

                // A row is complete when the quotes in it are balanced (details can hold line breaks).
                if (substr_count($buffer, '"') % 2 === 0) {
                    $rows++;
                    $buffer = '';
                }
            }
        } finally {
            gzclose($gz);
        }

        if ($buffer !== '') {
            throw new RuntimeException('The archive file ends in the middle of a row.');
        }

        return max($rows, 0);
    }

    /**
     * Text that starts with = + - @ (or a tab / return) is read as a formula by
     * Excel: a quote goes in front so it stays text.
     */
    public static function safe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    /**
     * @param  resource  $gz
     * @param  list<mixed>  $values
     */
    private static function put($gz, array $values): void
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_map(self::safe(...), $values), ',', '"', '');
        rewind($stream);
        $line = (string) stream_get_contents($stream);
        fclose($stream);

        if (gzwrite($gz, $line) === false) {
            throw new RuntimeException('Writing to the archive file failed (is the disk full?).');
        }
    }
}
