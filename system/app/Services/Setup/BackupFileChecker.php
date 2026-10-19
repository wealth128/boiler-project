<?php

namespace App\Services\Setup;

use ZipArchive;

/**
 * Checks a backup file before census:restore changes anything: the dump can
 * be read and is not cut off, and the picture zip opens. A cut-off dump is
 * what a full disk or a copy that was stopped leaves behind, and loading it
 * after the database was dropped would lose the live data for nothing.
 */
class BackupFileChecker
{
    /** The last line mysqldump writes when it finished ("-- Dump completed on ..."). */
    public const COMPLETE_MARKER = '-- Dump completed';

    /** How much of the end of the file is searched for the marker. */
    private const TAIL_BYTES = 4096;

    public const UNREADABLE = 'This backup file cannot be read. Choose another one.';

    public const INCOMPLETE = 'This backup file is incomplete. Choose another one.';

    public const BAD_ZIP = 'The picture zip of this backup cannot be opened. Choose another backup.';

    /** The reason to refuse the dump, or null when it is fine. */
    public function dumpProblem(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return self::UNREADABLE;
        }

        try {
            $size = (int) filesize($path);

            if ($size === 0 || fread($handle, 1) === false) {
                return $size === 0 ? self::INCOMPLETE : self::UNREADABLE;
            }

            fseek($handle, max(0, $size - self::TAIL_BYTES));
            $tail = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return str_contains($tail, self::COMPLETE_MARKER) ? null : self::INCOMPLETE;
    }

    /** The reason to refuse the picture zip, or null when it opens and is not damaged. */
    public function zipProblem(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            return self::BAD_ZIP;
        }

        $zip->close();

        return null;
    }
}
