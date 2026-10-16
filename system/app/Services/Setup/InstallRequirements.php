<?php

namespace App\Services\Setup;

use App\Services\HealthChecker;

/**
 * What the server needs before census:install can start. Each line is OK
 * or FIX with one plain line on how to fix it. MySQL being reachable is
 * checked by the command itself (MysqlAdmin::reachable).
 *
 * The protected methods at the bottom let tests replace the PC's real
 * PHP settings.
 */
class InstallRequirements
{
    public const MIN_PHP = '8.3.0';

    /** @var list<string> */
    public const EXTENSIONS = ['gd', 'intl', 'zip', 'mbstring', 'pdo_mysql', 'fileinfo', 'openssl'];

    private const MIN_UPLOAD_BYTES = 2 * 1024 ** 2;

    /**
     * @return list<array{label: string, ok: bool, fix: string}>
     */
    public function results(): array
    {
        $results = [];

        $results[] = [
            'label' => 'PHP 8.3 or newer (this is '.$this->phpVersion().')',
            'ok' => version_compare($this->phpVersion(), self::MIN_PHP, '>='),
            'fix' => 'Install PHP 8.3 or newer in Laragon (Menu > PHP > Version), then try again.',
        ];

        foreach (self::EXTENSIONS as $extension) {
            $results[] = [
                'label' => "PHP extension {$extension}",
                'ok' => $this->extensionLoaded($extension),
                'fix' => "Turn on the {$extension} extension (Laragon: Menu > PHP > Extensions), then try again.",
            ];
        }

        foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
            $results[] = [
                'label' => 'Folder '.basename(dirname($path)).'/'.basename($path).' is writable',
                'ok' => is_dir($path) && is_writable($path),
                'fix' => 'Give the Windows account that runs the server write access to this folder, then try again.',
            ];
        }

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $value = $this->ini($key);

            $results[] = [
                'label' => "{$key} is at least 2M (now {$value})",
                'ok' => HealthChecker::iniBytes($value) >= self::MIN_UPLOAD_BYTES,
                'fix' => "Set {$key} to 2M or more in php.ini, restart the web server, then try again.",
            ];
        }

        return $results;
    }

    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    protected function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    protected function ini(string $key): string
    {
        return (string) ini_get($key);
    }
}
