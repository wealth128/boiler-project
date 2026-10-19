<?php

use Inertia\Testing\AssertableInertia as Assert;

/*
 * auth.loginAt: a value that is new on every sign-in. The browser stores it
 * with a closed red warning bar, so the bar comes back at the next sign-in.
 * It is shared only to a signed-in user.
 */

function loginAtOf(string $uri): mixed
{
    $value = 'unset';

    test()->get($uri)->assertOk()->assertInertia(function (Assert $page) use (&$value) {
        $value = $page->toArray()['props']['auth']['loginAt'];
    });

    return $value;
}

it('is not shared when nobody is signed in', function () {
    expect(loginAtOf('/login'))->toBeNull();
});

it('is set at sign-in and stays the same on every page of that session', function () {
    userWithRole('admin', ['username' => 'admin.garcia']);

    $this->post(route('login.store'), ['username' => 'admin.garcia', 'password' => 'password'])->assertRedirect();

    $first = loginAtOf('/encode');

    expect($first)->toBeString()->not->toBe('')
        ->and(loginAtOf('/records/visits'))->toBe($first)
        ->and(loginAtOf('/backups'))->toBe($first);
});

it('is new on every sign-in, even two in the same second', function () {
    userWithRole('admin', ['username' => 'admin.garcia']);
    $this->freezeTime();

    $this->post(route('login.store'), ['username' => 'admin.garcia', 'password' => 'password']);
    $first = loginAtOf('/encode');

    $this->post(route('logout'));
    $this->post(route('login.store'), ['username' => 'admin.garcia', 'password' => 'password']);

    $second = loginAtOf('/encode');

    expect($second)->not->toBeNull()->and($second)->not->toBe($first);
});

it('gives a session that began before this existed a value on its first request, then keeps it', function () {
    $this->actingAs(userWithRole('admin'));

    $value = loginAtOf('/encode');

    expect($value)->toBeString()->not->toBe('')->and(loginAtOf('/encode'))->toBe($value);
});

it('is gone again after sign-out', function () {
    $this->actingAs(userWithRole('admin'));
    loginAtOf('/encode');

    $this->post(route('logout'));

    expect(loginAtOf('/login'))->toBeNull();
});
