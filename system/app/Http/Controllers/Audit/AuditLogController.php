<?php

namespace App\Http\Controllers\Audit;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogFilterRequest;
use App\Models\User;
use App\Services\AuditActions;
use App\Services\TablePaging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit log (prototype/prototype.html, "Audit log"). Admin only: role:admin
 * on the route and the "view-audit" Gate here. Read-only: this controller
 * has no create, edit or delete, and looking at the log is not logged.
 *
 * Filters live in the URL (?from=&to=&user=&action=&record=&page=), checked
 * by AuditLogFilterRequest. 50 entries per page, newest first.
 */
class AuditLogController extends Controller
{
    public function index(AuditLogFilterRequest $request): Response|RedirectResponse
    {
        Gate::authorize(Permission::ViewAudit->value);

        $filters = $request->filters();

        $page = AuditRows::query($filters)
            ->paginate(TablePaging::perPage($request))
            ->withQueryString();

        if ($redirect = TablePaging::redirectPastLastPage($request, $page)) {
            return $redirect;
        }

        return Inertia::render('audit/index', [
            'filters' => $filters,
            // Entries older than this are archived and removed automatically.
            'retention_from' => AuditLogFilterRequest::retentionDate(),
            'actions' => AuditActions::options(),
            // Every account, including deactivated ones and ones in Trash.
            'users' => User::withTrashed()->orderBy('username')->get(['id', 'username', 'name', 'deleted_at'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'label' => "{$user->username} · {$user->name}".($user->trashed() ? ' (in Trash)' : ''),
                ])
                ->values(),
            'entries' => [
                'data' => AuditRows::rows($page->items()),
                'meta' => TablePaging::meta($page),
            ],
        ]);
    }
}
