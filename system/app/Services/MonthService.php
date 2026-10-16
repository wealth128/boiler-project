<?php

namespace App\Services;

use App\Models\MonthClosure;
use App\Models\ReportSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Close and reopen a month (Admin only, Gate "close-month").
 *
 * - Closing locks the month (no edits or deletes of its visits, see
 *   VisitPolicy and TrashPolicy) and saves a ReportSnapshot, so the
 *   submitted numbers never change.
 * - The snapshot also keeps the signature block as it was at close (names,
 *   titles and copies of the signature pictures, see FrozenSignatures).
 * - When closing a month makes every month of its quarter (or year) closed,
 *   a snapshot of that quarter (or year) is saved too, with its own frozen
 *   signature block. Quarterly and annual reports use it, so a final
 *   quarter or year never changes either. Snapshots of a quarter are
 *   keyed "2026-Q3" and of a year "2026" in the same table (the period
 *   column is 7 characters wide).
 * - Reopening unlocks the month and removes its snapshot with its frozen
 *   signatures, and the snapshots of its quarter and year. Closing again
 *   saves fresh ones.
 *
 * Two people pressing Close on the same month at the same moment: closing
 * runs under a lock for that month and checks "already closed" again inside
 * the transaction on the locked row, so the second one is refused and the
 * first one's saved copy and pictures are left alone.
 *
 * Both actions are written to the audit log as "month.closed" /
 * "month.reopened" (AuditObserver on MonthClosure).
 */
class MonthService
{
    public function __construct(
        private ReportBuilder $reports,
        private FrozenSignatures $signatures,
    ) {}

    public function close(string $period, User $by): MonthClosure
    {
        Gate::forUser($by)->authorize('close-month');

        [, $nextMonth] = ReportBuilder::monthRange($period);

        // The report is only final once the month is over.
        if (now()->lessThan($nextMonth)) {
            self::fail("{$this->label($period)} hasn't ended yet. A month can be closed from the 1st of the next month.");
        }

        $lock = Cache::lock("month-close:{$period}", 60);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            self::fail("{$this->label($period)} is being closed right now. Wait a moment and look at the report again.");
        }

        // Snapshots whose picture copies THIS request made: only these are
        // removed if the close fails. A refused second close makes none.
        $made = [];

        try {
            return DB::transaction(function () use ($period, $by, &$made): MonthClosure {
                // The row is locked until commit; check again, now that no one else can close it.
                $closure = MonthClosure::query()->where('period', $period)->lockForUpdate()->first();

                if ($closure?->is_closed) {
                    self::fail("{$this->label($period)} is already closed.");
                }

                $closure ??= new MonthClosure(['period' => $period]);
                $closure->fill([
                    'is_closed' => true,
                    'closed_by' => $by->id,
                    'closed_at' => now(),
                    'reopened_by' => null,
                    'reopened_at' => null,
                ])->save();

                $this->saveSnapshot($period, $this->reports->build($period), $by, $made);
                $this->closeLongerPeriods($period, $by, $made);

                return $closure;
            });
        } catch (\Throwable $e) {
            // The close did not happen: do not leave picture copies behind.
            foreach ($made as $key) {
                $this->signatures->release($key);
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function reopen(string $period, User $by): MonthClosure
    {
        Gate::forUser($by)->authorize('close-month');

        ReportBuilder::monthRange($period); // checks the format

        $closure = MonthClosure::query()->where('period', $period)->where('is_closed', true)->first();

        if ($closure === null) {
            self::fail("{$this->label($period)} is not closed.");
        }

        $keys = [$period, ...array_values(self::longerPeriodsOf($period))];

        $reopened = DB::transaction(function () use ($closure, $by, $keys): MonthClosure {
            $closure->fill([
                'is_closed' => false,
                'reopened_by' => $by->id,
                'reopened_at' => now(),
            ])->save();

            // The month's copy, and the copies of its quarter and year, which are no longer final.
            ReportSnapshot::query()->whereIn('period', $keys)->delete();

            return $closure;
        });

        // The frozen signature pictures go with their snapshots.
        foreach ($keys as $key) {
            $this->signatures->release($key);
        }

        return $reopened;
    }

    /**
     * Save the snapshot for a month, a quarter or a year: the report as
     * counted now and the signature block frozen now.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $made  keys of the snapshots made by this request
     */
    private function saveSnapshot(string $key, array $data, User $by, array &$made): void
    {
        ReportSnapshot::query()->where('period', $key)->delete();

        // Noted before the copies are made, so a failure removes them too.
        $made[] = $key;

        ReportSnapshot::create([
            'period' => $key,
            'data' => $data,
            'signature_block' => $this->signatures->freeze($key),
            'created_by' => $by->id,
        ]);
    }

    /**
     * If every month of this month's quarter (then of its year) is now
     * closed, save a snapshot of that quarter (then year). The numbers are
     * counted by the same call the live report uses, so nothing is counted
     * differently.
     *
     * @param  list<string>  $made
     */
    private function closeLongerPeriods(string $period, User $by, array &$made): void
    {
        $year = (int) substr($period, 0, 4);
        $timezone = config('app.timezone');

        foreach (self::longerPeriodsOf($period) as $kind => $key) {
            if ($kind === 'quarter') {
                $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;
                $from = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1, 0, 0, 0, $timezone);
                $to = $from->addMonths(3);
            } else {
                $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $timezone);
                $to = $from->addYear();
            }

            if (! $this->allClosed($from, $to)) {
                continue;
            }

            $this->saveSnapshot($key, $this->reports->build($from, $to), $by, $made);
        }
    }

    /** Is every month from `$from` up to (not including) `$to` closed? */
    private function allClosed(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $months = [];

        for ($month = $from; $month->lessThan($to); $month = $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        return MonthClosure::query()->whereIn('period', $months)->where('is_closed', true)->count() === count($months);
    }

    /**
     * The snapshot keys of the quarter and the year a month belongs to:
     * ['quarter' => '2026-Q3', 'year' => '2026'].
     *
     * @return array{quarter: string, year: string}
     */
    public static function longerPeriodsOf(string $period): array
    {
        $year = (int) substr($period, 0, 4);
        $quarter = intdiv((int) substr($period, 5, 2) - 1, 3) + 1;

        return ['quarter' => "{$year}-Q{$quarter}", 'year' => (string) $year];
    }

    private function label(string $period): string
    {
        return ReportBuilder::monthRange($period)[0]->format('F Y');
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['period' => $message]);
    }
}
