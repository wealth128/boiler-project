<?php

use App\Models\Setting;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Friendly 403 / 404 / 500 pages (pages/errors/error.tsx). They work for
 * signed-out users too, and never show internal error text.
 */

it('shows the 404 page to a signed-out user', function () {
    Setting::create(['key' => 'hospital_name', 'value' => 'AFP General Hospital']);

    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 404)
            ->where('message', null)
            ->where('auth.user', null)
            ->where('hospital.name', 'AFP General Hospital'));
});

it('shows the 404 page with the menu to a signed-in user', function () {
    $user = userWithRole('encoder');

    $this->actingAs($user)
        ->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('auth.user.id', $user->id));
});

it('answers any method on an unknown URL with 404', function () {
    $this->post('/no-such-page')->assertNotFound();
    $this->delete('/no-such-page')->assertNotFound();
});

it('does not name the table or ID of a missing record', function () {
    $this->actingAs(userWithRole('admin'))
        ->put('/visits/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('message', null));
});

it('shows the 500 page without the error text when debug is off', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('SQLSTATE secret detail'));

    $this->get('/_test/boom')
        ->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 500)
            ->where('message', null))
        ->assertDontSee('SQLSTATE secret detail');
});

it("keeps Laravel's debug page for 500 errors while debug is on", function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('boom'));

    $response = $this->get('/_test/boom')->assertStatus(500);

    // Not an Inertia page: Laravel's own error page.
    expect(fn () => $response->assertInertia())->toThrow(AssertionFailedError::class);
});

it('sends an expired form back with a message instead of a 419 page', function () {
    Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException);

    $this->from('/login')->post('/_test/expired')->assertRedirect('/login');
});
