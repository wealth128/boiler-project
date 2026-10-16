<?php

namespace App\Services\Setup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Maintenance mode and the caches, for the setup commands.
 *
 * Rebuilding the caches runs in a new "php artisan" process, so it reads
 * the .env file as it is now. (The running command may still hold the old
 * values in memory, and Laravel never overwrites values that are already
 * loaded.) Tests replace this class with a fake.
 */
class AppMaintenance
{
    /** php artisan down: office PCs see the "back soon" page. */
    public function down(): void
    {
        $this->artisan('down');
    }

    /** php artisan up. */
    public function up(): void
    {
        $this->artisan('up');
    }

    public function clearCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            $this->artisan($command);
        }
    }

    /**
     * config:cache, route:cache and view:cache in a fresh process.
     *
     * @param  list<string>  $envKeys  Names in .env. They are removed from the new
     *                                 process's environment so it reads the file.
     */
    public function rebuildCaches(array $envKeys = []): void
    {
        $environment = array_fill_keys($envKeys, false);

        foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
            $result = Process::path(base_path())
                ->timeout(300)
                ->env($environment)
                ->run([PHP_BINARY, 'artisan', $command, '--no-interaction']);

            if ($result->failed()) {
                $line = trim(strtok(trim($result->output()."\n".$result->errorOutput()), "\n") ?: '');

                throw new RuntimeException("php artisan {$command} failed: {$line}");
            }
        }
    }

    private function artisan(string $command): void
    {
        if (Artisan::call($command) !== 0) {
            throw new RuntimeException("php artisan {$command} failed: ".trim(Artisan::output()));
        }
    }
}
