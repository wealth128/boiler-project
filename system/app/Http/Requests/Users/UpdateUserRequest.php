<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * "Edit user": name, username, role and office. Passwords are changed with
 * "Reset password" only.
 */
class UpdateUserRequest extends FormRequest
{
    use UserFormRules;

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->editedUser());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->accountRules();
    }

    protected function editedUser(): User
    {
        /** @var User */
        return $this->route('user');
    }
}
