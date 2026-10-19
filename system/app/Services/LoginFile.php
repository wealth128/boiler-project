<?php

namespace App\Services;

use RuntimeException;

/**
 * The temporary MySQL option file that carries a login to mysqldump or mysql,
 * so the password is never on the command line where other programs on the PC
 * could see it. Readable by this user only, and deleted by the caller right
 * after use.
 *
 * The name is always "census-login-<random>.cnf". That fixed pattern is what
 * lets cleanLeftovers() delete the files a crashed run left behind without
 * ever touching anything else in the temp folder. (PHP's tempnam() cannot be
 * used: on Windows it keeps only the first 3 letters of the prefix.)
 */
final class LoginFile
{
    public const PREFIX = 'census-login-';

    public const EXTENSION = '.cnf';

    /** A leftover file older than this is deleted. */
    public const STALE_SECONDS = 3600;

    /**
     * Write a [client] option file and return its path. Leftover files from
     * earlier runs are cleaned first, so every run starts with a tidy folder.
     *
     * @param  array{host: string, port: int, user: string, password: string}  $login
     */
    public static function create(string $folder, array $login): string
    {
        self::cleanLeftovers($folder);

        $path = rtrim($folder, '\\/').DIRECTORY_SEPARATOR.self::PREFIX.bin2hex(random_bytes(8)).self::EXTENSION;
        $handle = @fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException('Could not create a temporary file for the database login.');
        }

        fclose($handle);
        chmod($path, 0600);

        $quote = fn (mixed $value): string => '"'.addcslashes((string) $value, '"\\').'"';

        file_put_contents($path, implode(PHP_EOL, [
            '[client]',
            'host='.$quote($login['host']),
            'port='.(int) $login['port'],
            'user='.$quote($login['user']),
            'password='.$quote($login['password']),
            '',
        ]));

        return $path;
    }

    /**
     * Delete login files of this app that are older than an hour. Only names
     * that match the pattern above are looked at.
     *
     * @return int How many were deleted.
     */
    public static function cleanLeftovers(string $folder): int
    {
        $limit = time() - self::STALE_SECONDS;
        $deleted = 0;

        foreach (glob(rtrim($folder, '\\/').DIRECTORY_SEPARATOR.self::PREFIX.'*'.self::EXTENSION) ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) < $limit && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
