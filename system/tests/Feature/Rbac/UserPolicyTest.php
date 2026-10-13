<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * UserPolicy (planning/rbac.md): nobody deletes their own account or the
 * Admin account; nobody changes their own role; the System Admin can't
 * edit, deactivate, re-role or reset the Admin (unlock is allowed); only
 * one active Admin.
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
    $admin = userWithRole('admin');
    $oldAdmin = userWithRole('admin', ['is_active' => false]);

    assertUserActionDenied(
        $this->actingAs($admin)->post("/users/{$oldAdmin->id}/toggle-active"),
        'There is already an active Admin account. Only one is allowed.',
    );
});

// --- Admin account protected from the System Admin (rbac.md) -----------

it("doesn't let the System Admin edit or deactivate the Admin", function () {
    $admin = userWithRole('admin');
    $this->actingAs(userWithRole('system_admin'));

    assertUserActionDenied($this->put("/users/{$admin->id}"), "The System Admin can't change the Admin account.");
    assertUserActionDenied($this->post("/users/{$admin->id}/toggle-active"), "The System Admin can't change the Admin account.");

    expect($admin->fresh()->is_active)->toBeTrue();
});

it("doesn't let the System Admin reset the Admin's password", function () {
    $admin = userWithRole('admin');

    assertUserActionDenied(
        $this->actingAs(userWithRole('system_admin'))->post("/users/{$admin->id}/reset-password"),
        "The System Admin can't reset the Admin's password. If the Admin forgot it, IT resets it on the server.",
    );
});

it("doesn't let the System Admin change the Admin's role", function () {
    $admin = userWithRole('admin');

    $result = Gate::forUser(userWithRole('system_admin'))->inspect('changeRole', $admin);

    expect($result->denied())->toBeTrue()
        ->and($result->message())->toBe("The System Admin can't change the Admin account.");
});

it('still lets the System Admin unlock a locked-out Admin', function () {
    $admin = userWithRole('admin', ['failed_attempts' => 5, 'locked_at' => now()]);

    $this->actingAs(userWithRole('system_admin'))->from('/users')
        ->post("/users/{$admin->id}/unlock")
        ->assertRedirect('/users');
});

it('lets the System Admin manage every other account', function () {
    $encoder = userWithRole('encoder');
    $sysadmin = userWithRole('system_admin');
    $gate = Gate::forUser($sysadmin);

    foreach (['update', 'changeRole', 'resetPassword', 'toggleActive', 'delete', 'unlock'] as $ability) {
        expect($gate->allows($ability, $encoder))->toBeTrue($ability);
    }
});

it("doesn't let anyone change their own role", function (string $role) {
    $actor = userWithRole($role);

    $result = Gate::forUser($actor)->inspect('changeRole', $actor);

    expect($result->denied())->toBeTrue()
        ->and($result->message())->toBe("You can't change your own role.");
})->with(['admin', 'system_admin']);

it('lets the Admin edit their own account and change other roles', function () {
    $admin = userWithRole('admin');
    $encoder = userWithRole('encoder');
    $gate = Gate::forUser($admin);

    expect($gate->allows('update', $admin))->toBeTrue()
        ->and($gate->allows('changeRole', $encoder))->toBeTrue()
        ->and($gate->allows('changeRole', userWithRole('system_admin')))->toBeTrue();
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

it('gives encoders and viewers no account rights', function (string $role) {
    $actor = userWithRole($role);
    $other = userWithRole('encoder');
    $gate = Gate::forUser($actor);

    foreach (['update', 'changeRole', 'delete', 'unlock', 'resetPassword', 'toggleActive'] as $ability) {
        expect($gate->allows($ability, $other))->toBeFalse("{$role} {$ability}");
    }

    expect($gate->allows('viewAny', User::class))->toBeFalse()
        ->and($gate->allows('create', User::class))->toBeFalse()
        ->and($gate->allows('makeAdmin', [User::class]))->toBeFalse();
})->with(['encoder', 'viewer']);

// --- One active System Admin ----------------------------------------------

it('refuses a second active System Admin', function () {
    $sysadmin = userWithRole('system_admin');
    $encoder = userWithRole('encoder');
    $admin = userWithRole('admin');

    $newAccount = Gate::forUser($admin)->inspect('makeSystemAdmin', [User::class]);
    $promote = Gate::forUser($admin)->inspect('makeSystemAdmin', [User::class, $encoder]);

    expect($newAccount->denied())->toBeTrue()
        ->and($newAccount->message())->toBe('There is already an active System Admin account. Only one is allowed.')
        ->and($promote->denied())->toBeTrue()
        // The System Admin account itself keeps its role when edited.
        ->and(Gate::forUser($admin)->allows('makeSystemAdmin', [User::class, $sysadmin]))->toBeTrue();
});

it('allows a new System Admin once the old one is deactivated', function () {
    userWithRole('system_admin', ['is_active' => false]);

    expect(Gate::forUser(userWithRole('admin'))->allows('makeSystemAdmin', [User::class]))->toBeTrue();
});

it('refuses to activate a second System Admin account', function () {
    userWithRole('system_admin');
    $old = userWithRole('system_admin', ['is_active' => false]);

    assertUserActionDenied(
        $this->actingAs(userWithRole('admin'))->post("/users/{$old->id}/toggle-active"),
        'There is already an active System Admin account. Only one is allowed.',
    );
});

it('refuses to restore a System Admin account from Trash while another is active', function () {
    $admin = userWithRole('admin');
    $old = userWithRole('system_admin');
    $old->moveToTrash($admin, 'Replaced');
    userWithRole('system_admin');

    expect(Gate::forUser($admin)->inspect('restore-from-trash', User::onlyTrashed()->find($old->id))->message())
        ->toBe('There is already an active System Admin account. Only one is allowed.');
});

it('gives encoders and viewers no right to make a System Admin', function (string $role) {
    expect(Gate::forUser(userWithRole($role))->allows('makeSystemAdmin', [User::class]))->toBeFalse();
})->with(['encoder', 'viewer']);
