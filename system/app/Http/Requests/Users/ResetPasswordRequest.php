<?php

namespace App\Http\Requests\Users;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * "Reset password" (planning/rbac.md): Admin or System Admin re-enters
 * their own password, then sets a temporary password for the user.
 */
class ResetPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): Response
    {
        /** @var User $user */
        $user = $this->route('user');

        return Gate::inspect('resetPassword', $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => $this->passwordRules(),
            'own_password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'own_password.required' => 'Enter your own password to confirm.',
            'own_password.current_password' => 'Your own password is wrong.',
        ];
    }
}
