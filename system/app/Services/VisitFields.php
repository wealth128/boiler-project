<?php

namespace App\Services;

use App\Enums\Category;
use App\Enums\Sex;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Rank;
use App\Models\Visit;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The rules for the fields of one visit (planning/mvp-scope.md section 2),
 * in one place. The Encode form (App\Http\Requests\Encode\VisitRequest) and the
 * downtime import (App\Services\Import\ImportAnalyzer) both validate with
 * these rules, so a visit is accepted or refused the same way from either.
 *
 * - Rank must belong to the chosen branch.
 * - Only active list items: branch switched on, rank and diagnosis not in
 *   Trash. When editing, the visit's current item is still accepted.
 * - "Other (specify)" needs the typed value.
 *
 * The category rule depends on who is signed in (Encode only), so the caller
 * passes it in.
 *
 * Error messages match the prototype word for word where it has one.
 */
final class VisitFields
{
    private ?Branch $branch = null;

    private ?Rank $rank = null;

    private ?Diagnosis $diagnosis = null;

    private bool $loaded = false;

    /**
     * @param  array<string, mixed>  $input  The form values (after normalize())
     * @param  Visit|null  $edited  The visit being edited, or null for a new one
     * @param  array{branches: array<int, Branch>, ranks: array<int, Rank>, diagnoses: array<int, Diagnosis>}|null  $lists  The lists already
     *                                                                                                                      loaded by the caller (id => item, Trash included), so a file of
     *                                                                                                                      2,000 rows does not ask the database three times per row
     */
    public function __construct(private readonly array $input, private readonly ?Visit $edited = null, private readonly ?array $lists = null) {}

    /**
     * M.I. is saved uppercase, without periods or spaces: "j." becomes "J".
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $mi = $input['middle_initial'] ?? null;

        if (is_string($mi)) {
            $mi = mb_strtoupper(str_replace(['.', ' '], '', $mi));
            $input['middle_initial'] = $mi === '' ? null : $mi;
        }

        return $input;
    }

    /**
     * @param  Closure|null  $categoryRule  Extra check on the category (Encode: the office's category)
     * @return array<string, mixed>
     */
    public function rules(?Closure $categoryRule = null): array
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
            'category' => array_values(array_filter(['required', Rule::enum(Category::class), $categoryRule])),
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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function visitAttributes(array $validated): array
    {
        return [
            'age' => (int) $validated['age'],
            'branch_id' => (int) $validated['branch_id'],
            'rank_id' => (int) $validated['rank_id'],
            'rank_other' => $this->rank()?->is_other ? ($validated['rank_other'] ?? null) : null,
            'diagnosis_id' => (int) $validated['diagnosis_id'],
            'diagnosis_other' => $this->diagnosis()?->is_other ? ($validated['diagnosis_other'] ?? null) : null,
            'category' => $validated['category'],
            'remarks' => $validated['remarks'] ?? null,
        ];
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

            if (! $branch->is_active && $branch->id !== $this->edited?->branch_id) {
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

            if ($rank->trashed() && $rank->id !== $this->edited?->rank_id) {
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

            if ($diagnosis->trashed() && $diagnosis->id !== $this->edited?->diagnosis_id) {
                $fail('This diagnosis was removed from the list. Choose another.');
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
        $id = fn (string $key): ?int => filter_var($this->input[$key] ?? null, FILTER_VALIDATE_INT) === false
            ? null
            : (int) $this->input[$key];

        $branchId = $id('branch_id');
        $rankId = $id('rank_id');
        $diagnosisId = $id('diagnosis_id');

        if ($this->lists !== null) {
            $this->branch = $branchId ? ($this->lists['branches'][$branchId] ?? null) : null;
            $this->rank = $rankId ? ($this->lists['ranks'][$rankId] ?? null) : null;
            $this->diagnosis = $diagnosisId ? ($this->lists['diagnoses'][$diagnosisId] ?? null) : null;

            return;
        }

        $this->branch = $branchId ? Branch::find($branchId) : null;
        $this->rank = $rankId ? Rank::withTrashed()->find($rankId) : null;
        $this->diagnosis = $diagnosisId ? Diagnosis::withTrashed()->find($diagnosisId) : null;
    }

    public function branch(): ?Branch
    {
        $this->load();

        return $this->branch;
    }

    public function rank(): ?Rank
    {
        $this->load();

        return $this->rank;
    }

    public function diagnosis(): ?Diagnosis
    {
        $this->load();

        return $this->diagnosis;
    }

    /**
     * "type" for the Others branch (Dependent, Retiree...), "rank" otherwise.
     */
    public function rankWord(): string
    {
        return $this->branch()?->is_afp === false ? 'type' : 'rank';
    }
}
