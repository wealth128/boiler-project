<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The warning bars at the top of every Admin page: the backup problems and
 * reminders (BackupMonitor), a failed audit log clean-up (AuditPruner) and the
 * stopped scheduler (SchedulerHeartbeat). Working them out takes several
 * queries and runs on every page, so the result is remembered for 60 seconds
 * in one cache entry (one small query per page instead of seven).
 *
 * Every change that can turn a bar on or off forgets it at once, so the next
 * page shows the new state: a backup run, a test restore, a USB copy, a manual
 * mark, an audit clean-up run (models with ClearsAdminAlerts) and the
 * scheduler coming back (SchedulerHeartbeat::beat). The bars that appear only
 * because time passed (no backup for 26 hours, a reminder overdue, the
 * scheduler silent for 5 minutes) show within 60 seconds.
 */
class AdminAlerts
{
    public const KEY = 'admin-alerts';

    public const SECONDS = 60;

    public function __construct(
        private readonly BackupMonitor $backups,
        private readonly AuditPruner $pruner,
        private readonly SchedulerHeartbeat $heartbeat,
    ) {}

    /**
     * @return array{alerts: list<array{level: string, message: string}>, scheduler: string|null}
     */
    public function current(): array
    {
        return Cache::remember(self::KEY, self::SECONDS, fn (): array => $this->fresh());
    }

    /**
     * Worked out now, without the cache (the System health page and tests).
     *
     * @return array{alerts: list<array{level: string, message: string}>, scheduler: string|null}
     */
    public function fresh(): array
    {
        $alerts = $this->backups->alerts();

        if ($message = $this->pruner->alert()) {
            $alerts[] = ['level' => 'error', 'message' => $message];
        }

        return ['alerts' => $alerts, 'scheduler' => $this->heartbeat->alert()];
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
