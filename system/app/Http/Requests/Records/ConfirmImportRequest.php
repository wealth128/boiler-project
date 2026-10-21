<?php

namespace App\Http\Requests\Records;

use App\Enums\Permission;
use App\Services\Import\ImportColumns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * "Import N visits": the file's token, the row numbers Admin ticked, and the
 * rows to save as a new patient. The rows themselves are NOT sent: the server
 * reads the file again and checks every row (ImportService::confirm).
 */
class ConfirmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows(Permission::ImportVisits->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $row = ['integer', 'min:2', 'max:'.(ImportColumns::MAX_ROWS * 10)];

        return [
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{40}$/'],
            'rows' => ['required', 'array', 'min:1', 'max:'.ImportColumns::MAX_ROWS],
            'rows.*' => $row,
            'new_patient_rows' => ['nullable', 'array', 'max:'.ImportColumns::MAX_ROWS],
            'new_patient_rows.*' => $row,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.*' => 'That file is no longer available. Upload it again.',
            'rows.required' => 'Tick at least one row to import.',
            'rows.min' => 'Tick at least one row to import.',
            'rows.*' => 'Tick rows from the preview.',
            'new_patient_rows.*' => 'Tick rows from the preview.',
        ];
    }

    /** @return list<int> */
    public function rowNumbers(): array
    {
        return array_map('intval', array_values((array) $this->validated('rows')));
    }

    /** @return list<int> */
    public function newPatientRowNumbers(): array
    {
        return array_map('intval', array_values((array) ($this->validated('new_patient_rows') ?? [])));
    }
}
