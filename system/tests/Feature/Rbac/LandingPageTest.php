<?php

/*
 * First page per role (replaces the starter kit dashboard):
 * Encoder and Admin: /encode, Viewer (CO): /reports, System Admin: /users.
 */

dataset('landing pages', [
    'encoder' => ['encoder', '/encode'],
    'admin' => ['admin', '/encode'],
    'viewer (CO)' => ['viewer', '/reports'],
    'system admin' => ['system_admin', '/users'],
]);

it('sends each role to its landing page after login', function (string $role, string $landing) {
    userWithRole($role, ['username' => 'tester']);

    $this->post(route('login.store'), ['username' => 'tester', 'password' => 'password'])
        ->assertRedirect($landing);

    $this->get($landing)->assertOk();
})->with('landing pages');

it('sends "/" to the landing page when signed in', function (string $role, string $landing) {
    $this->actingAs(userWithRole($role))
        ->get('/')
        ->assertRedirect($landing);
})->with('landing pages');

it('sends "/" to the login page when signed out', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends a signed-in user who opens /login back to their landing page', function () {
    $this->actingAs(userWithRole('viewer'))
        ->get('/login')
        ->assertRedirect('/');
});

it('does not reopen a page asked for before login', function () {
    // On a shared PC that page may belong to the previous user's role.
    userWithRole('viewer', ['username' => 'co']);

    $this->get('/encode')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['username' => 'co', 'password' => 'password'])
        ->assertRedirect('/reports');
});

it('no longer has the starter kit welcome and dashboard pages', function () {
    $this->actingAs(userWithRole('admin'));

    $this->get('/dashboard')->assertNotFound();
    expect(file_exists(resource_path('js/pages/dashboard.tsx')))->toBeFalse()
        ->and(file_exists(resource_path('js/pages/welcome.tsx')))->toBeFalse();
});
