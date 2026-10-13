<?php

namespace App\Http\Controllers\Encode;

use App\Enums\Category;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encode Patient screen (prototype/prototype.html, "Encode Patient"): the
 * encoding form and "My entries today".
 *
 * /encode?edit={visit} loads that visit into the same form ("Editing
 * V-…"). VisitPolicy decides who may edit it.
 */
class EncodeController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $editing = $this->visitToEdit($request);

        return Inertia::render('encode/index', [
            'office' => $user->office->value,
            'categories' => Category::values(),
            'defaultCategory' => $user->defaultCategory()->value,
            'canChooseCategory' => $user->canChooseCategory(),
            ...$this->lists($editing),
            // A preview only: the real Patient ID is set when the patient is saved.
            'nextPatientNo' => Patient::formatNumber(((int) Patient::withTrashed()->max('id')) + 1),
            'todayEntries' => $this->todayEntries($user),
            'editing' => $editing ? $this->editingProps($editing) : null,
        ]);
    }

    private function visitToEdit(Request $request): ?Visit
    {
        if (! $request->filled('edit')) {
            return null;
        }

        $id = filter_var($request->query('edit'), FILTER_VALIDATE_INT);
        abort_if($id === false, 404);

        // Visits in Trash can't be edited: findOrFail skips them.
        $visit = Visit::query()->with('patient')->findOrFail($id);
        Gate::authorize('update', $visit);

        return $visit;
    }

    /**
     * Only active items are offered: branches switched on, ranks and
     * diagnoses not in Trash. When editing, the visit's own branch, rank and
     * diagnosis are added even if they were removed later, so the form can
     * still show them.
     *
     * @return array{branches: list<array<string, mixed>>, ranks: list<array<string, mixed>>, diagnoses: list<array<string, mixed>>}
     */
    private function lists(?Visit $editing): array
    {
        $branches = Branch::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $editing?->branch_id))
            ->ordered()
            ->get(['id', 'name', 'is_afp']);

        $ranks = Rank::withTrashed()
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $editing?->rank_id))
            ->ordered()
            ->get(['id', 'branch_id', 'name', 'is_other']);

        $diagnoses = Diagnosis::withTrashed()
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $editing?->diagnosis_id))
            ->ordered()
            ->get(['id', 'name', 'is_other']);

        return [
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_afp' => $branch->is_afp,
            ])->values()->all(),
            'ranks' => $ranks->map(fn (Rank $rank): array => [
                'id' => $rank->id,
                'branch_id' => $rank->branch_id,
                'name' => $rank->name,
                'is_other' => $rank->is_other,
            ])->values()->all(),
            'diagnoses' => $diagnoses->map(fn (Diagnosis $diagnosis): array => [
                'id' => $diagnosis->id,
                'name' => $diagnosis->name,
                'is_other' => $diagnosis->is_other,
            ])->values()->all(),
        ];
    }

    /**
     * This user's visits today (Asia/Manila), newest first. Visits in
     * Trash don't show.
     *
     * @return list<array<string, mixed>>
     */
    private function todayEntries(User $user): array
    {
        $gate = Gate::forUser($user);

        return Visit::query()
            ->where('encoded_by', $user->id)
            ->whereBetween('visited_at', [now()->startOfDay(), now()->endOfDay()])
            ->with(['patient', 'diagnosis'])
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Visit $visit): array => [
                'id' => $visit->id,
                'visit_no' => $visit->visit_no,
                'visited_at' => $visit->visited_at->toIso8601String(),
                'patient_name' => $visit->patient->full_name,
                'category' => $visit->category->value,
                'diagnosis' => $visit->diagnosis->is_other
                    ? "Other: {$visit->diagnosis_other}"
                    : $visit->diagnosis->name,
                'can_edit' => $gate->allows('update', $visit),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function editingProps(Visit $visit): array
    {
        /** @var Patient $patient */
        $patient = $visit->patient;

        return [
            'id' => $visit->id,
            'visit_no' => $visit->visit_no,
            'visited_at' => $visit->visited_at->toIso8601String(),
            'patient' => [
                'id' => $patient->id,
                'patient_no' => $patient->patient_no,
                'last_name' => $patient->last_name,
                'first_name' => $patient->first_name,
                'middle_initial' => $patient->middle_initial,
                'sex' => $patient->sex->value,
                'birthdate' => $patient->birthdate->format('Y-m-d'),
                'full_name' => $patient->full_name,
            ],
            'age' => $visit->age,
            'branch_id' => $visit->branch_id,
            'rank_id' => $visit->rank_id,
            'rank_other' => $visit->rank_other,
            'diagnosis_id' => $visit->diagnosis_id,
            'diagnosis_other' => $visit->diagnosis_other,
            'category' => $visit->category->value,
            'remarks' => $visit->remarks,
        ];
    }
}
