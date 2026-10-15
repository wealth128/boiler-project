<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use App\Services\TrashService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Close / reopen / delete a month. {period} is "YYYY-MM" (checked by the
 * route pattern). Admin only: role:admin on the routes, the close-month and
 * delete-month-report Gates in the services.
 *
 * The rules live in MonthService and TrashService. A refusal with a reason
 * (month not over yet, month closed, no visits, bad reason) goes back to the
 * Reports screen as an error on `period`, `reason` or `reason_other`, not a
 * 403 page. Audit entries come from AuditObserver and TrashService.
 */
class MonthController extends Controller
{
    /**
     * Close the month (locks it and saves a report snapshot).
     */
    public function close(Request $request, string $period, MonthService $months): RedirectResponse
    {
        $this->refusalsAsMessages(fn () => $months->close($period, $this->actor($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$this->label($period)} closed. The report was saved."]);

        return back();
    }

    /**
     * Reopen the month (discards the saved snapshot).
     */
    public function reopen(Request $request, string $period, MonthService $months): RedirectResponse
    {
        $this->refusalsAsMessages(fn () => $months->reopen($period, $this->actor($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$this->label($period)} reopened. The report is counted live again."]);

        return back();
    }

    /**
     * Delete the month report: all its visits go to Trash as one entry.
     */
    public function destroy(Request $request, string $period, TrashService $trash): RedirectResponse
    {
        $entry = $this->refusalsAsMessages(
            fn () => $trash->deleteMonthReport($period, $this->actor($request), $request->input('reason'), $request->input('reason_other')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Moved {$entry->visit_count} visits of {$this->label($period)} to Trash"]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function label(string $period): string
    {
        return ReportBuilder::monthRange($period)[0]->format('F Y');
    }

    /**
     * Run a month action. A Gate refusal that carries a reason (e.g. the
     * month is closed) becomes a message on the Reports screen; one
     * without a reason stays a 403.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function refusalsAsMessages(callable $action): mixed
    {
        try {
            return $action();
        } catch (AuthorizationException $e) {
            $message = $e->response()?->message() ?? $e->getMessage();

            if ($message === '' || $message === 'This action is unauthorized.') {
                throw $e;
            }

            throw ValidationException::withMessages(['period' => $message]);
        }
    }
}
