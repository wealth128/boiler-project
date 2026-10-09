<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MonthClosure;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Auth\Access\Response;

/**
 * Who may edit or delete one visit (planning/rbac.md):
 *
 * - Nobody, while the visit's month is closed. Admin reopens it first.
 * - Admin: any visit.
 * - Encoder: only a visit they encoded, and only on the day it was encoded
 *   (Asia/Manila). Encoders can't delete.
 * - System Admin and Viewer (CO): never.
 *
 * Moving a visit to Trash is Admin only. Restore and permanent delete are
 * in TrashPolicy.
 */
class VisitPolicy
{
    public function update(User $user, Visit $visit): Response
    {
        if (MonthClosure::isClosed($visit->period)) {
            return self::monthClosed($visit);
        }

        if ($user->hasRole(Role::Admin)) {
            return Response::allow();
        }

        if (! $user->hasRole(Role::Encoder)) {
            return Response::deny();
        }

        if ($visit->encoded_by !== $user->id) {
            return Response::deny('You can only edit visits you encoded. Ask the Admin to correct this one.');
        }

        if (! $visit->visited_at->isSameDay(now())) {
            return Response::deny('You can only edit your own visits on the day you encoded them. Ask the Admin to correct this one.');
        }

        return Response::allow();
    }

    public function delete(User $user, Visit $visit): Response
    {
        if (MonthClosure::isClosed($visit->period)) {
            return self::monthClosed($visit);
        }

        return $user->hasRole(Role::Admin) ? Response::allow() : Response::deny();
    }

    private static function monthClosed(Visit $visit): Response
    {
        return Response::deny(MonthClosure::closedMessage($visit->period));
    }
}
