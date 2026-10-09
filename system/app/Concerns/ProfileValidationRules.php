<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A user may change only their own name. Username, role and office are set
 * by Admin / System Admin, and accounts have no email to manage.
 */
trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(): array
    {
        return [
            'name' => $this->nameRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     * 100 characters matches the users.name column.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:100'];
    }
}
