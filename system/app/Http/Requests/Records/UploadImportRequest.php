<?php

namespace App\Http\Requests\Records;

use App\Enums\Permission;
use App\Services\Import\ImportColumns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * "Upload and check" on Records > Import: one .xlsx or .csv file, at most
 * 2 MB. Whether the rows are good is decided in the preview
 * (App\Services\Import\ImportAnalyzer), not here.
 */
class UploadImportRequest extends FormRequest
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
        return [
            'file' => ['required', 'file', 'max:'.ImportColumns::MAX_KILOBYTES, 'extensions:xlsx,csv'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the file to import.',
            'file.file' => "That file could not be uploaded. Try again.",
            'file.uploaded' => 'That file could not be uploaded. It may be bigger than 2 MB.',
            'file.max' => 'The file is bigger than 2 MB. Split it into smaller files.',
            'file.extensions' => 'Use an Excel (.xlsx) or a CSV (.csv) file.',
        ];
    }

    /** "xlsx" or "csv", from the name the browser sent (already checked by the rule above). */
    public function extension(): string
    {
        return strtolower((string) $this->file('file')?->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
    }
}
