<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * Starter-kit features that conflict with the plan stay off: no sign-up,
 * no reset by email, no email verification, no two-factor, no passkeys.
 * Admin / System Admin reset passwords (planning/rbac.md).
 */

it('does not register the removed starter-kit routes', function (string $name) {
    expect(Route::has($name))->toBeFalse("Route {$name} should not exist");
})->with([
    'register',
    'password.request',
    'password.email',
    'password.reset',
    'password.update',
    'verification.notice',
    'verification.verify',
    'verification.send',
    'two-factor.login',
    'two-factor.enable',
    'passkey.login',
    'passkeys.store',
    'well-known.passkeys',
]);

it('answers the removed pages with 404', function (string $uri) {
    $this->get($uri)->assertNotFound();
})->with([
    '/register',
    '/forgot-password',
    '/reset-password/some-token',
    '/two-factor-challenge',
    '/.well-known/passkey-endpoints',
]);

it('does not send a signed-in user without email to a verify-email page', function () {
    $this->actingAs(User::factory()->create(['email' => null]))
        ->get('/encode')
        ->assertOk();

    $this->get('/email/verify')->assertNotFound();
});

it('has no columns or tables for the removed features', function () {
    expect(Schema::hasColumn('users', 'email_verified_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasTable('passkeys'))->toBeFalse()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse();
});
