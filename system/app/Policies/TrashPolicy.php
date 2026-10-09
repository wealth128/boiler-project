<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
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
 * AppServiceProvider: "restore-from-trash" and "delete-permanently", e.g.
 * Gate::authorize('delete-permanently', $patient).
 *
 * Permanent delete is blocked when it would break history:
 * - Visit or month report: its month is closed (reopen first)
 * - Patient: still has visits, including ones in Trash
 * - Diagnosis or rank: used by any visit
 * - User: has visits or audit log entries
 * - Age brackets: never blocked (not linked to visits)
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

    public function restore(User $actor, Model $item): Response
    {
        self::ensureTrashable($item);

        if (! self::manages($actor)) {
            return Response::deny();
        }

        if (! self::inTrash($item)) {
            return Response::deny('This item is not in Trash.');
        }

        if ($closed = self::closedMonth($item)) {
            return $closed;
        }

        if ($item instanceof User && $item->is_active && $item->hasRole(Role::Admin) && User::activeAdminExists(except: $item)) {
            return UserPolicy::oneAdminOnly();
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
