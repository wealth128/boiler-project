<?php

namespace App\Http\Requests\Settings;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Hospital settings form (Admin only). Text is trimmed. The logo and the two
 * signature pictures are checked by their real content, not their file name:
 * only PNG or JPG, at most 1 MB and at most 1000 x 1000 pixels (an SVG can
 * carry scripts, so it is refused).
 */
class UpdateHospitalRequest extends FormRequest
{
    public const LOGO_MAX_KB = 1024;

    public const LOGO_MAX_PIXELS = 1000;

    /** The picture fields and what each is called in a message. */
    private const IMAGE_NAMES = ['logo' => 'logo', 'prepared_signature' => 'signature', 'noted_signature' => 'signature'];

    public function authorize(): bool
    {
        return Gate::allows(Permission::ManageSettings->value);
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['hospital_name', 'report_office', 'prepared_by', 'prepared_by_title', 'noted_by', 'noted_by_title'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $trimmed[$key] = trim($value);
            }
        }

        $this->merge($trimmed);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hospital_name' => ['required', 'string', 'min:2', 'max:150'],
            'report_office' => ['nullable', 'string', 'max:150'],
            'prepared_by' => ['nullable', 'string', 'max:100'],
            'prepared_by_title' => ['nullable', 'string', 'max:100'],
            'noted_by' => ['nullable', 'string', 'max:100'],
            'noted_by_title' => ['nullable', 'string', 'max:100'],
            'logo' => $this->imageRules(),
            'prepared_signature' => $this->imageRules(),
            'noted_signature' => $this->imageRules(),
        ];
    }

    /** @return list<string> */
    private function imageRules(): array
    {
        return [
            'nullable', 'file',
            'mimetypes:image/png,image/jpeg',
            'mimes:png,jpg,jpeg',
            'max:'.self::LOGO_MAX_KB,
            'dimensions:max_width='.self::LOGO_MAX_PIXELS.',max_height='.self::LOGO_MAX_PIXELS,
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'hospital_name' => 'hospital name',
            'report_office' => 'office line',
            'prepared_by' => 'prepared by name',
            'prepared_by_title' => 'prepared by title',
            'noted_by' => 'noted by name',
            'noted_by_title' => 'noted by title',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];

        foreach (self::IMAGE_NAMES as $field => $noun) {
            $messages += [
                "{$field}.mimetypes" => "The {$noun} must be a PNG or JPG picture.",
                "{$field}.mimes" => "The {$noun} must be a PNG or JPG picture.",
                "{$field}.max" => "The {$noun} must be 1 MB or smaller.",
                "{$field}.dimensions" => "The {$noun} must be at most 1000 × 1000 pixels.",
                "{$field}.uploaded" => "The {$noun} could not be uploaded. It must be 1 MB or smaller.",
            ];
        }

        return $messages;
    }
}
