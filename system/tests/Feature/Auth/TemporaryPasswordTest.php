<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Temporary passwords (planning/rbac.md): a password set by Admin, System
 * Admin or IT must be replaced right after logging in with it. Until then
 * only "Choose your new password" and Log out open.
 */

const TEMP_PASSWORD = 'Temp-Pass-2026';

function userWithTemporaryPassword(string $role = 'encoder'): User
{
    $user = userWithRole($role, ['username' => 'er.cruz']);
    $user->setTemporaryPassword(TEMP_PASSWORD);

    return $user;
}

function logInWithTemporaryPassword()
{
    return test()->post(route('login.store'), ['username' => 'er.cruz', 'password' => TEMP_PASSWORD]);
}

it('is off for accounts by default and on after a password is set for the user', function () {
    $user = userWithRole('encoder');

    expect($user->fresh()->must_change_password)->toBeFalse()
        ->and($user->isFillable('must_change_password'))->toBeFalse();

    $user->setTemporaryPassword(TEMP_PASSWORD);

    expect($user->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check(TEMP_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('sends the user to "Choose your new password" right after login', function () {
    userWithTemporaryPassword();

    logInWithTemporaryPassword()->assertRedirect('/choose-password');

    $this->get('/choose-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/choose-password')
            ->has('passwordRules'));
});

it('opens no other page until the password is changed', function (string $url) {
    userWithTemporaryPassword('admin');
    logInWithTemporaryPassword();

    $this->get($url)->assertRedirect('/choose-password');
})->with(['/', '/encode', '/records/visits', '/reports', '/settings/profile', '/settings/security', '/no-such-page']);

it('answers JSON requests with 403 until the password is changed', function () {
    userWithTemporaryPassword();
    logInWithTemporaryPassword();

    $this->getJson('/patients/search?q=cruz')
        ->assertForbidden()
        ->assertJson(['message' => 'Choose your new password first.']);
});

it('still lets the user log out', function () {
    userWithTemporaryPassword();
    logInWithTemporaryPassword();

    $this->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
});

it('refuses the temporary password as the new one', function () {
    $user = userWithTemporaryPassword();
    logInWithTemporaryPassword();

    $this->from('/choose-password')
        ->put('/choose-password', ['password' => TEMP_PASSWORD, 'password_confirmation' => TEMP_PASSWORD])
        ->assertRedirect('/choose-password')
        ->assertSessionHasErrors(['password' => "Your new password can't be the same as the temporary one."]);

    expect($user->fresh()->must_change_password)->toBeTrue()
        ->and(AuditLog::where('action', 'user.password_changed')->exists())->toBeFalse();
});

it('applies the password rule and the confirmation', function (string $password, string $confirmation) {
    $user = userWithTemporaryPassword();
    logInWithTemporaryPassword();

    $this->from('/choose-password')
        ->put('/choose-password', ['password' => $password, 'password_confirmation' => $confirmation])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue();
})->with([
    'too short' => ['abc123', 'abc123'],
    'no numbers' => ['onlyletters', 'onlyletters'],
    'no letters' => ['12345678', '12345678'],
    'not confirmed' => ['MyOwnPass-2026', 'Something-else-2026'],
]);

it('saves the new password, clears the flag, logs user.password_changed and opens the system', function () {
    $user = userWithTemporaryPassword();
    logInWithTemporaryPassword();
    $lastEntry = AuditLog::max('id');

    $this->put('/choose-password', ['password' => 'myownpass1', 'password_confirmation' => 'myownpass1'])
        ->assertRedirect('/encode');

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('myownpass1', $user->password))->toBeTrue();

    $entry = AuditLog::where('action', 'user.password_changed')->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->changes)->toEqual(['was_temporary' => true])
        // One clear entry, not also a generic edit.
        ->and(AuditLog::where('id', '>', $lastEntry)->where('action', 'user.updated')->exists())->toBeFalse();

    $this->get('/encode')->assertOk();
    $this->get('/choose-password')->assertRedirect('/encode');
});

it('sends a user without a temporary password away from the page', function () {
    $this->actingAs(userWithRole('viewer'))
        ->get('/choose-password')
        ->assertRedirect('/reports');

    $this->put('/choose-password', ['password' => 'myownpass1', 'password_confirmation' => 'myownpass1'])
        ->assertRedirect('/reports');

    expect(AuditLog::where('action', 'user.password_changed')->exists())->toBeFalse();
});

it('logs user.password_changed when a user changes their own password in Settings', function () {
    $user = userWithRole('encoder');

    $this->actingAs($user)
        ->from('/settings/security')
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-2026',
            'password_confirmation' => 'new-password-2026',
        ])
        ->assertSessionHasNoErrors();

    $entry = AuditLog::where('action', 'user.password_changed')->sole();
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->changes)->toEqual(['was_temporary' => false])
        ->and(AuditLog::where('action', 'user.updated')->exists())->toBeFalse();
});
