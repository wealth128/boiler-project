<?php

use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\Route;

/*
 * The route map from planning/setup-and-structure.md, section 3.
 * Which role may use which route: tests/Feature/Rbac/RoleAccessTest.php.
 * The stub checks below sign in as Admin, who may use every route.
 */

dataset('route map', [
    ['GET', 'encode', 'encode.index'],
    ['GET', 'patients/search', 'patients.search'],
    ['POST', 'visits', 'visits.store'],
    ['PUT', 'visits/{visit}', 'visits.update'],
    ['GET', 'records/visits', 'records.visits'],
    ['GET', 'records/patients', 'records.patients'],
    ['DELETE', 'visits/{visit}', 'visits.destroy'],
    ['DELETE', 'patients/{patient}', 'patients.destroy'],
    ['GET', 'reports', 'reports.index'],
    ['GET', 'reports/export/pdf', 'reports.export.pdf'],
    ['GET', 'reports/export/excel', 'reports.export.excel'],
    ['POST', 'months/{period}/close', 'months.close'],
    ['POST', 'months/{period}/reopen', 'months.reopen'],
    ['DELETE', 'months/{period}', 'months.destroy'],
    ['GET', 'settings/lists', 'lists.index'],
    ['POST', 'settings/{list}', 'lists.store'],
    ['PUT', 'settings/{list}/{id}', 'lists.update'],
    ['DELETE', 'settings/{list}/{id}', 'lists.destroy'],
    ['GET', 'users', 'users.index'],
    ['POST', 'users', 'users.store'],
    ['PUT', 'users/{user}', 'users.update'],
    ['DELETE', 'users/{user}', 'users.destroy'],
    ['POST', 'users/{user}/unlock', 'users.unlock'],
    ['POST', 'users/{user}/reset-password', 'users.reset-password'],
    ['POST', 'users/{user}/toggle-active', 'users.toggle-active'],
    ['GET', 'audit-log', 'audit.index'],
    ['GET', 'trash', 'trash.index'],
    ['POST', 'trash/{type}/{id}/restore', 'trash.restore'],
    ['DELETE', 'trash/{type}/{id}', 'trash.destroy'],
    ['DELETE', 'trash', 'trash.empty'],
    ['GET', 'backups', 'backups.index'],
    ['POST', 'backups/run', 'backups.run'],
]);

it('registers every planned route behind auth and a role rule', function (string $method, string $uri, string $name) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull("Route {$name} is missing")
        ->and($route->uri())->toBe($uri)
        ->and($route->methods())->toContain($method)
        ->and($route->gatherMiddleware())->toContain('auth')
        ->and(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:')))
        ->toBeTrue("Route {$name} has no role: rule");
})->with('route map');

it('puts a role rule on every signed-in route except the own-account pages', function () {
    // Open to every signed-in role: own profile, password, appearance,
    // password confirmation and logout.
    $everyone = [
        'settings', 'settings/profile', 'settings/security', 'settings/password',
        'settings/appearance', 'user/confirm-password', 'user/confirmed-password-status', 'logout',
    ];

    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
        ->reject(fn ($route) => in_array($route->uri(), $everyone, true))
        ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:')))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('sends guests to the login page', function () {
    $this->get('/encode')->assertRedirect(route('login'));
    $this->getJson('/patients/search?q=cruz')->assertUnauthorized();
    $this->post('/visits')->assertRedirect(route('login'));
});

it('shows the placeholder page on every planned screen', function (string $uri) {
    $this->actingAs(User::factory()->admin()->create())
        ->get($uri)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('placeholder')->has('title'));
})->with([
    '/encode', '/records/visits', '/records/patients', '/reports', '/reports/export/pdf',
    '/reports/export/excel', '/settings/lists', '/users', '/audit-log', '/trash', '/backups',
]);

it('answers the patient search with JSON', function () {
    $this->actingAs(User::factory()->encoder()->create())
        ->getJson('/patients/search?q=cruz')
        ->assertOk()
        ->assertJson(['query' => 'cruz', 'data' => []]);
});

it('answers stub form actions with a "not built yet" toast', function () {
    $user = User::factory()->admin()->create();
    $encoder = User::factory()->encoder()->create();
    $visit = Visit::factory()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($user)->from('/encode');

    foreach ([
        ['post', '/visits'],
        ['put', "/visits/{$visit->id}"],
        ['delete', "/visits/{$visit->id}"],
        ['delete', "/patients/{$patient->id}"],
        ['post', '/months/2026-09/close'],
        ['post', '/settings/diagnoses'],
        ['put', '/settings/age-brackets/1'],
        ['post', "/users/{$encoder->id}/unlock"],
        ['post', '/trash/visits/1/restore'],
        ['delete', '/trash'],
        ['post', '/backups/run'],
    ] as [$method, $uri]) {
        $this->{$method}($uri)->assertRedirect('/encode');
    }
});

it('rejects bad periods, list names and Trash types', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/months/2026-13/close')->assertNotFound();
    $this->post('/months/sept/close')->assertNotFound();
    $this->post('/settings/profile-x')->assertNotFound();
    $this->post('/trash/settings/1/restore')->assertNotFound();
    $this->delete('/trash/visits/abc')->assertNotFound();
});
