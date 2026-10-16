<?php

namespace App\Services;

use App\Models\MonthClosure;
use App\Models\ReportSnapshot;
use App\Models\User;
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
 * - Reopening unlocks it and removes the snapshot with its frozen
 *   signatures. Closing again saves fresh ones.
 *
 * Both are written to the audit log as "month.closed" / "month.reopened"
 * (AuditObserver on MonthClosure).
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

        if (MonthClosure::isClosed($period)) {
            self::fail("{$this->label($period)} is already closed.");
        }

        // The report is only final once the month is over.
        if (now()->lessThan($nextMonth)) {
            self::fail("{$this->label($period)} hasn't ended yet. A month can be closed from the 1st of the next month.");
        }

        try {
            return DB::transaction(function () use ($period, $by): MonthClosure {
                $closure = MonthClosure::query()->firstOrNew(['period' => $period]);
                $closure->fill([
                    'is_closed' => true,
                    'closed_by' => $by->id,
                    'closed_at' => now(),
                    'reopened_by' => null,
                    'reopened_at' => null,
                ])->save();

                ReportSnapshot::query()->where('period', $period)->delete();
                ReportSnapshot::create([
                    'period' => $period,
                    'data' => $this->reports->build($period),
                    'signature_block' => $this->signatures->freeze($period),
                    'created_by' => $by->id,
                ]);

                return $closure;
            });
        } catch (\Throwable $e) {
            // The close did not happen: do not leave picture copies behind.
            $this->signatures->release($period);

            throw $e;
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

        $reopened = DB::transaction(function () use ($closure, $by): MonthClosure {
            $closure->fill([
                'is_closed' => false,
                'reopened_by' => $by->id,
                'reopened_at' => now(),
            ])->save();

            ReportSnapshot::query()->where('period', $closure->period)->delete();

            return $closure;
        });

        // The frozen signature pictures go with the snapshot.
        $this->signatures->release($closure->period);

        return $reopened;
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
