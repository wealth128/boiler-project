<?php

use App\Enums\BackupStatus;
use App\Enums\ListItemDeleteReason;
use App\Enums\MonthReportDeleteReason;
use App\Enums\PatientDeleteReason;
use App\Enums\UserDeleteReason;
use App\Enums\VisitDeleteReason;
use App\Models\AuditLog;
use App\Models\BackupMark;
use App\Models\BackupRun;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\TestRestoreRun;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SecurityWorld;

/*
 * Step 7a, check 1: every route against every kind of sender.
 *
 * The matrix is built from the real route list. A route with no entry below
 * FAILS the "every route has an entry" test, so a new route can't slip in
 * unchecked. Each entry holds a VALID request (real records from
 * SecurityWorld), so "allowed" means the action really happened: each entry
 * checks the database or the answer, not only "not 403".
 *
 * Senders: signed out, the four roles, an Admin who must still choose a new
 * password (temporary password) and a deactivated Admin (the strongest role,
 * so the check proves the account state beats the role).
 *
 * Expected results come from planning/rbac.md:
 *   ok        the action worked
 *   403       the 403 page
 *   login     sent to the login page
 *   choose    sent to "Choose your new password"
 *   redirect  sent somewhere else (e.g. a signed-in user opening /login)
 *   404       the friendly "not found" page
 */

const SEC_MATRIX_ALL = ['encoder', 'admin', 'system_admin', 'viewer'];

/** @return array<string, array<string, mixed>> */
function secAccessMatrix(): array
{
    $page = fn ($w, TestResponse $r) => $r->assertInertia(fn (Assert $p) => $p->where('auth.user.id', $w->actor->id));

    $m = [];

    // --- Open to the world or to every signed-in user ---------------------------

    $m['home'] = ['GET', '/', SEC_MATRIX_ALL, 'status' => 302];
    $m['up'] = ['GET', '/up', [], 'as' => array_fill_keys(SecurityWorld::ACTORS, 'ok')];
    $m['hospital.logo.public'] = ['GET', function ($w) {
        $w->withLogo();

        return '/hospital-logo';
    }, [], 'as' => ['guest' => 'ok', 'encoder' => 'ok', 'admin' => 'ok', 'system_admin' => 'ok', 'viewer' => 'ok'], 'verify' => fn ($w, TestResponse $r) => expect($r->headers->get('Content-Type'))->toBe('image/png')];
    $m['login'] = ['GET', '/login', [], 'as' => ['guest' => 'ok', 'encoder' => 'redirect', 'admin' => 'redirect', 'system_admin' => 'redirect', 'viewer' => 'redirect']];
    $m['login.store'] = ['POST', '/login', [],
        'data' => fn ($w) => ['username' => $w->loginUser->username, 'password' => 'password'],
        'as' => ['guest' => 'ok', 'encoder' => 'redirect', 'admin' => 'redirect', 'system_admin' => 'redirect', 'viewer' => 'redirect'],
        'verify' => fn ($w, $r, $t) => $t->assertAuthenticatedAs($w->loginUser),
    ];
    $m['logout'] = ['POST', '/logout', SEC_MATRIX_ALL, 'as' => ['must_change' => 'ok'], 'status' => 302];
    $m['password.confirm'] = ['GET', '/user/confirm-password', SEC_MATRIX_ALL];
    $m['password.confirm.store'] = ['POST', '/user/confirm-password', SEC_MATRIX_ALL, 'status' => 302, 'data' => fn () => ['password' => 'password']];
    $m['password.confirmation'] = ['GET', '/user/confirmed-password-status', SEC_MATRIX_ALL];
    $m['settings'] = ['GET', '/settings', SEC_MATRIX_ALL, 'status' => 302];
    $m['profile.edit'] = ['GET', '/settings/profile', SEC_MATRIX_ALL, 'verify' => $page];
    $m['profile.update'] = ['PATCH', '/settings/profile', SEC_MATRIX_ALL, 'data' => fn () => ['name' => 'Renamed Person'],
        'verify' => fn ($w) => expect($w->actor->fresh()->name)->toBe('Renamed Person')];
    $m['security.edit'] = ['GET', '/settings/security', SEC_MATRIX_ALL, 'verify' => $page];
    $m['user-password.update'] = ['PUT', '/settings/password', SEC_MATRIX_ALL,
        'data' => fn () => ['current_password' => 'password', 'password' => SecurityWorld::newPassword(), 'password_confirmation' => SecurityWorld::newPassword()],
        'verify' => fn ($w) => expect(Hash::check(SecurityWorld::newPassword(), $w->actor->fresh()->password))->toBeTrue()
            ->and(AuditLog::where('action', 'user.password_changed')->where('user_id', $w->actor->id)->exists())->toBeTrue()];
    $m['appearance.edit'] = ['GET', '/settings/appearance', SEC_MATRIX_ALL, 'verify' => $page];
    // Choose-password: open to everyone signed in, and the only page a temporary password may open.
    $m['password.choose'] = ['GET', '/choose-password', SEC_MATRIX_ALL, 'as' => ['must_change' => 'ok'],
        'status' => fn ($actor) => $actor === 'must_change' ? 200 : 302];
    $m['password.choose.update'] = ['PUT', '/choose-password', SEC_MATRIX_ALL, 'as' => ['must_change' => 'ok'], 'status' => 302,
        'data' => fn () => ['password' => SecurityWorld::newPassword('Chosen'), 'password_confirmation' => SecurityWorld::newPassword('Chosen')],
        'verify' => fn ($w, $r, $t, $actor) => $actor === 'must_change'
            ? expect($w->actor->fresh()->must_change_password)->toBeFalse()
                ->and(Hash::check(SecurityWorld::newPassword('Chosen'), $w->actor->fresh()->password))->toBeTrue()
            : expect(Hash::check('password', $w->actor->fresh()->password))->toBeTrue()];

    // Laravel's own storage routes exist only outside production (see the production test).
    $m['storage.local'] = ['GET', '/storage/anything.txt', [], 'as' => array_fill_keys(SecurityWorld::ACTORS, '403')];
    $m['storage.local.upload'] = ['PUT', '/storage/anything.txt?upload=true', [], 'as' => array_fill_keys(SecurityWorld::ACTORS, '403')];
    $m['{fallbackPlaceholder}'] = ['GET', '/no-such-page', [], 'as' => ['guest' => '404', 'encoder' => '404', 'admin' => '404', 'system_admin' => '404', 'viewer' => '404']];

    // --- Encode: Encoder, Admin ------------------------------------------------

    $enc = ['encoder', 'admin'];
    $m['encode.index'] = ['GET', '/encode', $enc, 'verify' => $page];
    $m['patients.search'] = ['GET', '/patients/search?q=cruz', $enc, 'verify' => fn ($w, TestResponse $r) => $r->assertJsonStructure(['query', 'data'])];
    $m['visits.store'] = ['POST', '/visits', $enc, 'data' => fn ($w) => $w->visitForm(),
        'verify' => fn ($w) => expect(Visit::where('encoded_by', $w->actor->id)->count())->toBe($w->actor->role->value === 'encoder' ? 2 : 1)
            ->and(Patient::where('last_name', 'Dela Cruz')->exists())->toBeTrue()];
    $m['visits.update'] = ['PUT', fn ($w) => "/visits/{$w->visit->id}", $enc,
        'data' => fn ($w) => $w->visitForm(['patient_id' => $w->visit->patient_id, 'remarks' => 'Changed by matrix']),
        'verify' => fn ($w) => expect($w->visit->fresh()->remarks)->toBe('Changed by matrix')];

    // --- Records and import: Admin ---------------------------------------------

    $adm = ['admin'];
    $m['records.visits'] = ['GET', '/records/visits', $adm, 'verify' => $page];
    $m['records.patients'] = ['GET', '/records/patients', $adm, 'verify' => $page];
    $m['visits.destroy'] = ['DELETE', fn ($w) => "/visits/{$w->visit->id}", $adm, 'data' => fn () => ['reason' => SecurityWorld::reason(VisitDeleteReason::class)],
        'verify' => fn ($w) => expect(Visit::onlyTrashed()->whereKey($w->visit->id)->exists())->toBeTrue()
            ->and(AuditLog::where('action', 'like', '%deleted%')->orWhere('action', 'like', '%trash%')->exists())->toBeTrue()];
    $m['patients.destroy'] = ['DELETE', fn ($w) => "/patients/{$w->patient->id}", $adm, 'data' => fn () => ['reason' => SecurityWorld::reason(PatientDeleteReason::class)],
        'verify' => fn ($w) => expect(Patient::onlyTrashed()->whereKey($w->patient->id)->exists())->toBeTrue()];
    $m['records.import'] = ['GET', '/records/import', $adm, 'verify' => $page];
    $m['records.import.template'] = ['GET', '/records/import/template', $adm,
        'verify' => fn ($w, TestResponse $r) => expect($r->headers->get('Content-Type'))->toContain('spreadsheetml')];
    $m['records.import.upload'] = ['POST', '/records/import', $adm, 'data' => fn () => ['file' => Tests\Support\ImportFile::xlsx([Tests\Support\ImportFile::row()])],
        'verify' => fn ($w, TestResponse $r) => expect((string) $r->headers->get('Location'))->toMatch('/records\/import\?file=[A-Za-z0-9]{40}/')];
    $m['records.import.confirm'] = ['POST', '/records/import/confirm', $adm, 'needsTest' => true,
        'data' => fn ($w, $t) => ['token' => $w->stagedToken($t), 'rows' => [2]],
        'verify' => fn ($w) => expect(Visit::where('written_by', 'Nurse A. Ramos')->count())->toBe(1)
            ->and(Visit::where('written_by', 'Nurse A. Ramos')->first()->encoded_by)->toBe($w->actor->id)];
    $m['records.import.cancel'] = ['POST', '/records/import/cancel', $adm, 'data' => fn () => ['token' => str_repeat('b', 40)]];
    $m['records.import.undo'] = ['POST', fn ($w) => "/records/import/{$w->batch->id}/undo", $adm,
        'verify' => fn ($w) => expect($w->batch->fresh()->undone_at)->not->toBeNull()];

    // --- Reports: Admin, Viewer (CO) -------------------------------------------

    $rep = ['admin', 'viewer'];
    $m['reports.index'] = ['GET', '/reports', $rep, 'verify' => $page];
    $m['reports.print'] = ['GET', '/reports/print', $rep, 'verify' => $page];
    $m['reports.export.pdf'] = ['GET', '/reports/export/pdf', $rep, 'verify' => fn ($w, TestResponse $r) => expect($r->headers->get('Content-Type'))->toContain('pdf')];
    $m['reports.export.excel'] = ['GET', '/reports/export/excel', $rep, 'verify' => fn ($w, TestResponse $r) => expect($r->headers->get('Content-Type'))->toContain('spreadsheetml')];
    $m['reports.signature'] = ['GET', fn ($w) => '/reports/signatures/'.$w->closedAugust().'/noted', $rep,
        'verify' => fn ($w, TestResponse $r) => expect($r->headers->get('Content-Type'))->toBe('image/png')];

    // --- Months: Admin ---------------------------------------------------------

    $m['months.close'] = ['POST', '/months/2026-09/close', $adm,
        'verify' => fn () => expect(MonthClosure::where('period', '2026-09')->value('is_closed'))->toBeTruthy()
            ->and(ReportSnapshot::where('period', '2026-09')->exists())->toBeTrue()];
    $m['months.reopen'] = ['POST', fn ($w) => '/months/'.$w->closedAugust().'/reopen', $adm,
        'verify' => fn () => expect(MonthClosure::where('period', '2026-08')->value('is_closed'))->toBeFalsy()];
    $m['months.destroy'] = ['DELETE', function ($w) {
        $w->septemberVisit();

        return '/months/2026-09';
    }, $adm, 'data' => fn () => ['reason' => SecurityWorld::reason(MonthReportDeleteReason::class)],
        'verify' => fn () => expect(MonthDeletion::where('period', '2026-09')->exists())->toBeTrue()
            ->and(Visit::onlyTrashed()->whereNotNull('month_deletion_id')->count())->toBe(1)];

    // --- Lists: Admin ----------------------------------------------------------

    $m['lists.index'] = ['GET', '/settings/lists', $adm, 'verify' => $page];
    $m['lists.store'] = ['POST', '/settings/diagnoses', $adm, 'data' => fn () => ['name' => 'Asthma attack'],
        'verify' => fn () => expect(Diagnosis::where('name', 'Asthma attack')->exists())->toBeTrue()];
    $m['lists.update'] = ['PUT', fn ($w) => "/settings/ranks/{$w->rank->id}", $adm, 'data' => fn () => ['name' => 'MAJ'],
        'verify' => fn ($w) => expect($w->rank->fresh()->name)->toBe('MAJ')];
    $m['lists.destroy'] = ['DELETE', fn ($w) => "/settings/age-brackets/{$w->bracket->id}", $adm, 'data' => fn () => ['reason' => SecurityWorld::reason(ListItemDeleteReason::class)],
        'verify' => fn ($w) => expect(App\Models\AgeBracket::onlyTrashed()->whereKey($w->bracket->id)->exists())->toBeTrue()];
    $m['lists.ranks.move'] = ['PUT', fn ($w) => "/settings/ranks/{$w->rankB->id}/move", $adm, 'data' => fn () => ['direction' => 'up'],
        'verify' => fn ($w) => expect($w->rankB->fresh()->sort_order)->toBeLessThan($w->rank->fresh()->sort_order)];

    // --- Hospital settings: Admin (pictures: Admin and Viewer) -----------------

    $m['hospital.edit'] = ['GET', '/settings/hospital', $adm, 'verify' => $page];
    $m['hospital.update'] = ['PUT', '/settings/hospital', $adm, 'data' => fn () => ['hospital_name' => 'Matrix Medical Center'],
        'verify' => fn () => expect(Setting::where('key', 'hospital_name')->value('value'))->toBe('Matrix Medical Center')];
    $m['hospital.logo.destroy'] = ['DELETE', function ($w) {
        $w->withLogo();

        return '/settings/hospital/logo';
    }, $adm, 'verify' => fn () => expect(Storage::disk('local')->files())->not->toContain('logo.png')
        ->and(AuditLog::where('action', 'settings.logo_removed')->exists())->toBeTrue()];
    $m['hospital.signature.destroy'] = ['DELETE', function ($w) {
        $w->withSignatures();

        return '/settings/hospital/signature/prepared';
    }, $adm, 'verify' => fn () => expect(AuditLog::where('action', 'settings.signature_removed')->exists())->toBeTrue()];
    $m['hospital.logo'] = ['GET', function ($w) {
        $w->withLogo();

        return '/settings/hospital/logo';
    }, $rep, 'verify' => fn ($w, TestResponse $r) => expect($r->headers->get('Content-Type'))->toBe('image/png')];
    $m['hospital.signature'] = ['GET', function ($w) {
        $w->withSignatures();

        return '/settings/hospital/signature/prepared';
    }, $rep, 'verify' => fn ($w, TestResponse $r) => expect($r->headers->get('Content-Type'))->toBe('image/png')];

    // --- Users: Admin, System Admin --------------------------------------------

    $usr = ['admin', 'system_admin'];
    $m['users.index'] = ['GET', '/users', $usr, 'verify' => $page];
    $m['users.store'] = ['POST', '/users', $usr, 'data' => fn () => ['name' => 'New Encoder', 'username' => 'new.encoder', 'role' => 'encoder', 'office' => 'ER', 'password' => SecurityWorld::newPassword('Temp'), 'password_confirmation' => SecurityWorld::newPassword('Temp')],
        'verify' => fn () => expect(User::where('username', 'new.encoder')->value('must_change_password'))->toBeTruthy()];
    $m['users.update'] = ['PUT', fn ($w) => "/users/{$w->otherUser->id}", $usr,
        'data' => fn ($w) => ['name' => 'Renamed Encoder', 'username' => $w->otherUser->username, 'role' => 'encoder', 'office' => 'ER'],
        'verify' => fn ($w) => expect($w->otherUser->fresh()->name)->toBe('Renamed Encoder')];
    $m['users.destroy'] = ['DELETE', fn ($w) => "/users/{$w->otherUser->id}", $usr, 'data' => fn () => ['reason' => SecurityWorld::reason(UserDeleteReason::class)],
        'verify' => fn ($w) => expect(User::onlyTrashed()->whereKey($w->otherUser->id)->exists())->toBeTrue()];
    $m['users.unlock'] = ['POST', fn ($w) => "/users/{$w->lockedUser->id}/unlock", $usr,
        'verify' => fn ($w) => expect($w->lockedUser->fresh()->locked_at)->toBeNull()
            ->and(AuditLog::where('action', 'user.unlocked')->where('user_id', $w->actor->id)->exists())->toBeTrue()];
    $m['users.reset-password'] = ['POST', fn ($w) => "/users/{$w->otherUser->id}/reset-password", $usr,
        'data' => fn () => ['password' => SecurityWorld::newPassword('Reset'), 'password_confirmation' => SecurityWorld::newPassword('Reset'), 'own_password' => 'password'],
        'verify' => fn ($w) => expect($w->otherUser->fresh()->must_change_password)->toBeTrue()
            ->and(Hash::check(SecurityWorld::newPassword('Reset'), $w->otherUser->fresh()->password))->toBeTrue()];
    $m['users.toggle-active'] = ['POST', fn ($w) => "/users/{$w->otherUser->id}/toggle-active", $usr,
        'verify' => fn ($w) => expect($w->otherUser->fresh()->is_active)->toBeFalse()];

    // --- Audit log, Trash, Backups, Health: Admin --------------------------------

    $m['audit.index'] = ['GET', '/audit-log', $adm, 'verify' => $page];
    $m['trash.index'] = ['GET', '/trash', $adm, 'verify' => $page];
    $m['trash.restore'] = ['POST', fn ($w) => "/trash/visits/{$w->trashedVisit->id}/restore", $adm,
        'verify' => fn ($w) => expect(Visit::whereKey($w->trashedVisit->id)->exists())->toBeTrue()];
    $m['trash.destroy'] = ['DELETE', fn ($w) => "/trash/patients/{$w->trashedPatient->id}", $adm, 'data' => fn ($w) => ['confirm' => $w->trashedPatient->patient_no],
        'verify' => fn ($w) => expect(Patient::withTrashed()->whereKey($w->trashedPatient->id)->exists())->toBeFalse()];
    $m['trash.empty'] = ['DELETE', '/trash', $adm, 'data' => fn () => ['confirm' => 'EMPTY TRASH'],
        'verify' => fn ($w) => expect(Visit::withTrashed()->whereKey($w->trashedVisit->id)->exists())->toBeFalse()];
    $m['backups.index'] = ['GET', '/backups', $adm, 'verify' => $page];
    $m['backups.run'] = ['POST', function ($w) {
        $w->backupFakes();

        return '/backups/run';
    }, $adm, 'verify' => fn ($w) => expect(BackupRun::sole()->status)->toBe(BackupStatus::Success)
        ->and(AuditLog::where('action', 'backup.run_manual')->where('user_id', $w->actor->id)->exists())->toBeTrue()];
    $m['backups.usb-copy'] = ['POST', function ($w) {
        $w->backupFakes();
        $w->goodBackup();

        return '/backups/usb-copy';
    }, $adm, 'data' => fn () => ['drive' => 'E:'],
        'verify' => fn ($w) => expect(glob($w->usbFolder.'/census-backups/*.sql'))->not->toBeEmpty()];
    $m['backups.test-restore'] = ['POST', function ($w) {
        $w->backupFakes();
        $w->goodBackup();

        return '/backups/test-restore';
    }, $adm, 'verify' => fn ($w) => expect(TestRestoreRun::sole()->status)->toBe(BackupStatus::Success)];
    $m['backups.mark'] = ['POST', '/backups/marks/usb-copy', $adm,
        'verify' => fn ($w) => expect(BackupMark::where('marked_by', $w->actor->id)->count())->toBe(1)];
    $m['system.health'] = ['GET', '/system/health', $adm, 'verify' => $page];

    return array_map(function (array $e): array {
        [$method, $uri, $roles] = $e;

        return [...$e, 'method' => $method, 'uri' => $uri, 'roles' => $roles];
    }, $m);
}

/** What the sender is expected to get, from planning/rbac.md. */
function secExpectedOutcome(array $entry, string $actor): string
{
    if (isset($entry['as'][$actor])) {
        return $entry['as'][$actor];
    }

    return match (true) {
        $actor === 'guest' => 'login',
        $actor === 'must_change' => 'choose',
        $actor === 'deactivated' => 'login',
        in_array($actor, $entry['roles'], true) => 'ok',
        default => '403',
    };
}

dataset('access matrix', function () {
    foreach (secAccessMatrix() as $key => $entry) {
        foreach (SecurityWorld::ACTORS as $actor) {
            yield "{$key} as {$actor}" => [$key, $actor];
        }
    }
});

afterEach(function () {
    if (isset($this->world)) {
        $this->world->cleanUp();
    }
});

it('gives every sender the answer rbac.md says, and the action really works when allowed', function (string $key, string $actorKey) {
    $entry = secAccessMatrix()[$key];

    $this->world = SecurityWorld::boot($actorKey);
    $w = $this->world;

    $uri = $entry['uri'] instanceof Closure ? ($entry['uri'])($w) : $entry['uri'];
    $data = isset($entry['data']) ? ($entry['data'])($w, $this) : [];

    if ($w->actor !== null) {
        $this->actingAs($w->actor);
    }

    $before = SecurityWorld::state();
    $response = $this->from('/settings/profile')->call($entry['method'], $uri, $data);

    $expected = secExpectedOutcome($entry, $actorKey);
    $where = "{$entry['method']} {$uri} as {$actorKey}";

    switch ($expected) {
        case 'ok':
            $status = $entry['status'] ?? ($entry['method'] === 'GET' ? 200 : 302);
            $status = $status instanceof Closure ? $status($actorKey) : $status;
            expect($response->getStatusCode())->toBe($status, "{$where} should be {$status}");

            if ($status >= 300) {
                expect($response->headers->get('Location'))->not->toEndWith('/login', "{$where} was sent to login")
                    ->and($response->headers->get('Location'))->not->toEndWith('/choose-password', "{$where} was sent to choose-password (unless the sender must choose one)");
            }

            $response->assertSessionHasNoErrors();

            if (isset($entry['verify'])) {
                ($entry['verify'])($w, $response, $this, $actorKey);
            }

            break;

        case '403':
            $response->assertForbidden();

            break;

        case '404':
            $response->assertNotFound();

            break;

        case 'login':
            expect($response->getStatusCode())->toBeIn([302, 303], $where);
            expect($response->headers->get('Location'))->toEndWith('/login', $where);

            break;

        case 'choose':
            expect($response->getStatusCode())->toBeIn([302, 303], $where);
            expect($response->headers->get('Location'))->toEndWith('/choose-password', $where);

            break;

        case 'redirect':
            expect($response->getStatusCode())->toBeIn([302, 303], $where);
            expect($response->headers->get('Location'))->not->toEndWith('/login')->not->toEndWith('/choose-password');

            break;
    }

    // A refused request changes nothing. (Allowed requests are checked by "verify" above.)
    if ($expected !== 'ok') {
        expect(SecurityWorld::state())->toBe($before, "{$where} changed the database although it was refused");
    }
})->with('access matrix');

it('has an entry in the access matrix for every route, and no entry for a route that does not exist', function () {
    $routeKeys = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName() ?: $route->uri())->all();
    $matrixKeys = array_keys(secAccessMatrix());

    expect(array_values(array_diff($routeKeys, $matrixKeys)))
        ->toBe([], 'Routes with no entry in RouteAccessMatrixTest: '.implode(', ', array_diff($routeKeys, $matrixKeys)))
        ->and(array_values(array_diff($matrixKeys, $routeKeys)))
        ->toBe([], 'Matrix entries with no route: '.implode(', ', array_diff($matrixKeys, $routeKeys)))
        ->and(count($routeKeys))->toBe(count(array_unique($routeKeys)));
});

it('uses the same method and address in the matrix as the real route', function () {
    $matrix = secAccessMatrix();

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $entry = $matrix[$route->getName() ?: $route->uri()] ?? null;

        if ($entry === null || $entry['uri'] instanceof Closure) {
            continue;
        }

        $path = ltrim(strtok($entry['uri'], '?'), '/');

        expect($route->methods())->toContain($entry['method']);

        if (! str_contains($route->uri(), '{')) {
            expect($route->uri())->toBe($path === '' ? '/' : $path);
        }
    }
});

it('lists the same roles in the matrix as the role: rule on the route (matrix from rbac.md, rule from routes/web.php)', function () {
    foreach (secAccessMatrix() as $key => $entry) {
        $route = Route::getRoutes()->getByName($key);

        if ($route === null || $entry['roles'] === [] || $entry['roles'] === SEC_MATRIX_ALL) {
            continue;
        }

        $rule = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'role:'));

        expect($rule)->not->toBeNull("{$key} has no role: rule")
            ->and(explode(',', substr($rule, 5)))->toEqualCanonicalizing($entry['roles'], "{$key} roles differ from rbac.md");
    }
});

it('puts the auth rule on every route that is not public', function () {
    $public = ['home', 'hospital.logo.public', 'login', 'login.store', 'up', 'storage.local', 'storage.local.upload', '{fallbackPlaceholder}'];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $key = $route->getName() ?: $route->uri();

        if (in_array($key, $public, true)) {
            continue;
        }

        expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
            ->toBeTrue("{$key} is not behind the auth rule");
    }
});
