<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AgeBracket;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Trash: restore and permanent delete (planning/database-schema.md,
 * section 5). Admin only. Trash holds visits, patients, users, diagnoses,
 * ranks, age brackets and "Delete month report" entries (MonthDeletion).
 *
 * Trash is not one model, so these checks are registered as Gates in
 * AppServiceProvider: "move-to-trash", "delete-month-report",
 * "restore-from-trash" and "delete-permanently", e.g.
 * Gate::authorize('delete-permanently', $patient). App\Services\TrashService
 * calls them, so every Trash action goes through these rules.
 *
 * Permanent delete is blocked when it would break history:
 * - Visit or month report: its month is closed (reopen first)
 * - Patient: still has visits, including ones in Trash
 * - Diagnosis or rank: used by any visit
 * - User: has visits or audit log entries
 * - Age brackets: never blocked (not linked to visits)
 *
 * Patients and their visits (prototype/prototype.html): a patient goes to
 * Trash only after all their visits are in Trash, and a visit comes back
 * only after its patient is restored.
 */
class TrashPolicy
{
    public function viewAny(User $actor): bool
    {
        return self::manages($actor);
    }

    public function empty(User $actor): bool
    {
        return self::manages($actor);
    }

    /**
     * Move one record to Trash. Visits and users follow their own Policy
     * (closed month; can't delete yourself or the Admin). Patients and
     * dropdown items: Admin only. The fixed "Other (specify)" entries
     * can't be removed (planning/database-schema.md, section 5).
     */
    public function moveToTrash(User $actor, Model $item): Response
    {
        self::ensureTrashable($item);

        if ($item instanceof MonthDeletion) {
            throw new InvalidArgumentException('Use "Delete month report" for a whole month.');
        }

        if ($item->trashed()) {
            return Response::deny('This item is already in Trash.');
        }

        if ($item instanceof Visit) {
            return (new VisitPolicy)->delete($actor, $item);
        }

        if ($item instanceof User) {
            return (new UserPolicy)->delete($actor, $item);
        }

        if (! self::manages($actor)) {
            return Response::deny();
        }

        if (($item instanceof Diagnosis || $item instanceof Rank) && $item->is_other) {
            return Response::deny('"Other (specify)" is a fixed entry and can\'t be removed.');
        }

        // Visits first, then the patient (prototype/prototype.html).
        if ($item instanceof Patient && $item->visits()->exists()) {
            return Response::deny("Move this patient's visits to Trash first.");
        }

        return Response::allow();
    }

    /**
     * "Delete month report": all visits of an open month go to Trash as one
     * entry. Admin only.
     */
    public function deleteMonthReport(User $actor, string $period): Response
    {
        if (! self::manages($actor)) {
            return Response::deny();
        }

        if (MonthClosure::isClosed($period)) {
            return Response::deny(MonthClosure::closedMessage($period));
        }

        return Response::allow();
    }

    public function restore(User $actor, Model $item): Response
    {
        self::ensureTrashable($item);

        if (! self::manages($actor)) {
            return Response::deny();
        }

        if (! self::inTrash($item)) {
            return Response::deny('This item is not in Trash.');
        }

        if ($partOfMonth = self::partOfMonthReport($item)) {
            return $partOfMonth;
        }

        if ($closed = self::closedMonth($item)) {
            return $closed;
        }

        if ($item instanceof User && $item->is_active && $item->role->isSingleHolder() && User::activeHolderExists($item->role, except: $item)) {
            return UserPolicy::oneOnly($item->role);
        }

        // A visit can't come back without its patient.
        if ($item instanceof Visit && Patient::onlyTrashed()->whereKey($item->patient_id)->exists()) {
            return Response::deny('Restore the patient first.');
        }

        // Same for a month report: name the first patient to restore
        // (prototype/prototype.html).
        if ($item instanceof MonthDeletion) {
            $patientNo = Patient::onlyTrashed()
                ->whereIn('id', $item->visits()->onlyTrashed()->select('patient_id'))
                ->orderBy('id')
                ->value('patient_no');

            if ($patientNo !== null) {
                return Response::deny("Restore patient {$patientNo} first.");
            }
        }

        return Response::allow();
    }

    public function forceDelete(User $actor, Model $item): Response
    {
        self::ensureTrashable($item);

        if (! self::manages($actor)) {
            return Response::deny();
        }

        if (! self::inTrash($item)) {
            return Response::deny('Only items in Trash can be deleted permanently.');
        }

        if ($partOfMonth = self::partOfMonthReport($item)) {
            return $partOfMonth;
        }

        if ($closed = self::closedMonth($item)) {
            return $closed;
        }

        return match (true) {
            $item instanceof Patient && $item->visits()->withTrashed()->exists() => Response::deny("This patient still has visits, so the record can't be deleted permanently."),
            $item instanceof Diagnosis && $item->visits()->withTrashed()->exists() => Response::deny("This diagnosis is used by visits, so it can't be deleted permanently."),
            $item instanceof Rank && $item->visits()->withTrashed()->exists() => Response::deny("This rank is used by visits, so it can't be deleted permanently."),
            $item instanceof User && ($item->encodedVisits()->withTrashed()->exists() || $item->auditLogs()->exists()) => Response::deny("This account has visits or audit log entries, so it can't be deleted permanently."),
            default => Response::allow(),
        };
    }

    private static function manages(User $actor): bool
    {
        return Permission::ManageTrash->allows($actor->role);
    }

    /**
     * A MonthDeletion row only exists while its month report is in Trash.
     */
    private static function inTrash(Model $item): bool
    {
        return $item instanceof MonthDeletion || $item->trashed();
    }

    /**
     * A visit that went to Trash with "Delete month report" comes back or
     * goes away only with its whole month report.
     */
    private static function partOfMonthReport(Model $item): ?Response
    {
        if ($item instanceof Visit && $item->month_deletion_id !== null) {
            return Response::deny('This visit is part of a deleted month report. Restore or delete the whole month report instead.');
        }

        return null;
    }

    /**
     * Visits and month reports of a closed month can't be restored or
     * deleted permanently until Admin reopens the month.
     */
    private static function closedMonth(Model $item): ?Response
    {
        $period = match (true) {
            $item instanceof Visit => $item->period,
            $item instanceof MonthDeletion => $item->period,
            default => null,
        };

        if ($period === null || ! MonthClosure::isClosed($period)) {
            return null;
        }

        return Response::deny(MonthClosure::closedMessage($period));
    }

    private static function ensureTrashable(Model $item): void
    {
        $types = [Visit::class, Patient::class, User::class, Diagnosis::class, Rank::class, AgeBracket::class, MonthDeletion::class];

        if (! in_array($item::class, $types, true)) {
            throw new InvalidArgumentException($item::class.' does not go to Trash.');
        }
    }
}
