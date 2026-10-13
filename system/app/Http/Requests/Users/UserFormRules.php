<?php

namespace App\Http\Requests\Users;

use App\Enums\Office;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Fields shared by "Add user" and "Edit user" (planning/rbac.md):
 * name, username, role and office.
 *
 * - Usernames are lowercase: letters, numbers, dot, dash, underscore. They
 *   must be unique, also against accounts in Trash (they can come back).
 * - Encoders pick an office. OPD and Admission are off in v1.0
 *   (Office::encodingOn()), except for an account that already has one.
 *   Other roles get their fixed office (Role::fixedOffice()).
 * - Admin and System Admin: only one active each (UserPolicy).
 */
trait UserFormRules
{
    /** The account being edited, or null when adding one. */
    abstract protected function editedUser(): ?User;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'username' => mb_strtolower(trim((string) $this->input('username'))),
        ]);

        // The office follows the role, except for encoders.
        $role = Role::tryFrom((string) $this->input('role'));

        if ($role?->fixedOffice() !== null) {
            $this->merge(['office' => $role->fixedOffice()->value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountRules(): array
    {
        $user = $this->editedUser();

        $offices = array_map(fn (Office $office) => $office->value, array_filter(
            Office::forEncoders(),
            fn (Office $office) => $office->encodingOn() || $office === $user?->office,
        ));

        return [
            'name' => ['required', 'string', 'max:100'],
            'username' => [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'role' => ['required', Rule::enum(Role::class)],
            'office' => [
                'required',
                Rule::when($this->input('role') === Role::Encoder->value, [Rule::in($offices)], [Rule::enum(Office::class)]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'Use only lowercase letters, numbers, dots, dashes or underscores.',
            'username.unique' => 'This username is already used (accounts in Trash count too).',
            'office.in' => 'Choose ER. OPD and Admission encoding is off in v1.0.',
        ];
    }

    /**
     * Role rules that need the database: one active Admin / System Admin,
     * nobody changes their own role, the System Admin can't touch the Admin.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('role')) {
                    return;
                }

                $user = $this->editedUser();
                $role = Role::from((string) $this->input('role'));

                if ($user !== null && $role === $user->role) {
                    return;
                }

                $checks = $user !== null ? [Gate::inspect('changeRole', $user)] : [];

                if ($role === Role::Admin) {
                    $checks[] = Gate::inspect('makeAdmin', [User::class, $user]);
                }

                if ($role === Role::SystemAdmin) {
                    $checks[] = Gate::inspect('makeSystemAdmin', [User::class, $user]);
                }

                foreach ($checks as $check) {
                    if ($check->denied()) {
                        $validator->errors()->add('role', $check->message() ?? "You can't give this role.");

                        return;
                    }
                }
            },
        ];
    }
}
