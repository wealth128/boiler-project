<?php

namespace App\Http\Requests\Users;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * "Add user". The password is temporary: the user must choose their own
 * right after logging in (User::setTemporaryPassword()).
 */
class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules, UserFormRules;

    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->accountRules(),
            'password' => $this->passwordRules(),
        ];
    }

    protected function editedUser(): ?User
    {
        return null;
    }
}
