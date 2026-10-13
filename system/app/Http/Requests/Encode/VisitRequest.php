<?php

namespace App\Http\Requests\Encode;

use App\Enums\Category;
use App\Enums\Sex;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The encoding form (prototype/prototype.html, "Encode Patient"; rules in
 * planning/mvp-scope.md section 2). Shared by "Save & New" and "Save
 * changes". The browser checks the same things first; this is the check
 * that counts.
 *
 * - Rank must belong to the chosen branch.
 * - Only active list items: branch switched on, rank and diagnosis not in
 *   Trash. When editing, the visit's current item is still accepted.
 * - "Other (specify)" needs the typed value.
 * - Category: ER encoders and Admin pick any; other encoders keep their
 *   office's category.
 *
 * Error messages match the prototype word for word where it has one.
 */
abstract class VisitRequest extends FormRequest
{
    /** The visit being edited, or null for a new one. */
    abstract protected function editedVisit(): ?Visit;

    private ?Branch $branch = null;

    private ?Rank $rank = null;

    private ?Diagnosis $diagnosis = null;

    private bool $loaded = false;

    /**
     * M.I. is saved uppercase, without periods or spaces: "j." becomes "J".
     */
    protected function prepareForValidation(): void
    {
        $mi = $this->input('middle_initial');

        if (is_string($mi)) {
            $mi = mb_strtoupper(str_replace(['.', ' '], '', $mi));
            $this->merge(['middle_initial' => $mi === '' ? null : $mi]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:60'],
            'first_name' => ['required', 'string', 'max:60'],
            'middle_initial' => ['nullable', 'string', 'regex:/^\pL{1,2}$/u'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'birthdate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'age' => ['required', 'integer', 'between:0,120'],
            'branch_id' => ['required', 'integer', $this->branchRule()],
            'rank_id' => ['required', 'integer', $this->rankRule()],
            'rank_other' => [
                Rule::requiredIf(fn (): bool => $this->rank()?->is_other === true),
                'nullable', 'string', 'max:60',
            ],
            'diagnosis_id' => ['required', 'integer', $this->diagnosisRule()],
            'diagnosis_other' => [
                Rule::requiredIf(fn (): bool => $this->diagnosis()?->is_other === true),
                'nullable', 'string', 'max:120',
            ],
            'category' => ['required', Rule::enum(Category::class), $this->categoryRule()],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $type = $this->rankWord();

        return [
            'last_name.required' => 'Enter the last name.',
            'last_name.max' => 'Use at most 60 characters.',
            'first_name.required' => 'Enter the first name.',
            'first_name.max' => 'Use at most 60 characters.',
            'middle_initial.regex' => 'Enter up to 2 letters.',
            'sex.required' => 'Choose Male or Female.',
            'sex.enum' => 'Choose Male or Female.',
            'birthdate.required' => 'Enter the birthdate.',
            'birthdate.date_format' => 'Enter a valid birthdate.',
            'birthdate.before_or_equal' => "The birthdate can't be in the future.",
            'age.*' => 'Enter an age from 0 to 120.',
            'branch_id.required' => 'Choose the branch of service.',
            'branch_id.integer' => 'Choose the branch of service.',
            'rank_id.required' => $type === 'type' ? 'Choose the patient type.' : 'Choose the rank.',
            'rank_id.integer' => $type === 'type' ? 'Choose the patient type.' : 'Choose the rank.',
            'rank_other.required' => $type === 'type' ? 'Type the patient type.' : 'Type the rank.',
            'rank_other.max' => 'Use at most 60 characters.',
            'diagnosis_id.required' => 'Choose a diagnosis.',
            'diagnosis_id.integer' => 'Choose a diagnosis.',
            'diagnosis_other.required' => 'Type the diagnosis.',
            'diagnosis_other.max' => 'Use at most 120 characters.',
            'category.required' => 'Choose the category.',
            'category.enum' => 'Choose OPD, ER or Admission.',
            'remarks.max' => 'Use at most 1,000 characters.',
        ];
    }

    /**
     * The values to save on the visit. "Other (specify)" text is kept only
     * when that entry is chosen.
     *
     * @return array<string, mixed>
     */
    public function visitAttributes(): array
    {
        return [
            'age' => (int) $this->validated('age'),
            'branch_id' => (int) $this->validated('branch_id'),
            'rank_id' => (int) $this->validated('rank_id'),
            'rank_other' => $this->rank()?->is_other ? $this->validated('rank_other') : null,
            'diagnosis_id' => (int) $this->validated('diagnosis_id'),
            'diagnosis_other' => $this->diagnosis()?->is_other ? $this->validated('diagnosis_other') : null,
            'category' => $this->validated('category'),
            'remarks' => $this->validated('remarks'),
        ];
    }

    /**
     * Name, M.I., sex and birthdate live on the patient, so saving them
     * corrects all of that patient's visits (planning/database-schema.md).
     *
     * @return array<string, mixed>
     */
    public function patientAttributes(): array
    {
        return $this->safe()->only(['last_name', 'first_name', 'middle_initial', 'sex', 'birthdate']);
    }

    // --- Rules -----------------------------------------------------------------

    private function branchRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = $this->branch();

            if ($branch === null) {
                $fail('Choose the branch of service.');

                return;
            }

            if (! $branch->is_active && $branch->id !== $this->editedVisit()?->branch_id) {
                $fail('This branch is turned off. Choose another.');
            }
        };
    }

    private function rankRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $rank = $this->rank();
            $branch = $this->branch();
            $word = $this->rankWord();

            if ($rank === null) {
                $fail($word === 'type' ? 'Choose the patient type.' : 'Choose the rank.');

                return;
            }

            if ($branch !== null && $rank->branch_id !== $branch->id) {
                $fail("Choose a {$word} from the {$branch->name} list.");

                return;
            }

            if ($rank->trashed() && $rank->id !== $this->editedVisit()?->rank_id) {
                $fail("This {$word} was removed from the list. Choose another.");
            }
        };
    }

    private function diagnosisRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $diagnosis = $this->diagnosis();

            if ($diagnosis === null) {
                $fail('Choose a diagnosis.');

                return;
            }

            if ($diagnosis->trashed() && $diagnosis->id !== $this->editedVisit()?->diagnosis_id) {
                $fail('This diagnosis was removed from the list. Choose another.');
            }
        };
    }

    /**
     * ER encoders and Admin choose any category. Other encoders keep their
     * office's category (or, when editing, the visit's own).
     */
    private function categoryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var User $user */
            $user = $this->user();

            if ($user->canChooseCategory()) {
                return;
            }

            $allowed = $this->editedVisit()?->category ?? $user->defaultCategory();

            if ($value !== $allowed->value) {
                $fail("The category is set by your office ({$allowed->value}).");
            }
        };
    }

    // --- Lookups (one query each) ---------------------------------------------

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $id = fn (string $key): ?int => filter_var($this->input($key), FILTER_VALIDATE_INT) === false
            ? null
            : (int) $this->input($key);

        $branchId = $id('branch_id');
        $rankId = $id('rank_id');
        $diagnosisId = $id('diagnosis_id');

        $this->branch = $branchId ? Branch::find($branchId) : null;
        $this->rank = $rankId ? Rank::withTrashed()->find($rankId) : null;
        $this->diagnosis = $diagnosisId ? Diagnosis::withTrashed()->find($diagnosisId) : null;
    }

    private function branch(): ?Branch
    {
        $this->load();

        return $this->branch;
    }

    private function rank(): ?Rank
    {
        $this->load();

        return $this->rank;
    }

    private function diagnosis(): ?Diagnosis
    {
        $this->load();

        return $this->diagnosis;
    }

    /**
     * "type" for the Others branch (Dependent, Retiree...), "rank" otherwise.
     */
    private function rankWord(): string
    {
        return $this->branch()?->is_afp === false ? 'type' : 'rank';
    }
}
