<?php

namespace App\Http\Controllers\Trash;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TablePaging;
use App\Services\TrashService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trash (prototype/prototype.html, trashView). Admin only: role:admin on
 * the routes, and the "manage-trash" Gate here. {type} is one of: visits,
 * patients, users, diagnoses, ranks, age-brackets, months (the route
 * accepts only these, and {id} only digits).
 *
 * Every rule (who may, closed month, "can't delete permanently", list item
 * conflicts) lives in TrashPolicy and TrashService. This controller only
 * calls them and shows the answer: a refusal appears in that item's row
 * (the `item` error), never as a 403 page. Audit entries come from the
 * service and AuditObserver.
 *
 * Permanent delete needs the item's ID typed (TrashRows::confirmText), and
 * Empty Trash needs "EMPTY TRASH". The server checks the text too.
 */
class TrashController extends Controller
{
    /** Typed to confirm Empty Trash. */
    public const EMPTY_PHRASE = 'EMPTY TRASH';

    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize('manage-trash');

        $page = TrashRows::page($request);

        if ($redirect = TablePaging::redirectPastLastPage($request, $page)) {
            return $redirect;
        }

        return Inertia::render('trash/index', [
            'items' => [
                'data' => TrashRows::rows($page->items(), $this->actor($request)),
                'meta' => TablePaging::meta($page),
            ],
        ]);
    }

    /**
     * Take one item (or a whole month report) out of Trash.
     */
    public function restore(Request $request, string $type, int $id, TrashService $trash): RedirectResponse
    {
        Gate::authorize('manage-trash');

        $item = $this->find($trash, $type, $id);

        if ($item === null) {
            return $this->gone();
        }

        $this->refusalInRow(fn () => $trash->restore($item, $this->actor($request)));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Restored '.TrashRows::confirmText($item)]);

        return $this->backToList($request);
    }

    /**
     * Delete one item permanently, after its ID was typed.
     */
    public function destroy(Request $request, string $type, int $id, TrashService $trash): RedirectResponse
    {
        Gate::authorize('manage-trash');

        $item = $this->find($trash, $type, $id);

        if ($item === null) {
            return $this->gone();
        }

        $actor = $this->actor($request);
        $expected = TrashRows::confirmText($item);

        // A blocked item says why before it asks for the typed text.
        $this->refusalInRow(fn () => Gate::forUser($actor)->authorize('delete-permanently', $item));

        if (! TrashRows::matches($request->input('confirm'), $expected)) {
            throw ValidationException::withMessages(['confirm' => "Type {$expected} exactly to confirm."]);
        }

        $this->refusalInRow(fn () => $trash->forceDelete($item, $actor));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$expected} permanently deleted"]);

        return $this->backToList($request);
    }

    /**
     * Empty Trash: delete everything that may be deleted. Items still
     * needed stay in Trash.
     */
    public function empty(Request $request, TrashService $trash): RedirectResponse
    {
        Gate::authorize('manage-trash');

        if (! TrashRows::matches($request->input('confirm'), self::EMPTY_PHRASE)) {
            throw ValidationException::withMessages(['confirm' => 'Type '.self::EMPTY_PHRASE.' exactly to confirm.']);
        }

        ['deleted' => $deleted, 'kept' => $kept] = $trash->emptyTrash($this->actor($request));

        $message = 'Deleted '.self::items($deleted).'.';

        if ($kept === 1) {
            $message .= " 1 item stays in Trash because it's still needed.";
        } elseif ($kept > 1) {
            $message .= " {$kept} items stay in Trash because they're still needed.";
        }

        Inertia::flash('toast', ['type' => $deleted > 0 ? 'success' : 'info', 'message' => $message]);

        return redirect()->route('trash.index');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * The item in Trash, or null when it is no longer there (restored or
     * deleted in another window).
     */
    private function find(TrashService $trash, string $type, int $id): ?Model
    {
        try {
            return $trash->find($type, $id);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private function gone(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'warning', 'message' => 'That item is no longer in Trash.']);

        return redirect()->route('trash.index');
    }

    /**
     * Run a Trash action. A refusal with a reason (closed month, restore the
     * patient first...) goes to the `item` error, which the page shows in
     * that item's row. Gate refusals always carry their Response.
     */
    private function refusalInRow(Closure $action): void
    {
        try {
            $action();
        } catch (AuthorizationException $e) {
            $message = $e->response()?->message();

            if ($message === null) {
                throw $e;
            }

            throw ValidationException::withMessages(['item' => $message]);
        }
    }

    /**
     * Back to the list, on the page the click came from (the page number is
     * sent with the request; a page past the end goes to the last page).
     */
    private function backToList(Request $request): RedirectResponse
    {
        $page = $request->integer('page');

        return redirect()->route('trash.index', $page > 1 ? ['page' => $page] : []);
    }

    private static function items(int $count): string
    {
        return $count.($count === 1 ? ' item' : ' items');
    }
}
