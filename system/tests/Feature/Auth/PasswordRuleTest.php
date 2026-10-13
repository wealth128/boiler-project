<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
 * Password rule (planning/rbac.md): at least 8 characters, with letters and
 * numbers. No symbol needed. The same in every environment, and no internet
 * check (the hospital network has no internet).
 */

function passwordPasses(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => Password::defaults()])->passes();
}

it('accepts 8 or more characters with letters and numbers, no symbol needed', function (string $password) {
    expect(passwordPasses($password))->toBeTrue();
})->with(['abcd1234', 'myownpass1', '2026census', 'Long-Pass-With-Symbols-2026']);

it('refuses passwords that break the rule', function (string $password) {
    expect(passwordPasses($password))->toBeFalse();
})->with([
    'too short' => 'abc1234',
    'letters only' => 'abcdefgh',
    'numbers only' => '12345678',
    'symbols only' => '!@#$%^&*',
]);

it('uses the same rule in production', function () {
    $this->app['env'] = 'production';

    expect(passwordPasses('abcd1234'))->toBeTrue()
        ->and(passwordPasses('abcdefgh'))->toBeFalse()
        ->and(passwordPasses('abc1234'))->toBeFalse();
});

it('never goes online to check a password', function () {
    Http::preventStrayRequests();
    $this->app['env'] = 'production';

    expect(passwordPasses('abcd1234'))->toBeTrue();

    Http::assertNothingSent();
});
