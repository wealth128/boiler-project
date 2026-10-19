<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/*
 * "Remember me" is off. The login page has no checkbox, but a hand-made
 * login POST with remember=1 must not get a long-lived cookie, because that
 * cookie would survive a password reset and the 8 PM sign-out. A new
 * password also changes remember_token, so any old cookie stops working.
 */

/** @return list<string> Names of the remember cookies the response sets. */
function rememberCookies($response): array
{
    return collect($response->headers->getCookies())
        ->map(fn ($cookie) => $cookie->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'remember_'))
        ->values()
        ->all();
}

/** A real "remember me" cookie for $user, as the framework would have made it. */
function recallerFor(User $user): array
{
    Cookie::flushQueuedCookies();
    Auth::guard('web')->login($user, true);

    $cookie = collect(Cookie::getQueuedCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_'));

    // No logout() here: it would change remember_token and spoil the cookie.
    Auth::forgetGuards();
    Cookie::flushQueuedCookies();
    test()->flushSession();

    return [$cookie->getName(), $cookie->getValue()];
}

it('sets no remember cookie when a hand-made login sends remember=1', function (mixed $remember) {
    $user = userWithRole('encoder', ['username' => 'er.cruz', 'remember_token' => 'token-before-login']);

    $response = $this->post(route('login.store'), [
        'username' => 'er.cruz',
        'password' => 'password',
        'remember' => $remember,
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated();

    expect(rememberCookies($response))->toBe([])
        ->and($user->fresh()->remember_token)->toBe('token-before-login');
})->with(['1' => '1', 'on' => 'on', 'true' => 'true', 'bool true' => true]);

it('proves the control: a real remember cookie does sign someone in', function () {
    $user = userWithRole('encoder');
    [$name, $value] = recallerFor($user);

    $this->withCookie($name, $value)->get('/encode')->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('ends an old remember cookie when an Admin or IT resets the password', function () {
    $user = userWithRole('encoder');
    [$name, $value] = recallerFor($user);
    $before = $user->fresh()->remember_token;

    $user->fresh()->setTemporaryPassword('Reset-Pass-2026');

    expect($user->fresh()->remember_token)->not->toBe($before)->not->toBeNull()->toHaveLength(60);

    Auth::forgetGuards();
    $this->flushSession();
    $this->withCookie($name, $value)->get('/encode')->assertRedirect('/login');
    $this->assertGuest();
});

it('changes remember_token when a user changes their own password', function () {
    $user = userWithRole('encoder', ['remember_token' => 'old-token-value']);

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'Brand-New-Pass-2026',
        'password_confirmation' => 'Brand-New-Pass-2026',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->remember_token)->not->toBe('old-token-value')->toHaveLength(60);
});

it('changes remember_token when a temporary password is replaced', function () {
    $user = userWithRole('encoder', ['must_change_password' => true, 'remember_token' => 'old-token-value']);

    $this->actingAs($user)->put('/choose-password', [
        'password' => 'Brand-New-Pass-2026',
        'password_confirmation' => 'Brand-New-Pass-2026',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->remember_token)->not->toBe('old-token-value')->toHaveLength(60);
});

it('ends an old remember cookie after a password reset through the Users screen', function () {
    $encoder = userWithRole('encoder', ['remember_token' => 'old-token-value']);

    $this->actingAs(userWithRole('admin'))->post("/users/{$encoder->id}/reset-password", [
        'password' => 'Reset-Pass-2026',
        'password_confirmation' => 'Reset-Pass-2026',
        'own_password' => 'password',
    ])->assertSessionHasNoErrors();

    expect($encoder->fresh()->remember_token)->not->toBe('old-token-value')->toHaveLength(60);
});

it('changes remember_token when IT resets the Admin password on the server', function () {
    $admin = userWithRole('admin', ['username' => 'admin', 'remember_token' => 'old-token-value']);

    $this->artisan('census:reset-admin-password')
        ->expectsConfirmation('Set a new password for this account?', 'yes')
        ->expectsQuestion('New password', 'New-Admin-Pass-2026')
        ->expectsQuestion('Type the new password again', 'New-Admin-Pass-2026')
        ->assertSuccessful();

    expect($admin->fresh()->remember_token)->not->toBe('old-token-value')->toHaveLength(60);
});
