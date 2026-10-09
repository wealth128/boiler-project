<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * UserPolicy (planning/rbac.md): nobody deletes their own account or the
 * Admin account; only one active Admin; Admin and System Admin otherwise
 * have the same rights over accounts.
 */

function assertUserActionDenied($response, string $message): void
{
    $response->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('message', $message));
}

// --- Delete ----------------------------------------------------------------

it("doesn't let anyone delete their own account", function (string $role) {
    $actor = userWithRole($role);

    assertUserActionDenied(
        $this->actingAs($actor)->delete("/users/{$actor->id}"),
        "You can't delete your own account.",
    );
})->with(['admin', 'system_admin']);

it("doesn't let the System Admin delete the Admin account", function () {
    $admin = userWithRole('admin');

    assertUserActionDenied(
        $this->actingAs(userWithRole('system_admin'))->delete("/users/{$admin->id}"),
        "The Admin account can't be deleted.",
    );
});

it('lets Admin and System Admin delete other accounts', function (string $role) {
    $encoder = userWithRole('encoder');
    $systemAdmin = User::factory()->systemAdmin()->create();

    $this->actingAs(userWithRole($role))->from('/users')
        ->delete("/users/{$encoder->id}")
        ->assertRedirect('/users');

    if ($role === 'admin') {
        $this->delete("/users/{$systemAdmin->id}")->assertRedirect('/users');
    }
})->with(['admin', 'system_admin']);

// --- One active Admin -----------------------------------------------------

it('refuses a second active Admin', function () {
    $admin = userWithRole('admin');
    $encoder = userWithRole('encoder');
    $sysadmin = userWithRole('system_admin');

    $newAccount = Gate::forUser($sysadmin)->inspect('makeAdmin', [User::class]);
    $promote = Gate::forUser($sysadmin)->inspect('makeAdmin', [User::class, $encoder]);

    expect($newAccount->denied())->toBeTrue()
        ->and($newAccount->message())->toBe('There is already an active Admin account. Only one is allowed.')
        ->and($promote->denied())->toBeTrue()
        // The Admin account itself keeps its role when edited.
        ->and(Gate::forUser($sysadmin)->allows('makeAdmin', [User::class, $admin]))->toBeTrue();
});

it('allows a new Admin once the old one is deactivated', function () {
    userWithRole('admin', ['is_active' => false]);

    expect(Gate::forUser(userWithRole('system_admin'))->allows('makeAdmin', [User::class]))->toBeTrue();
});

it('refuses to activate a second Admin account', function () {
    userWithRole('admin');
    $oldAdmin = userWithRole('admin', ['is_active' => false]);

    assertUserActionDenied(
        $this->actingAs(userWithRole('system_admin'))->post("/users/{$oldAdmin->id}/toggle-active"),
        'There is already an active Admin account. Only one is allowed.',
    );
});

it('lets the System Admin deactivate the Admin, so a new Admin can take over', function () {
    $admin = userWithRole('admin');

    $this->actingAs(userWithRole('system_admin'))->from('/users')
        ->post("/users/{$admin->id}/toggle-active")
        ->assertRedirect('/users');
});

// --- Other account actions ---------------------------------------------

it("doesn't let anyone deactivate their own account", function () {
    $admin = userWithRole('admin');

    assertUserActionDenied(
        $this->actingAs($admin)->post("/users/{$admin->id}/toggle-active"),
        "You can't deactivate your own account.",
    );
});

it('sends users to Settings to change their own password', function () {
    $sysadmin = userWithRole('system_admin');

    assertUserActionDenied(
        $this->actingAs($sysadmin)->post("/users/{$sysadmin->id}/reset-password"),
        'To change your own password, use Settings > Password.',
    );
});

it("lets the System Admin unlock the Admin and reset the Admin's password", function () {
    $admin = userWithRole('admin');
    $this->actingAs(userWithRole('system_admin'))->from('/users');

    $this->post("/users/{$admin->id}/unlock")->assertRedirect('/users');
    $this->post("/users/{$admin->id}/reset-password")->assertRedirect('/users');
});

it('gives encoders and viewers no account rights', function (string $role) {
    $actor = userWithRole($role);
    $other = userWithRole('encoder');
    $gate = Gate::forUser($actor);

    foreach (['update', 'delete', 'unlock', 'resetPassword', 'toggleActive'] as $ability) {
        expect($gate->allows($ability, $other))->toBeFalse("{$role} {$ability}");
    }

    expect($gate->allows('viewAny', User::class))->toBeFalse()
        ->and($gate->allows('create', User::class))->toBeFalse()
        ->and($gate->allows('makeAdmin', [User::class]))->toBeFalse();
})->with(['encoder', 'viewer']);
