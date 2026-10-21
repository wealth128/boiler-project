<?php

namespace App\Http\Requests\Encode;

use App\Models\User;
use App\Models\Visit;
use App\Services\VisitFields;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * The encoding form (prototype/prototype.html, "Encode Patient"; rules in
 * planning/mvp-scope.md section 2). Shared by "Save & New" and "Save
 * changes". The browser checks the same things first; this is the check
 * that counts.
 *
 * The field rules and messages live in App\Services\VisitFields, which the
 * downtime import uses too. What stays here is the one rule that depends on
 * who is signed in:
 * - Category: ER encoders and Admin pick any; other encoders keep their
 *   office's category.
 */
abstract class VisitRequest extends FormRequest
{
    /** The visit being edited, or null for a new one. */
    abstract protected function editedVisit(): ?Visit;

    private ?VisitFields $fields = null;

    /**
     * M.I. is saved uppercase, without periods or spaces: "j." becomes "J".
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('middle_initial'))) {
            $this->merge(Arr::only(VisitFields::normalize($this->all()), ['middle_initial']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->fields()->rules($this->categoryRule());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->fields()->messages();
    }

    /**
     * The values to save on the visit. "Other (specify)" text is kept only
     * when that entry is chosen.
     *
     * @return array<string, mixed>
     */
    public function visitAttributes(): array
    {
        return $this->fields()->visitAttributes($this->validated());
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

    private function fields(): VisitFields
    {
        return $this->fields ??= new VisitFields($this->all(), $this->editedVisit());
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
}
