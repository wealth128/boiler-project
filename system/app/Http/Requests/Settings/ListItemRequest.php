<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Rank;
use App\Services\ListItemRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add (POST /settings/{list}) or edit (PUT /settings/{list}/{id}) one
 * dropdown list item. {list} is diagnoses, ranks or age-brackets.
 *
 * Every check on the Lists screen is repeated here (ListItemRules):
 * - Names: trimmed, inner spaces collapsed, 2 to 120 characters (ranks 2
 *   to 60), unique in the list ignoring case (ranks: within the branch).
 *   A matching name in Trash: "“X” is in Trash. Restore it from Trash
 *   instead."
 * - Age brackets: 0 to 120, From not above To, blank To = "and above", no
 *   overlap with another active bracket.
 * - "Other (specify)" can't be edited (403).
 * - Items in Trash can't be edited (404).
 * - A rank stays in its branch: branch_id is read only when adding.
 */
class ListItemRequest extends FormRequest
{
    private Diagnosis|Rank|AgeBracket|null $editedItem = null;

    private bool $loaded = false;

    public function authorize(): Response|bool
    {
        if (! Gate::allows(Permission::ManageLists->value)) {
            return false;
        }

        $item = $this->editedItem();

        if (($item instanceof Diagnosis || $item instanceof Rank) && $item->is_other) {
            return Response::deny(ListItemRules::FIXED_MESSAGE);
        }

        return true;
    }

    /**
     * Names are compared and saved trimmed, with inner spaces collapsed.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => ListItemRules::normalizeName($name)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->list()) {
            'diagnoses' => [
                'name' => ['required', 'string', 'min:'.ListItemRules::NAME_MIN, 'max:'.ListItemRules::DIAGNOSIS_NAME_MAX],
            ],
            'ranks' => [
                'name' => ['required', 'string', 'min:'.ListItemRules::NAME_MIN, 'max:'.ListItemRules::RANK_NAME_MAX],
                ...($this->isEdit() ? [] : [
                    'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
                ]),
            ],
            'age-brackets' => [
                'min_age' => ['required', 'integer', 'between:'.ListItemRules::AGE_MIN.','.ListItemRules::AGE_MAX],
                'max_age' => ['nullable', 'integer', 'between:'.ListItemRules::AGE_MIN.','.ListItemRules::AGE_MAX],
            ],
            default => abort(404),
        };
    }

    /**
     * Same wording as the prototype's Lists tab.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $empty = match (true) {
            $this->isEdit() => "The name can't be empty.",
            $this->list() === 'ranks' => 'Type the rank or patient type to add.',
            default => 'Type the diagnosis to add.',
        };

        $max = $this->list() === 'ranks' ? ListItemRules::RANK_NAME_MAX : ListItemRules::DIAGNOSIS_NAME_MAX;

        return [
            'name.required' => $empty,
            'name.string' => $empty,
            'name.min' => 'Use '.ListItemRules::NAME_MIN." to {$max} characters.",
            'name.max' => 'Use '.ListItemRules::NAME_MIN." to {$max} characters.",
            'branch_id.*' => 'Choose a branch.',
            'min_age.*' => 'Enter a starting age from 0 to 120.',
            'max_age.*' => 'Enter an ending age from 0 to 120, or leave it blank for "and above".',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = $validator->errors();

                if ($errors->isNotEmpty()) {
                    return;
                }

                if ($this->list() === 'age-brackets') {
                    [$min, $max] = $this->ages();

                    if ($max !== null && $max < $min) {
                        $errors->add('max_age', 'The ending age must be the same as or higher than the starting age.');

                        return;
                    }

                    $other = ListItemRules::overlappingBracket($min, $max, $this->editedItem()?->id);

                    if ($other !== null) {
                        $errors->add('min_age', ListItemRules::overlapMessage($other));
                    }

                    return;
                }

                $match = $this->duplicateItem();

                if ($match !== null) {
                    $errors->add('name', ListItemRules::duplicateMessage($match));
                }
            },
        ];
    }

    /** diagnoses, ranks or age-brackets (limited by the route). */
    public function list(): string
    {
        return (string) $this->route('list');
    }

    public function isEdit(): bool
    {
        return $this->route('id') !== null;
    }

    /**
     * The item being edited (404 when it is missing or in Trash), or null
     * when adding.
     */
    public function editedItem(): Diagnosis|Rank|AgeBracket|null
    {
        if (! $this->loaded) {
            $this->loaded = true;

            if ($this->isEdit()) {
                $class = match ($this->list()) {
                    'diagnoses' => Diagnosis::class,
                    'ranks' => Rank::class,
                    'age-brackets' => AgeBracket::class,
                    default => abort(404),
                };

                $this->editedItem = $class::query()->findOrFail((int) $this->route('id'));
            }
        }

        return $this->editedItem;
    }

    /**
     * The branch a rank is added to, or the branch of the rank being edited.
     */
    public function branch(): Branch
    {
        $item = $this->editedItem();

        if ($item instanceof Rank) {
            return $item->branch;
        }

        // Checked by rules() (exists) before this is used.
        return Branch::query()->findOrFail((int) $this->input('branch_id'));
    }

    /**
     * From and To as numbers; To is null for "and above".
     *
     * @return array{0: int, 1: int|null}
     */
    public function ages(): array
    {
        $max = $this->input('max_age');

        return [(int) $this->input('min_age'), $max === null ? null : (int) $max];
    }

    /** The cleaned name. */
    public function itemName(): string
    {
        return (string) $this->input('name');
    }

    private function duplicateItem(): Diagnosis|Rank|null
    {
        $exceptId = $this->editedItem()?->id;

        return $this->list() === 'ranks'
            ? ListItemRules::duplicateRank($this->branch()->id, $this->itemName(), $exceptId)
            : ListItemRules::duplicateDiagnosis($this->itemName(), $exceptId);
    }
}
