<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * "Choose your new password" after logging in with a temporary one. The
 * user just typed the temporary password to log in, so it is not asked
 * again. The new one must follow the password rule and differ from it.
 */
class ChoosePasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => [
                ...$this->passwordRules(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    /** @var User $user */
                    $user = $this->user();

                    if (is_string($value) && Hash::check($value, $user->password)) {
                        $fail("Your new password can't be the same as the temporary one.");
                    }
                },
            ],
        ];
    }
}
