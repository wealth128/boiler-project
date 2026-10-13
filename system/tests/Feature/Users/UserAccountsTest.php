<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * User Accounts screen (planning/rbac.md, prototype "User accounts"):
 * list, add, edit, unlock, reset password, activate / deactivate and move
 * to Trash. The Policy rules themselves are in Rbac/UserPolicyTest.
 */

function newAccount(array $overrides = []): array
{
    return [
        'name' => 'Cruz, Joanna',
        'username' => 'er.cruz',
        'role' => 'encoder',
        'office' => 'ER',
        'password' => 'Temp-Pass-2026',
        'password_confirmation' => 'Temp-Pass-2026',
        ...$overrides,
    ];
}

// --- List ----------------------------------------------------------------

it('lists accounts not in Trash, with what the signed-in user may do', function () {
    $admin = userWithRole('admin', ['name' => 'Garcia, Elena']);
    $sysadmin = userWithRole('system_admin', ['name' => 'System Administrator']);
    $locked = userWithRole('encoder', ['name' => 'Ramos, Carlo', 'failed_attempts' => 5, 'locked_at' => now()]);
    $gone = userWithRole('encoder', ['name' => 'Gone, Old']);
    $gone->moveToTrash($admin, 'Left');

    $this->actingAs($sysadmin)
        ->get('/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/index')
            ->has('users', 3)
            ->where('users.0.name', 'Garcia, Elena')
            // The System Admin can only unlock the Admin, and it isn't locked.
            ->where('users.0.can', [
                'update' => false, 'change_role' => false, 'delete' => false,
                'unlock' => false, 'reset_password' => false, 'toggle_active' => false,
            ])
            ->where('users.1.name', 'Ramos, Carlo')
            ->where('users.1.is_locked', true)
            ->where('users.1.can.unlock', true)
            ->where('users.2.is_self', true)
            ->where('users.2.can.delete', false)
            ->where('users.2.can.reset_password', false)
            ->missing('users.0.password')
            ->missing('users.0.failed_attempts')
            ->has('roles', 4)
            ->has('encoderOffices', 3)
            ->where('maxAttempts', 5)
            ->has('passwordRules'));

    expect($locked->id)->toBeInt();
});

// --- Add -----------------------------------------------------------------

it('adds an account with a temporary password', function (string $role) {
    $this->actingAs(userWithRole($role))
        ->post('/users', newAccount(['username' => '  ER.Cruz ']))
        ->assertRedirect('/users')
        ->assertSessionHasNoErrors();

    $user = User::where('username', 'er.cruz')->sole();
    expect($user->name)->toBe('Cruz, Joanna')
        ->and($user->role->value)->toBe('encoder')
        ->and($user->office->value)->toBe('ER')
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check('Temp-Pass-2026', $user->password))->toBeTrue();

    $entry = AuditLog::where('action', 'user.created')->where('subject_id', $user->id)->sole();
    expect($entry->changes['new']['password'])->toBe('[hidden]')
        ->and(json_encode($entry->changes))->not->toContain('Temp-Pass-2026');
})->with(['admin', 'system_admin']);

it('makes the new user choose their own password at first login', function () {
    $this->actingAs(userWithRole('admin'))->post('/users', newAccount());
    auth()->logout();

    $this->post(route('login.store'), ['username' => 'er.cruz', 'password' => 'Temp-Pass-2026'])
        ->assertRedirect('/choose-password');
});

it('gives Viewer, Admin and System Admin accounts their fixed office', function (string $role, string $office) {
    // No active Admin / System Admin yet besides the one adding.
    $actor = $role === 'admin' ? userWithRole('system_admin') : userWithRole('admin');

    $this->actingAs($actor)
        ->post('/users', newAccount(['username' => 'new.user', 'role' => $role, 'office' => 'ER']))
        ->assertSessionHasNoErrors();

    expect(User::where('username', 'new.user')->sole()->office->value)->toBe($office);
})->with([
    ['viewer', 'Command'],
    ['admin', 'Admin'],
    ['system_admin', 'IT'],
]);

it('checks the fields of a new account', function (array $overrides, string $field, ?string $message = null) {
    $response = $this->actingAs(userWithRole('admin'))
        ->from('/users')
        ->post('/users', newAccount($overrides))
        ->assertRedirect('/users');

    $message === null
        ? $response->assertSessionHasErrors($field)
        : $response->assertSessionHasErrors([$field => $message]);

    expect(User::where('username', 'er.cruz')->exists())->toBeFalse();
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad username' => [['username' => 'er cruz!'], 'username', 'Use only lowercase letters, numbers, dots, dashes or underscores.'],
    'unknown role' => [['role' => 'boss'], 'role'],
    'OPD encoding is off' => [['office' => 'OPD'], 'office', 'Choose ER. OPD and Admission encoding is off in v1.0.'],
    'weak password' => [['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'], 'password'],
    'not confirmed' => [['password_confirmation' => 'Other-Pass-2026'], 'password'],
]);

it('refuses a username that is taken, also by an account in Trash', function () {
    $admin = userWithRole('admin');
    userWithRole('encoder', ['username' => 'er.cruz'])->moveToTrash($admin, 'Left');

    $this->actingAs($admin)
        ->from('/users')
        ->post('/users', newAccount())
        ->assertSessionHasErrors(['username' => 'This username is already used (accounts in Trash count too).']);
});

it('refuses a second active Admin or System Admin', function (string $role, string $message) {
    userWithRole('system_admin');

    $this->actingAs(userWithRole('admin'))
        ->from('/users')
        ->post('/users', newAccount(['role' => $role]))
        ->assertSessionHasErrors(['role' => $message]);
})->with([
    ['admin', 'There is already an active Admin account. Only one is allowed.'],
    ['system_admin', 'There is already an active System Admin account. Only one is allowed.'],
]);

// --- Edit ----------------------------------------------------------------

it('edits name, username, role and office', function () {
    $encoder = userWithRole('encoder', ['username' => 'er.cruz']);

    $this->actingAs(userWithRole('admin'))
        ->put("/users/{$encoder->id}", ['name' => 'Cruz, Joanna B.', 'username' => 'er.jcruz', 'role' => 'viewer', 'office' => 'ER'])
        ->assertRedirect('/users')
        ->assertSessionHasNoErrors();

    $encoder->refresh();
    expect($encoder->name)->toBe('Cruz, Joanna B.')
        ->and($encoder->username)->toBe('er.jcruz')
        ->and($encoder->role->value)->toBe('viewer')
        ->and($encoder->office->value)->toBe('Command')
        ->and(AuditLog::where('action', 'user.updated')->where('subject_id', $encoder->id)->exists())->toBeTrue();
});

it('lets users keep their own username', function () {
    $encoder = userWithRole('encoder', ['username' => 'er.cruz']);

    $this->actingAs(userWithRole('admin'))
        ->put("/users/{$encoder->id}", ['name' => 'New Name', 'username' => 'er.cruz', 'role' => 'encoder', 'office' => 'ER'])
        ->assertSessionHasNoErrors();
});

it("refuses a change to one's own role but allows editing one's own name", function () {
    $admin = userWithRole('admin', ['username' => 'admin']);
    $this->actingAs($admin)->from('/users');

    $this->put("/users/{$admin->id}", ['name' => 'Garcia, Elena', 'username' => 'admin', 'role' => 'encoder', 'office' => 'ER'])
        ->assertSessionHasErrors(['role' => "You can't change your own role."]);

    $this->put("/users/{$admin->id}", ['name' => 'Garcia, Elena', 'username' => 'admin', 'role' => 'admin', 'office' => 'Admin'])
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->role->value)->toBe('admin')
        ->and($admin->fresh()->name)->toBe('Garcia, Elena');
});

it('refuses to promote an account to a second Admin', function () {
    userWithRole('admin');
    $encoder = userWithRole('encoder');

    $this->actingAs(userWithRole('system_admin'))
        ->from('/users')
        ->put("/users/{$encoder->id}", ['name' => $encoder->name, 'username' => $encoder->username, 'role' => 'admin', 'office' => 'ER'])
        ->assertSessionHasErrors(['role' => 'There is already an active Admin account. Only one is allowed.']);
});

// --- Unlock ----------------------------------------------------------------

it('unlocks an account and logs user.unlocked', function () {
    $locked = userWithRole('encoder', ['failed_attempts' => 5, 'locked_at' => now()]);

    $this->actingAs(userWithRole('system_admin'))
        ->post("/users/{$locked->id}/unlock")
        ->assertRedirect('/users');

    $locked->refresh();
    expect($locked->locked_at)->toBeNull()
        ->and($locked->failed_attempts)->toBe(0)
        ->and(AuditLog::where('action', 'user.unlocked')->sole()->changes)->toEqual(['failed_attempts' => 5])
        ->and(AuditLog::where('action', 'user.updated')->exists())->toBeFalse();
});

it('does nothing when the account is not locked', function () {
    $encoder = userWithRole('encoder');

    $this->actingAs(userWithRole('admin'))->post("/users/{$encoder->id}/unlock")->assertRedirect('/users');

    expect(AuditLog::where('action', 'user.unlocked')->exists())->toBeFalse();
});

// --- Reset password ------------------------------------------------------

it('resets a password after the Admin re-enters their own, and makes it temporary', function () {
    $encoder = userWithRole('encoder', ['failed_attempts' => 5, 'locked_at' => now()]);

    $this->actingAs(userWithRole('admin'))
        ->post("/users/{$encoder->id}/reset-password", [
            'password' => 'Reset-Pass-2026',
            'password_confirmation' => 'Reset-Pass-2026',
            'own_password' => 'password',
        ])
        ->assertRedirect('/users')
        ->assertSessionHasNoErrors();

    $encoder->refresh();
    expect(Hash::check('Reset-Pass-2026', $encoder->password))->toBeTrue()
        ->and($encoder->must_change_password)->toBeTrue()
        ->and($encoder->locked_at)->toBeNull()
        ->and($encoder->failed_attempts)->toBe(0);

    $entry = AuditLog::where('action', 'user.password_reset')->sole();
    expect($entry->subject_id)->toBe($encoder->id)
        ->and($entry->changes)->toEqual(['unlocked' => true, 'temporary' => true])
        ->and(AuditLog::where('action', 'user.updated')->exists())->toBeFalse();
});

it('refuses a reset when the own password is wrong or missing', function (string $ownPassword, string $message) {
    $encoder = userWithRole('encoder');
    $before = $encoder->password;

    $this->actingAs(userWithRole('system_admin'))
        ->from('/users')
        ->post("/users/{$encoder->id}/reset-password", [
            'password' => 'Reset-Pass-2026',
            'password_confirmation' => 'Reset-Pass-2026',
            'own_password' => $ownPassword,
        ])
        ->assertSessionHasErrors(['own_password' => $message]);

    expect($encoder->fresh()->password)->toBe($before)
        ->and($encoder->fresh()->must_change_password)->toBeFalse();
})->with([
    'wrong' => ['not-my-password', 'Your own password is wrong.'],
    'missing' => ['', 'Enter your own password to confirm.'],
]);

// --- Activate / deactivate -------------------------------------------------

it('deactivates and activates an account, with one clear audit entry each', function () {
    $encoder = userWithRole('encoder');
    $this->actingAs(userWithRole('admin'));

    $this->post("/users/{$encoder->id}/toggle-active")->assertRedirect('/users');
    expect($encoder->fresh()->is_active)->toBeFalse();

    $this->post("/users/{$encoder->id}/toggle-active")->assertRedirect('/users');
    expect($encoder->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::whereIn('action', ['user.deactivated', 'user.activated'])->orderBy('id')->pluck('action')->all())
        ->toBe(['user.deactivated', 'user.activated'])
        ->and(AuditLog::where('action', 'user.updated')->exists())->toBeFalse();
});

// --- Move to Trash ----------------------------------------------------------

it('moves an account to Trash with a reason', function () {
    $admin = userWithRole('admin');
    $encoder = userWithRole('encoder');

    $this->actingAs($admin)
        ->delete("/users/{$encoder->id}", ['reason' => 'Left the hospital'])
        ->assertRedirect('/users');

    $trashed = User::onlyTrashed()->find($encoder->id);
    expect($trashed)->not->toBeNull()
        ->and($trashed->delete_reason)->toBe('Left the hospital')
        ->and($trashed->deleted_by)->toBe($admin->id)
        ->and(AuditLog::where('action', 'user.deleted')->exists())->toBeTrue();
});

it('requires a reason to move an account to Trash', function () {
    $encoder = userWithRole('encoder');

    $this->actingAs(userWithRole('admin'))
        ->from('/users')
        ->delete("/users/{$encoder->id}", ['reason' => '  '])
        ->assertSessionHasErrors('reason');

    expect($encoder->fresh()->trashed())->toBeFalse();
});
