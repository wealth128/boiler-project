<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ListItemDeleteReason;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ListItemRequest;
use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Rank;
use App\Models\User;
use App\Services\ListItemRules;
use App\Services\TrashService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dropdown Lists (prototype/prototype.html, Admin Settings, "Dropdown
 * lists" tab). Admin only: role:admin on the routes, the manage-lists Gate
 * here and in ListItemRequest. {list} is diagnoses, ranks or age-brackets.
 *
 * Only active items show; items in Trash don't. Order: diagnoses
 * alphabetical with "Other (specify)" last, ranks in the seniority order
 * Admin sets with Move up / Move down, age brackets by From age.
 *
 * Visits store the item's id, so a rename shows on every past visit and in
 * open-month reports. Closed months keep their saved report copy.
 *
 * Delete moves the item to Trash through TrashService with a reason from
 * ListItemDeleteReason. Audit entries ("diagnosis.created",
 * "rank.updated", "age_bracket.deleted"...) come from AuditObserver.
 */
class ListController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Permission::ManageLists->value);

        $ranks = Rank::query()->ordered()->get(['id', 'branch_id', 'name', 'is_other'])->groupBy('branch_id');

        return Inertia::render('lists/index', [
            'diagnoses' => Diagnosis::query()->ordered()->get()
                ->map(fn (Diagnosis $diagnosis): array => [
                    'id' => $diagnosis->id,
                    'name' => $diagnosis->name,
                    'is_other' => $diagnosis->is_other,
                ])->values(),
            'ageBrackets' => AgeBracket::query()->ordered()->get()
                ->map(fn (AgeBracket $bracket): array => [
                    'id' => $bracket->id,
                    'min_age' => $bracket->min_age,
                    'max_age' => $bracket->max_age,
                    'label' => $bracket->label,
                ])->values(),
            'branches' => Branch::query()->ordered()->get()
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'is_afp' => $branch->is_afp,
                    'ranks' => $ranks->get($branch->id, collect())
                        ->map(fn (Rank $rank): array => [
                            'id' => $rank->id,
                            'name' => $rank->name,
                            'is_other' => $rank->is_other,
                        ])->values(),
                ])->values(),
            'limits' => [
                'name_min' => ListItemRules::NAME_MIN,
                'diagnosis_name_max' => ListItemRules::DIAGNOSIS_NAME_MAX,
                'rank_name_max' => ListItemRules::RANK_NAME_MAX,
                'age_min' => ListItemRules::AGE_MIN,
                'age_max' => ListItemRules::AGE_MAX,
            ],
            'deleteReasons' => ListItemDeleteReason::options(),
        ]);
    }

    /**
     * Add a diagnosis, a rank to a branch (at the bottom of its order), or
     * an age bracket.
     */
    public function store(ListItemRequest $request, string $list): RedirectResponse
    {
        $message = match ($list) {
            'diagnoses' => $this->saveName(function () use ($request): string {
                $diagnosis = Diagnosis::create(['name' => $request->itemName(), 'is_other' => false, 'sort_order' => 0]);

                return "Added “{$diagnosis->name}”";
            }),
            'ranks' => $this->saveName(function () use ($request): string {
                return DB::transaction(function () use ($request): string {
                    // Lock the branch so two adds can't take the same place.
                    $branch = Branch::query()->lockForUpdate()->findOrFail($request->branch()->id);

                    // Counted with Trash, so a restored rank never shares a number.
                    $last = (int) Rank::withTrashed()
                        ->where('branch_id', $branch->id)
                        ->where('is_other', false)
                        ->max('sort_order');

                    $rank = Rank::create([
                        'branch_id' => $branch->id,
                        'name' => $request->itemName(),
                        'is_other' => false,
                        'sort_order' => $last + 1,
                    ]);

                    return "Added “{$rank->name}” to {$branch->name}";
                });
            }),
            'age-brackets' => $this->saveBracket($request, null),
            default => abort(404),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('lists.index');
    }

    /**
     * Fix the spelling of a name, or change a bracket's ages.
     */
    public function update(ListItemRequest $request, string $list, int $id): RedirectResponse
    {
        $item = $request->editedItem();

        $message = $item instanceof AgeBracket
            ? $this->saveBracket($request, $item)
            : $this->saveName(function () use ($item, $request): string {
                /** @var Diagnosis|Rank $item */
                $item->update(['name' => $request->itemName()]);

                return "Renamed to “{$item->name}”";
            });

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('lists.index');
    }

    /**
     * Move an item to Trash with a reason from ListItemDeleteReason. A
     * refusal with a reason ("Other (specify)" is fixed) shows in the row.
     */
    public function destroy(Request $request, TrashService $trash, string $list, int $id): RedirectResponse
    {
        Gate::authorize(Permission::ManageLists->value);

        /** @var User $actor */
        $actor = $request->user();

        $item = match ($list) {
            'diagnoses' => Diagnosis::query()->findOrFail($id),
            'ranks' => Rank::query()->with('branch')->findOrFail($id),
            'age-brackets' => AgeBracket::query()->findOrFail($id),
            default => abort(404),
        };

        try {
            $trash->moveToTrash($item, $actor, $request->input('reason'), $request->input('reason_other'));
        } catch (AuthorizationException $e) {
            // Gate refusals always carry their Response (Response::authorize()).
            $message = $e->response()->message();

            if ($message === null) {
                throw $e;
            }

            throw ValidationException::withMessages(['item' => $message]);
        }

        $label = match (true) {
            $item instanceof AgeBracket => "age bracket {$item->label}",
            $item instanceof Rank => "“{$item->name}” ({$item->branch->name})",
            default => "“{$item->name}”",
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => "Moved {$label} to Trash"]);

        return to_route('lists.index');
    }

    /**
     * Move a rank one place up or down within its branch (seniority
     * order). "Other (specify)" stays last and can't be moved. Swaps the
     * sort_order of the two ranks, so the audit log gets two
     * "rank.updated" entries.
     */
    public function move(Request $request, int $id): RedirectResponse
    {
        Gate::authorize(Permission::ManageLists->value);

        $rank = Rank::query()->findOrFail($id);

        if ($rank->is_other) {
            throw new AuthorizationException(ListItemRules::FIXED_MESSAGE);
        }

        $direction = $request->validate(
            ['direction' => ['required', Rule::in(['up', 'down'])]],
            ['direction.*' => 'Choose up or down.'],
        )['direction'];

        DB::transaction(function () use ($rank, $direction): void {
            Branch::query()->lockForUpdate()->findOrFail($rank->branch_id);

            $ranks = Rank::query()
                ->where('branch_id', $rank->branch_id)
                ->where('is_other', false)
                ->ordered()
                ->get()
                ->values();

            $index = $ranks->search(fn (Rank $r): bool => $r->id === $rank->id);
            $neighbour = $ranks->get($direction === 'up' ? $index - 1 : $index + 1);

            if ($index === false || $neighbour === null) {
                return; // already first or last
            }

            /** @var Rank $current */
            $current = $ranks->get($index);
            [$a, $b] = [$current->sort_order, $neighbour->sort_order];

            if ($a === $b) {
                // Two ranks with the same number (not made by this screen):
                // number the branch 1, 2, 3... first, then swap.
                $ranks->each(fn (Rank $r, int $i) => $r->update(['sort_order' => $i + 1]));
                [$a, $b] = [$current->sort_order, $neighbour->sort_order];
            }

            $current->update(['sort_order' => $b]);
            $neighbour->update(['sort_order' => $a]);
        });

        return to_route('lists.index');
    }

    /**
     * Add or change an age bracket. The overlap check runs again with the
     * table locked, so two Admins can't add overlapping brackets at once.
     */
    private function saveBracket(ListItemRequest $request, ?AgeBracket $bracket): string
    {
        [$min, $max] = $request->ages();

        return DB::transaction(function () use ($bracket, $min, $max): string {
            AgeBracket::withTrashed()->lockForUpdate()->get(['id']);

            $other = ListItemRules::overlappingBracket($min, $max, $bracket?->id);

            if ($other !== null) {
                throw ValidationException::withMessages(['min_age' => ListItemRules::overlapMessage($other)]);
            }

            if ($bracket === null) {
                $bracket = AgeBracket::create(['min_age' => $min, 'max_age' => $max, 'sort_order' => 0]);

                return "Added age bracket {$bracket->label}";
            }

            $bracket->update(['min_age' => $min, 'max_age' => $max]);

            return "Saved {$bracket->label}";
        });
    }

    /**
     * Save a name. The database's own unique index (which also ignores
     * accents) is the last guard: a clash there becomes the same message
     * as the duplicate check instead of an error page.
     *
     * @param  Closure(): string  $save
     */
    private function saveName(Closure $save): string
    {
        try {
            return $save();
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            throw ValidationException::withMessages(['name' => 'This name is already in the list, or in Trash.']);
        }
    }
}
