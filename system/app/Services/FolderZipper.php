<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Zips a folder and unzips it again, for the picture files that every
 * backup keeps next to the database dump (BackupService) and that
 * census:restore puts back. Uses PHP's zip extension: no package.
 *
 * Tests replace this class with one that fails, to prove that a backup
 * only counts as a success when both files were written.
 */
class FolderZipper
{
    /** Always inside the zip, so a folder with no pictures still makes a valid file. */
    public const MANIFEST = 'backup-info.txt';

    /**
     * @param  list<string>  $skip  Folders inside $source to leave out (like the backup folder).
     * @return int Number of files zipped, not counting the info file.
     */
    public function zip(string $source, string $zipPath, array $skip = []): int
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("The picture backup {$zipPath} could not be created.");
        }

        $count = 0;
        $root = is_dir($source) ? (string) realpath($source) : '';
        $skip = array_filter(array_map(fn (string $path) => realpath($path) ?: null, $skip));

        if ($root !== '') {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($files as $file) {
                $path = $file->getPathname();

                if (! $file->isFile() || $this->isInside($path, $skip)) {
                    continue;
                }

                $name = str_replace('\\', '/', substr($path, strlen($root) + 1));

                if (! $zip->addFile($path, $name)) {
                    $zip->close();
                    throw new RuntimeException("Could not add {$name} to the picture backup.");
                }

                $count++;
            }
        }

        $zip->addFromString(self::MANIFEST, 'Picture files of the Patient Census system. Files: '.$count.'. Taken: '.now()->toDateTimeString().PHP_EOL);

        if (! $zip->close() || ! is_file($zipPath)) {
            throw new RuntimeException('The picture backup could not be written to the backup folder.');
        }

        return $count;
    }

    /**
     * Unzip into $target. Entries that try to leave the folder ("../x") are skipped.
     *
     * @return int Number of files restored.
     */
    public function unzip(string $zipPath, string $target): int
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The picture backup is not a valid zip file.');
        }

        if (! is_dir($target) && ! @mkdir($target, 0755, true) && ! is_dir($target)) {
            $zip->close();
            throw new RuntimeException('The pictures folder could not be created.');
        }

        $safe = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === self::MANIFEST || str_ends_with($name, '/') || ! $this->isSafeName($name)) {
                continue;
            }

            $safe[] = $name;
        }

        if ($safe !== [] && ! $zip->extractTo($target, $safe)) {
            $zip->close();
            throw new RuntimeException('The pictures could not be extracted.');
        }

        $zip->close();

        return count($safe);
    }

    private function isSafeName(string $name): bool
    {
        $name = str_replace('\\', '/', $name);

        return $name !== ''
            && ! str_starts_with($name, '/')
            && ! preg_match('/^[A-Za-z]:/', $name)
            && ! in_array('..', explode('/', $name), true);
    }

    /** @param  array<int, string>  $folders */
    private function isInside(string $path, array $folders): bool
    {
        foreach ($folders as $folder) {
            if (str_starts_with($path, $folder.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
