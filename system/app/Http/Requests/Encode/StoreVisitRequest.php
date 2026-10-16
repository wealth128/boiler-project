<?php

namespace App\Http\Requests\Encode;

use App\Enums\Permission;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Save & New". With patient_id, the visit is for a returning patient
 * found by the search; without it, a new patient is created.
 *
 * Duplicate guard: a new patient with the same last name, first name and
 * birthdate as a patient already on file (not in Trash) is stopped with
 * "A patient with this name and birthdate already exists". The encoder
 * either uses that patient, or sends confirm_new_patient to save a
 * different person who happens to match.
 */
class StoreVisitRequest extends VisitRequest
{
    public const DUPLICATE_MESSAGE = 'A patient with this name and birthdate already exists.';

    public function authorize(): bool
    {
        if (! Gate::allows(Permission::EncodeVisits->value)) {
            return false;
        }

        // A new visit is dated now. If this month was closed anyway (the
        // clock was moved, or it was closed early), it can't be added.
        $period = now()->format('Y-m');

        if (MonthClosure::isClosed($period)) {
            abort(403, MonthClosure::closedMessage($period));
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Patients in Trash can't get new visits: restore them first.
            'patient_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->withoutTrashed()],
            'confirm_new_patient' => ['sometimes', 'boolean'],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'patient_id.*' => 'This patient is no longer available. Search again, or clear the form to encode a new patient.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('patient_id') || $this->boolean('confirm_new_patient')) {
                    return;
                }

                $errors = $validator->errors();

                if ($errors->hasAny(['last_name', 'first_name', 'birthdate'])) {
                    return;
                }

                // The database comparison ignores upper / lower case. The save
                // checks again under a lock (VisitController), for two encoders
                // saving the same new patient at the same moment.
                $exists = Patient::identityExists(
                    (string) $this->input('last_name'),
                    (string) $this->input('first_name'),
                    (string) $this->input('birthdate'),
                );

                if ($exists) {
                    $errors->add('duplicate_patient', self::DUPLICATE_MESSAGE);
                }
            },
        ];
    }

    protected function editedVisit(): ?Visit
    {
        return null;
    }
}
