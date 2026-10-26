<?php

namespace App\Http\Controllers\Records;

use App\Enums\Category;
use App\Enums\Permission;
use App\Enums\VisitDeleteReason;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MonthClosure;
use App\Models\User;
use App\Models\Visit;
use App\Services\TablePaging;
use App\Services\TrashService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patient Records, Visits tab (prototype/prototype.html, "Patient Records").
 * Admin only (role:admin on the route).
 *
 * Filters live in the URL (?from=&to=&category=&branch=&q=&page=), so
 * Refresh and Back keep them. With no From / To in the URL, the range is
 * the first day of last month to today. An empty From or To means no limit
 * on that side. Visits in Trash never show. 50 rows per page, newest first.
 *
 * Edit opens the visit on the Encode screen (EncodeController, ?edit=).
 * Delete moves it to Trash through TrashService, with a reason picked from
 * VisitDeleteReason (VisitPolicy: not while its month is closed; that
 * refusal shows in the row, not as a 403 page). The audit entry
 * "visit.deleted" comes from AuditObserver.
 */
class VisitRecordController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize(Permission::ViewRecords->value);

        $filters = $this->filters($request);

        $query = Visit::query()
            ->with(['patient', 'branch', 'rank', 'diagnosis', 'encoder'])
            ->when($filters['from'] !== '', fn ($q) => $q->where('visited_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay()))
            ->when($filters['to'] !== '', fn ($q) => $q->where('visited_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->startOfDay()))
            ->when($filters['category'] !== '', fn ($q) => $q->where('category', $filters['category']))
            ->when($filters['branch'] !== '', fn ($q) => $q->where('branch_id', (int) $filters['branch']));

        RecordSearch::visits($query, $filters['q']);

        // Ids first, then the rows of the page (TablePaging::paginate), so a late page stays fast.
        $page = TablePaging::paginate($query->orderByDesc('visited_at')->orderByDesc('id'), $request)
            ->withQueryString();

        if ($redirect = TablePaging::redirectPastLastPage($request, $page)) {
            return $redirect;
        }

        // One query for the whole page instead of one per row.
        $closed = MonthClosure::query()->where('is_closed', true)->pluck('period')->flip();

        return Inertia::render('records/visits', [
            'filters' => $filters,
            'categories' => Category::values(),
            'branches' => Branch::query()->ordered()->get(['id', 'name'])
                ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])
                ->values(),
            'visits' => [
                'data' => collect($page->items())
                    ->map(fn (Visit $visit): array => $this->row($visit, $closed->has($visit->period)))
                    ->values(),
                'meta' => TablePaging::meta($page),
            ],
            'deleteReasons' => VisitDeleteReason::options(),
        ]);
    }

    /**
     * Move a visit to Trash with a reason from the list. Back to the same
     * filters and page.
     */
    public function destroy(Request $request, Visit $visit, TrashService $trash): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            Gate::authorize('delete', $visit);
            $trash->moveToTrash($visit, $actor, $request->input('reason'), $request->input('reason_other'));
        } catch (AuthorizationException $e) {
            // A refusal with a reason (the month was closed after the page
            // loaded) shows in the "Move to Trash?" row, like the patient
            // refusal. Anything else is a plain 403.
            // Gate refusals always carry their Response (Response::authorize()).
            $message = $e->response()->message();

            if ($message === null) {
                throw $e;
            }

            throw ValidationException::withMessages(['item' => $message]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Moved {$visit->visit_no} to Trash"]);

        return RecordSearch::redirectBack('records.visits');
    }

    /**
     * The filters from the URL, cleaned. Values that make no sense are
     * dropped rather than shown as errors.
     *
     * @return array{from: string, to: string, category: string, branch: string, q: string}
     */
    private function filters(Request $request): array
    {
        $category = RecordSearch::param($request, 'category');
        $branch = RecordSearch::param($request, 'branch');

        return [
            'from' => $this->date($request, 'from', now()->subMonthNoOverflow()->startOfMonth()->toDateString()),
            'to' => $this->date($request, 'to', now()->toDateString()),
            'category' => in_array($category, Category::values(), true) ? $category : '',
            'branch' => ctype_digit($branch) && Branch::query()->whereKey((int) $branch)->exists() ? $branch : '',
            'q' => RecordSearch::query($request),
        ];
    }

    /**
     * A YYYY-MM-DD date from the URL. Missing: the default. Empty: no limit.
     */
    private function date(Request $request, string $key, string $default): string
    {
        $raw = $request->query($key);

        // Missing, or a list such as ?from[]=: the default.
        if (! $request->query->has($key) || is_array($raw)) {
            return $default;
        }

        // Present but empty (Laravel turns "" into null): no limit.
        $value = (string) $raw;

        if ($value === '') {
            return '';
        }

        $real = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $part) === 1
            && checkdate((int) $part[2], (int) $part[3], (int) $part[1]);

        return $real ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Visit $visit, bool $monthClosed): array
    {
        $patient = $visit->patient;

        return [
            'id' => $visit->id,
            'visit_no' => $visit->visit_no,
            'visited_at' => $visit->visited_at->toIso8601String(),
            'patient_no' => $patient->patient_no,
            'patient_name' => $patient->full_name,
            'sex' => $patient->sex->value,
            'age' => $visit->age,
            'branch' => $visit->branch->name,
            // The typed text for "Other (specify)" (prototype: rankText).
            'rank' => $visit->rank->is_other ? ($visit->rank_other ?: 'Other') : $visit->rank->name,
            'diagnosis' => $visit->diagnosis->is_other ? "Other: {$visit->diagnosis_other}" : $visit->diagnosis->name,
            'remarks' => $visit->remarks,
            'category' => $visit->category->value,
            'encoded_by' => $visit->encoder->username,
            // From a downtime import (Records > Import): who wrote the row, and a label.
            'written_by' => $visit->written_by,
            'imported' => $visit->import_batch_id !== null,
            // Admin may edit and delete any visit except in a closed month
            // (VisitPolicy). The server checks again on Edit and Delete.
            'month_closed' => $monthClosed,
        ];
    }
}
