<?php

namespace App\Http\Requests\Audit;

use App\Enums\Permission;
use App\Services\AuditActions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The Audit log filters from the URL (?from=&to=&user=&action=&record=).
 *
 * A bad value is not an error page: that one filter falls back to its
 * default (filters()). With no From / To in the URL the range is the first
 * day of last month to today. An empty From or To means no limit.
 */
class AuditLogFilterRequest extends FormRequest
{
    public const RECORD_MAX = 60;

    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows(Permission::ViewAudit->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'to' => ['nullable', 'string', 'date_format:Y-m-d'],
            'user' => ['nullable', 'integer', 'min:1', Rule::exists('users', 'id')],
            'action' => ['nullable', 'string', Rule::in(AuditActions::codes())],
            'record' => ['nullable', 'string', 'max:'.self::RECORD_MAX],
        ];
    }

    /** Never an error page: filters() falls back per field. */
    protected function failedValidation(Validator $validator): void {}

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    /**
     * @return array{from: string, to: string, user: string, action: string, record: string}
     */
    public function filters(): array
    {
        $bad = $this->getValidatorInstance()->errors()->keys();

        $text = function (string $key) use ($bad): string {
            $value = $this->query($key);

            return in_array($key, $bad, true) || ! is_string($value) ? '' : trim($value);
        };

        return [
            'from' => $this->dateFilter('from', now()->subMonthNoOverflow()->startOfMonth()->toDateString(), $bad),
            'to' => $this->dateFilter('to', now()->toDateString(), $bad),
            'user' => $text('user'),
            'action' => $text('action'),
            'record' => $text('record'),
        ];
    }

    /**
     * Missing, bad, or a list: the default. Present but empty: no limit.
     *
     * @param  list<string>  $bad
     */
    private function dateFilter(string $key, string $default, array $bad): string
    {
        if (! $this->query->has($key) || in_array($key, $bad, true)) {
            return $default;
        }

        return (string) $this->query($key);
    }
}
