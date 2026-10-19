<?php

namespace App\Services;

use App\Enums\BackupMarkType;
use App\Enums\BackupStatus;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Models\TestRestoreRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * What Admin sees about backups: the last success, the red warning (the
 * last run failed, or no success for 26 hours) and the yellow reminders
 * (USB copy and test restore overdue). Reminders only: nothing is blocked.
 *
 * Used by the Backups screen and by the banner at the top of every Admin
 * page (HandleInertiaRequests).
 */
class BackupMonitor
{
    /** When the scheduler runs the daily backup (routes/console.php). */
    public const DAILY_AT = '02:00';

    /** When the scheduler runs the automatic test restore, on the 1st of each month. */
    public const TEST_RESTORE_AT = '03:00';

    /** No successful backup for this long is a warning. */
    public const STALE_HOURS = 26;

    /** Longest error text shown in a warning. */
    private const SHORT_ERROR = 120;

    public function latest(): ?BackupRun
    {
        return BackupRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    public function lastSuccess(): ?BackupRun
    {
        return BackupRun::query()
            ->where('status', BackupStatus::Success)
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->first();
    }

    public function lastMark(BackupMarkType $type): ?BackupMark
    {
        return BackupMark::query()
            ->with('user')
            ->where('type', $type)
            ->orderByDesc('marked_at')->orderByDesc('id')
            ->first();
    }

    /** The latest test restore, good or failed. */
    public function lastTestRestore(): ?TestRestoreRun
    {
        return TestRestoreRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    /** Who a mark is "by": a person, or the system for an automatic test restore. */
    public static function markedBy(?BackupMark $mark): ?string
    {
        if ($mark === null) {
            return null;
        }

        return $mark->marked_by === null ? 'System (automatic)' : $mark->user?->name;
    }

    /**
     * The red warning about the test restore, or null. It shows while the
     * latest test restore failed and clears when a later one passes.
     *
     * @return array{message: string, short: string}|null
     */
    public function testRestoreProblem(): ?array
    {
        $latest = $this->lastTestRestore();

        if ($latest === null || $latest->status !== BackupStatus::Failed) {
            return null;
        }

        $what = $latest->isAutomatic() ? 'The automatic test restore' : 'The test restore';
        $error = $this->shortError($latest->message);

        return [
            'message' => "{$what} failed on {$this->when($latest->started_at)}: {$error}. Tell IT / see the operations guide.",
            'short' => "{$what} failed. Tell IT.",
        ];
    }

    /** The next daily run: today at 02:00, or tomorrow once that has passed. */
    public function nextRun(): CarbonInterface
    {
        $next = now()->setTimeFromTimeString(self::DAILY_AT);

        return $next->isPast() ? $next->addDay() : $next;
    }

    /**
     * The red warning, or null when backups are fine. "message" is the full
     * text for the Backups screen, "short" the one for the banner.
     *
     * @return array{message: string, short: string}|null
     */
    public function problem(): ?array
    {
        $latest = $this->latest();

        if ($latest?->status === BackupStatus::Failed) {
            $error = $this->shortError($latest->message);

            return [
                'message' => "The last backup failed on {$this->when($latest->started_at)}: {$error}. Tell IT.",
                'short' => 'The last backup failed. Tell IT.',
            ];
        }

        $success = $this->lastSuccess();

        if ($success === null) {
            $text = 'No successful backup has been recorded yet. Tell IT.';

            return ['message' => $text, 'short' => $text];
        }

        if ($success->finished_at->lt(now()->subHours(self::STALE_HOURS))) {
            $text = "No successful backup since {$this->when($success->finished_at)}. Tell IT.";

            return ['message' => $text, 'short' => $text];
        }

        return null;
    }

    /**
     * Whether a manual step is overdue, and when it was last done.
     *
     * @return array{type: BackupMarkType, last: ?BackupMark, overdue: bool}
     */
    public function mark(BackupMarkType $type): array
    {
        $last = $this->lastMark($type);
        $overdue = $last === null || $last->marked_at->lt(now()->subDays($type->overdueAfterDays()));

        return ['type' => $type, 'last' => $last, 'overdue' => $overdue];
    }

    /**
     * The yellow reminders, in words.
     *
     * @return list<string>
     */
    public function reminders(): array
    {
        $messages = [];

        $usb = $this->mark(BackupMarkType::UsbCopy);

        if ($usb['overdue']) {
            $messages[] = 'The weekly USB copy is overdue (last: '.$this->day($usb['last']).'). Plug the USB into the server and click "Copy to USB" on Backups.';
        }

        $restore = $this->mark(BackupMarkType::TestRestore);

        if ($restore['overdue']) {
            $messages[] = 'The monthly test restore is overdue (last: '.$this->day($restore['last']).'). Open Backups and click "Run test restore now".';
        }

        return $messages;
    }

    /**
     * Everything for the banner on every Admin page.
     *
     * @return list<array{level: string, message: string}>
     */
    public function alerts(): array
    {
        $alerts = [];

        if ($problem = $this->problem()) {
            $alerts[] = ['level' => 'error', 'message' => $problem['short']];
        }

        if ($problem = $this->testRestoreProblem()) {
            $alerts[] = ['level' => 'error', 'message' => $problem['short']];
        }

        foreach ($this->reminders() as $message) {
            $alerts[] = ['level' => 'warning', 'message' => $message];
        }

        return $alerts;
    }

    /**
     * An error in one short line that is safe to show: first line only, no
     * database password or login, cut to 120 characters.
     */
    public function shortError(?string $message): string
    {
        $line = Str::of($this->scrub((string) $message))->trim()->before("\n")->squish()->rtrim('. ')->value();

        return $line === '' ? 'unknown error' : Str::limit($line, self::SHORT_ERROR);
    }

    /**
     * Hide the database password and login if mysqldump ever repeats them.
     */
    public function scrub(string $message): string
    {
        $db = (array) config('database.connections.'.config('database.default'));
        $password = (string) ($db['password'] ?? '');

        if ($password !== '') {
            $message = str_replace($password, '***', $message);
        }

        return (string) preg_replace("/'[^'\\s]*'@'[^'\\s]*'/", "'***'@'***'", $message);
    }

    /** "12.34 MB", for a file size. */
    public static function megabytes(?int $bytes): string
    {
        return Number::format(max(0.01, round(((int) $bytes) / 1048576, 2)), 2).' MB';
    }

    private function when(CarbonInterface $time): string
    {
        return $time->format('d-M-Y H:i');
    }

    private function day(?BackupMark $mark): string
    {
        return $mark === null ? 'never' : $mark->marked_at->format('d-M-Y');
    }
}
