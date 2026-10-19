<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Proof that Windows Task Scheduler is running "php artisan schedule:run".
 * The scheduler saves the time every minute (routes/console.php). When it
 * is older than 5 minutes, there is no automatic backup and no 8 PM
 * sign-out, so Admin sees a red bar on every page and the System health
 * page shows a Problem.
 */
class SchedulerHeartbeat
{
    public const KEY = 'scheduler.heartbeat';

    public function beat(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    public function lastBeat(): ?CarbonImmutable
    {
        try {
            $value = Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        // Shown on the System health page, so in the hospital's time zone, not UTC.
        return is_numeric($value) ? CarbonImmutable::createFromTimestamp((int) $value, config('app.timezone')) : null;
    }

    /** True when the scheduler never ran, or not within the last 5 minutes. */
    public function isStale(): bool
    {
        $last = $this->lastBeat();

        return $last === null || $last->lt(now()->subMinutes((int) config('census.scheduler_stale_minutes')));
    }

    /** The red bar text, or null when the scheduler is running. */
    public function alert(): ?string
    {
        return $this->isStale()
            ? 'Automatic tasks are not running (no backup, no 8 PM sign-out). Tell IT.'
            : null;
    }
}
