<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every Feature test runs on a freshly migrated MySQL test database,
| patient_census_test (see phpunit.xml). Never point it at patient_census.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * An active account with the given role (and that role's usual office).
 * Its password is "password".
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(Role|string $role, array $attributes = []): User
{
    $factory = User::factory();

    $factory = match (Role::from($role instanceof Role ? $role->value : $role)) {
        Role::Encoder => $factory->encoder(),
        Role::Admin => $factory->admin(),
        Role::SystemAdmin => $factory->systemAdmin(),
        Role::Viewer => $factory->viewer(),
    };

    return $factory->create($attributes);
}
