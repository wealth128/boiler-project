<?php

namespace App\Services;

use App\Models\AgeBracket;
use App\Models\Diagnosis;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Everything that goes into or out of Trash (planning/database-schema.md,
 * section 5). Controllers for Records, Lists, Users and Trash call this.
 *
 * Every action checks the TrashPolicy Gates first (who may, closed month,
 * "can't delete permanently" rules), so callers can't skip them.
 *
 * Audit log: single records are logged by AuditObserver ("visit.deleted",
 * "patient.restored", "rank.purged"...). A month report is logged as one
 * entry ("month.report_deleted", "month.report_restored",
 * "month.report_purged") instead of one per visit.
 */
class TrashService
{
    /**
     * Trash types as they appear in URLs (/trash/{type}/{id}).
     *
     * @var array<string, class-string<Model>>
     */
    public const TYPES = [
        'visits' => Visit::class,
        'patients' => Patient::class,
        'users' => User::class,
        'diagnoses' => Diagnosis::class,
        'ranks' => Rank::class,
        'age-brackets' => AgeBracket::class,
        'months' => MonthDeletion::class,
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * A record that is in Trash, by URL type and id (404 if not found).
     */
    public function find(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(404);

        return $class === MonthDeletion::class
            ? MonthDeletion::query()->findOrFail($id)
            : $class::onlyTrashed()->findOrFail($id);
    }

    /**
     * Move one record to Trash with a reason.
     */
    public function moveToTrash(Model $item, User $by, ?string $reason): void
    {
        $reason = self::reason($reason);

        Gate::forUser($by)->authorize('move-to-trash', $item);

        $item->moveToTrash($by, $reason);
    }

    /**
     * "Delete month report": every visit of an open month that is not
     * already in Trash goes to Trash together, as one MonthDeletion entry.
     */
    public function deleteMonthReport(string $period, User $by, ?string $reason): MonthDeletion
    {
        $reason = self::reason($reason);
        [$from, $to] = ReportBuilder::monthRange($period);

        Gate::forUser($by)->authorize('delete-month-report', $period);

        return DB::transaction(function () use ($period, $from, $to, $by, $reason): MonthDeletion {
            $visits = Visit::query()
                ->where('visited_at', '>=', $from)
                ->where('visited_at', '<', $to)
                ->lockForUpdate();

            $count = (clone $visits)->count();

            if ($count === 0) {
                throw ValidationException::withMessages(['period' => 'This month has no visits to delete.']);
            }

            $entry = MonthDeletion::create([
                'period' => $period,
                'visit_count' => $count,
                'deleted_by' => $by->id,
                'delete_reason' => $reason,
                'deleted_at' => now(),
            ]);

            // One query, no per-visit events: the month gets one log entry.
            $visits->update([
                'deleted_at' => now(),
                'deleted_by' => $by->id,
                'delete_reason' => $reason,
                'month_deletion_id' => $entry->id,
            ]);

            $this->audit->log('month.report_deleted', $entry, [
                'period' => $period,
                'visit_count' => $count,
                'reason' => $reason,
            ], by: $by);

            return $entry;
        });
    }

    /**
     * Take a record (or a whole month report) out of Trash.
     */
    public function restore(Model $item, User $by): void
    {
        Gate::forUser($by)->authorize('restore-from-trash', $item);

        if ($item instanceof MonthDeletion) {
            DB::transaction(function () use ($item, $by): void {
                $item->visits()->onlyTrashed()->update([
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'delete_reason' => null,
                    'month_deletion_id' => null,
                ]);

                $this->audit->log('month.report_restored', $item, [
                    'period' => $item->period,
                    'visit_count' => $item->visit_count,
                ], by: $by);

                $item->delete();
            });

            return;
        }

        $item->restoreFromTrash();
    }

    /**
     * Delete one record (or a whole month report) for good. Blocked by
     * TrashPolicy when it would break history.
     */
    public function forceDelete(Model $item, User $by): void
    {
        Gate::forUser($by)->authorize('delete-permanently', $item);

        try {
            DB::transaction(function () use ($item, $by): void {
                if ($item instanceof MonthDeletion) {
                    $this->audit->log('month.report_purged', $item, [
                        'period' => $item->period,
                        'visit_count' => $item->visit_count,
                    ], by: $by);

                    $item->visits()->onlyTrashed()->forceDelete();
                    $item->delete();

                    return;
                }

                $item->forceDelete();
            });
        } catch (QueryException $e) {
            // A link the Policy doesn't know about (a foreign key) still
            // points at this record. Keep it rather than fail with an error.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            throw ValidationException::withMessages([
                'item' => "This item is still linked to other records, so it can't be deleted permanently.",
            ]);
        }
    }

    /**
     * Delete everything in Trash that may be deleted permanently. Items the
     * rules protect stay in Trash.
     *
     * @return array{deleted: int, kept: int}
     */
    public function emptyTrash(User $by): array
    {
        Gate::forUser($by)->authorize('manage-trash');

        $deleted = 0;
        $kept = 0;

        foreach ($this->itemsInTrash() as $item) {
            if (Gate::forUser($by)->denies('delete-permanently', $item)) {
                $kept++;

                continue;
            }

            try {
                $this->forceDelete($item, $by);
                $deleted++;
            } catch (ValidationException) {
                $kept++;
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /**
     * Everything in Trash, in an order that frees links first: month
     * reports and visits before the patients, diagnoses, ranks and users
     * they point to.
     *
     * @return iterable<Model>
     */
    private function itemsInTrash(): iterable
    {
        yield from MonthDeletion::query()->orderBy('id')->get();
        yield from Visit::onlyTrashed()->whereNull('month_deletion_id')->orderBy('id')->get();

        foreach ([Patient::class, Diagnosis::class, Rank::class, AgeBracket::class, User::class] as $class) {
            yield from $class::onlyTrashed()->orderBy('id')->get();
        }
    }

    private static function reason(?string $reason): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for moving this to Trash.']);
        }

        if (mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'The reason can be at most 255 characters.']);
        }

        return $reason;
    }
}
