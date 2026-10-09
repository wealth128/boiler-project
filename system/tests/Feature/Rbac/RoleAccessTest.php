<?php

use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Every role against every route (planning/rbac.md, permission matrix and
 * planning/setup-and-structure.md, route map). A role that is allowed gets
 * past the role: middleware (the stub page or "not built yet" redirect);
 * every other role gets the 403 page.
 *
 * The fixtures make the per-record Policies say yes for an allowed role:
 * the visit was encoded today by the signed-in user, and the user account
 * acted on is another encoder.
 */

const ALL_ROLES = ['encoder', 'admin', 'system_admin', 'viewer'];

dataset('roles', ALL_ROLES);

dataset('protected routes', [
    // Encode: Encoder, Admin
    'encode page' => ['GET', '/encode', ['encoder', 'admin']],
    'patient search' => ['GET', '/patients/search?q=cruz', ['encoder', 'admin']],
    'save visit' => ['POST', '/visits', ['encoder', 'admin']],
    'edit visit' => ['PUT', '/visits/{visit}', ['encoder', 'admin']],
    // Records: Admin
    'visit records' => ['GET', '/records/visits', ['admin']],
    'patient records' => ['GET', '/records/patients', ['admin']],
    'delete visit' => ['DELETE', '/visits/{visit}', ['admin']],
    'delete patient' => ['DELETE', '/patients/{patient}', ['admin']],
    // Reports: Admin, Viewer (CO)
    'reports page' => ['GET', '/reports', ['admin', 'viewer']],
    'export pdf' => ['GET', '/reports/export/pdf', ['admin', 'viewer']],
    'export excel' => ['GET', '/reports/export/excel', ['admin', 'viewer']],
    // Months: Admin
    'close month' => ['POST', '/months/2026-09/close', ['admin']],
    'reopen month' => ['POST', '/months/2026-09/reopen', ['admin']],
    'delete month report' => ['DELETE', '/months/2026-09', ['admin']],
    // Dropdown lists: Admin
    'lists page' => ['GET', '/settings/lists', ['admin']],
    'add list item' => ['POST', '/settings/diagnoses', ['admin']],
    'edit list item' => ['PUT', '/settings/ranks/1', ['admin']],
    'delete list item' => ['DELETE', '/settings/age-brackets/1', ['admin']],
    // User accounts: Admin, System Admin
    'users page' => ['GET', '/users', ['admin', 'system_admin']],
    'add user' => ['POST', '/users', ['admin', 'system_admin']],
    'edit user' => ['PUT', '/users/{user}', ['admin', 'system_admin']],
    'delete user' => ['DELETE', '/users/{user}', ['admin', 'system_admin']],
    'unlock user' => ['POST', '/users/{user}/unlock', ['admin', 'system_admin']],
    'reset password' => ['POST', '/users/{user}/reset-password', ['admin', 'system_admin']],
    'activate / deactivate' => ['POST', '/users/{user}/toggle-active', ['admin', 'system_admin']],
    // Audit log, Trash, Backups: Admin
    'audit log' => ['GET', '/audit-log', ['admin']],
    'trash page' => ['GET', '/trash', ['admin']],
    'restore from trash' => ['POST', '/trash/visits/1/restore', ['admin']],
    'delete permanently' => ['DELETE', '/trash/patients/1', ['admin']],
    'empty trash' => ['DELETE', '/trash', ['admin']],
    'backups page' => ['GET', '/backups', ['admin']],
    'run backup' => ['POST', '/backups/run', ['admin']],
    // Own account pages: everyone signed in
    'profile' => ['GET', '/settings/profile', ALL_ROLES],
    'password' => ['GET', '/settings/security', ALL_ROLES],
    'appearance' => ['GET', '/settings/appearance', ALL_ROLES],
]);

it('lets each role reach only the routes rbac.md allows', function (string $method, string $uri, array $allowed, string $role) {
    $actor = userWithRole($role);
    $visit = Visit::factory()->create(['encoded_by' => $actor->id, 'visited_at' => now()]);
    $patient = Patient::factory()->create();
    $otherUser = User::factory()->encoder()->create();

    $uri = strtr($uri, [
        '{visit}' => $visit->id,
        '{patient}' => $patient->id,
        '{user}' => $otherUser->id,
    ]);

    $response = $this->actingAs($actor)->from('/settings/profile')->call($method, $uri);

    if (in_array($role, $allowed, true)) {
        expect($response->status())->toBeLessThan(400, "{$role} should reach {$method} {$uri}");
    } else {
        $response->assertForbidden();
    }
})->with('protected routes')->with('roles');

it('shows the friendly 403 page to a role that is not allowed', function () {
    $this->actingAs(userWithRole('viewer'))
        ->get('/encode')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 403)
            ->where('message', null));
});

it('answers a JSON request from a role that is not allowed with JSON', function () {
    $this->actingAs(userWithRole('viewer'))
        ->getJson('/patients/search?q=cruz')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('sends signed-out users to the login page instead of a 403', function (string $method, string $uri) {
    $this->call($method, strtr($uri, ['{visit}' => 1, '{patient}' => 1, '{user}' => 1]))
        ->assertRedirect(route('login'));
})->with('protected routes');
