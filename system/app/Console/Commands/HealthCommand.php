<?php

namespace App\Console\Commands;

use App\Services\HealthChecker;
use Illuminate\Console\Command;

/**
 * php artisan census:health
 *
 * The same checks as the System health page. Ends with exit code 1 if any
 * check is a Problem, so a scheduled task or a script can notice. Shows no
 * passwords, .env contents or connection details.
 */
class HealthCommand extends Command
{
    protected $signature = 'census:health';

    protected $description = 'Check that the system is healthy (the same checks as the System health page)';

    public function handle(HealthChecker $health): int
    {
        $checks = $health->checks();

        foreach ($checks as $check) {
            $tag = match ($check['status']) {
                HealthChecker::OK => 'OK     ',
                HealthChecker::WARNING => 'WARNING',
                default => 'PROBLEM',
            };

            $line = "{$tag}  {$check['label']}: {$check['detail']}";

            match ($check['status']) {
                HealthChecker::OK => $this->info($line),
                HealthChecker::WARNING => $this->warn($line),
                default => $this->error($line),
            };

            if ($check['status'] !== HealthChecker::OK) {
                $this->line("         What to do: {$check['todo']}");
            }
        }

        if (HealthChecker::hasProblem($checks)) {
            $this->newLine();
            $this->error('There is at least one problem. Fix it, then run this again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
