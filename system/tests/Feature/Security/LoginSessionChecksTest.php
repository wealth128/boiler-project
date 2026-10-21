<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Support\SecurityWorld;

/*
 * Step 7a, check 7: login and session rules, through the real login form
 * and the real routes (no pre-made sessions where it matters).
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

function secManila(string $time): Carbon
{
    return Carbon::parse($time, 'Asia/Manila');
}

function secLoginAs(object $test, User $user, string $password = 'password')
{
    return $test->post('/login', ['username' => $user->username, 'password' => $password]);
}

// --- Lock after 5 wrong passwords ----------------------------------------------

it('locks the account on the 5th wrong password, refuses even the right password after that, and opens again when unlocked', function () {
    $user = userWithRole('encoder');

    foreach (range(1, 4) as $i) {
        secLoginAs($this, $user, 'wrong')->assertSessionHasErrors('username');
        expect($user->fresh()->locked_at)->toBeNull();
    }

    secLoginAs($this, $user, 'wrong')->assertSessionHasErrors('username');
    expect($user->fresh()->locked_at)->not->toBeNull()->and($user->fresh()->failed_attempts)->toBe(5);

    secLoginAs($this, $user)->assertSessionHasErrors('username');
    $this->assertGuest();

    $admin = userWithRole('admin');
    $this->actingAs($admin)->post("/users/{$user->id}/unlock")->assertRedirect();
    auth()->logout();

    secLoginAs($this, $user)->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->failed_attempts)->toBe(0);
});

it('counts a right password as a fresh start', function () {
    $user = userWithRole('viewer');

    foreach (range(1, 4) as $i) {
        secLoginAs($this, $user, 'wrong');
    }

    secLoginAs($this, $user)->assertRedirect();
    auth()->logout();

    foreach (range(1, 4) as $i) {
        secLoginAs($this, $user, 'wrong');
    }

    expect($user->fresh()->locked_at)->toBeNull();
});

it('does not let a wrong password on an unknown name create or lock anything, and answers the same as for a known name', function () {
    $known = secLoginAs($this, userWithRole('encoder'), 'wrong')->assertSessionHasErrors('username');
    $before = User::count();

    $unknown = $this->post('/login', ['username' => 'nobody.here', 'password' => 'wrong'])->assertSessionHasErrors('username');

    expect(User::count())->toBe($before)
        ->and(str_starts_with(session('errors')->first('username'), 'Wrong username or password.'))->toBeTrue();
});

it('limits login tries to 10 a minute for one name and address, then answers 429 with a wait time', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/login', ['username' => 'nobody.here', 'password' => 'wrong'])->assertStatus(422);
    }

    $limited = $this->postJson('/login', ['username' => 'nobody.here', 'password' => 'wrong']);

    expect($limited->getStatusCode())->toBe(429)
        ->and($limited->headers->get('Retry-After'))->not->toBeNull();

    // Another name is not held back, and the right password of the limited name is also refused until the minute is over.
    $this->postJson('/login', ['username' => 'someone.else', 'password' => 'wrong'])->assertStatus(422);

    $this->travel(61)->seconds();
    $this->postJson('/login', ['username' => 'nobody.here', 'password' => 'wrong'])->assertStatus(422);
});

// --- Temporary password -----------------------------------------------------------

it('sends every route to "Choose your new password" while the password is temporary, except that page and Log out', function () {
    $this->world = $w = SecurityWorld::boot('must_change');
    $this->actingAs($w->actor);

    $open = ['password.choose', 'password.choose.update', 'logout'];
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (in_array($name, $open, true) || in_array($route->uri(), ['up', 'login'], true) || str_starts_with($route->uri(), 'storage/') || $route->uri() === '{fallbackPlaceholder}') {
            continue;
        }

        // Real records, so the page is found and only the temporary password stops the request.
        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => match ($m[1]) {
            'period' => '2026-09', 'list' => 'diagnoses', 'type' => 'visits', 'span' => '2026-09', 'who' => 'prepared',
            'user' => (string) $w->otherUser->id, 'visit' => (string) $w->visit->id, 'patient' => (string) $w->patient->id, 'batch' => (string) $w->batch->id,
            default => (string) $w->bracket->id,
        }, $route->uri());

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $html = $this->call($method, '/'.ltrim($uri, '/'));
            expect($html->getStatusCode())->toBeIn([302, 303], "{$method} {$uri}")
                ->and($html->headers->get('Location'))->toEndWith('/choose-password', "{$method} {$uri}");

            $json = $this->json($method, '/'.ltrim($uri, '/'));
            expect($json->getStatusCode())->toBe(403, "{$method} {$uri} (JSON)")
                ->and($json->json('message'))->toBe('Choose your new password first.');

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(55);

    // The two pages and Log out still work.
    $this->get('/choose-password')->assertOk();
    $this->post('/logout')->assertRedirect();
});

it('opens every page after the new password is chosen, and asks for it again after an Admin reset', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $encoder = $w->otherUser;
    $encoder->setTemporaryPassword('TempPass2026');

    $this->post('/login', ['username' => $encoder->username, 'password' => 'TempPass2026'])->assertRedirect(route('password.choose'));
    $this->get('/encode')->assertRedirect(route('password.choose'));

    $this->put('/choose-password', ['password' => 'MyOwnPass2026', 'password_confirmation' => 'MyOwnPass2026'])->assertRedirect();
    $this->get('/encode')->assertOk();

    $this->actingAs($w->actor)->post("/users/{$encoder->id}/reset-password", ['password' => 'Another2026Pw', 'password_confirmation' => 'Another2026Pw', 'own_password' => 'password'])->assertRedirect();
    expect($encoder->fresh()->must_change_password)->toBeTrue();
});

// --- 8 PM sign-out ---------------------------------------------------------------------

it('signs out at 8:00 PM everyone who logged in before it, whatever the role, and not before', function (string $role) {
    $user = userWithRole($role);
    $landing = $user->role->landingRoute();

    $this->travelTo(secManila('2026-10-09 08:00'));
    secLoginAs($this, $user)->assertRedirect();

    // No idle timeout: 11 hours with no click is still fine at 7:59 PM.
    $this->travelTo(secManila('2026-10-09 19:59'));
    $this->get(route($landing))->assertOk();

    $this->travelTo(secManila('2026-10-09 20:00:30'));
    $this->get(route($landing))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->travelTo(secManila('2026-10-09 20:30'));
    secLoginAs($this, $user)->assertRedirect();
    $this->travelTo(secManila('2026-10-10 12:00'));
    $this->get(route($landing))->assertOk();

    $this->travelTo(secManila('2026-10-10 20:01'));
    $this->get(route($landing))->assertRedirect(route('login'));

    expect(AuditLog::where('action', 'user.session_ended')->where('user_id', $user->id)->count())->toBe(2);
})->with(['encoder', 'admin', 'system_admin', 'viewer']);

// --- Access removed ---------------------------------------------------------------------

it('signs a user out on their next click when they are deactivated, locked, moved to Trash or deleted, and writes why', function (string $change, string $reason) {
    $user = userWithRole('encoder');
    secLoginAs($this, $user)->assertRedirect();
    $this->get('/encode')->assertOk();

    match ($change) {
        'deactivated' => $user->forceFill(['is_active' => false])->saveQuietly(),
        'locked' => $user->forceFill(['locked_at' => now(), 'failed_attempts' => 5])->saveQuietly(),
        'trashed' => $user->forceFill(['deleted_at' => now(), 'deleted_by' => userWithRole('admin')->id, 'delete_reason' => 'x'])->saveQuietly(),
        'deleted' => (function () use ($user) {
            DB::table('audit_logs')->where('user_id', $user->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        })(),
    };

    // The next click is a new request: it loads the account again (a test keeps the old object in memory).
    auth()->forgetGuards();
    $this->get('/encode')->assertRedirect(route('login'));
    $this->assertGuest();

    if ($change !== 'deleted') {
        expect(AuditLog::where('action', 'user.session_ended')->where('user_id', $user->id)->latest('id')->first()->changes['reason'])->toBe($reason);
    }
})->with([
    ['deactivated', 'deactivated'],
    ['locked', 'locked'],
    ['trashed', 'trashed'],
    ['deleted', 'deleted'],
]);

it('answers a form post and a JSON call of a removed user with a sign-out, and changes nothing', function () {
    $this->world = $w = SecurityWorld::boot('encoder');
    $this->actingAs($w->actor);
    $w->actor->forceFill(['is_active' => false])->saveQuietly();

    $before = SecurityWorld::state();

    $this->post('/visits', $w->visitForm())->assertRedirect(route('login'));
    $this->actingAs($w->actor);
    $this->getJson('/patients/search?q=cruz')->assertUnauthorized();
    $this->actingAs($w->actor);
    $this->putJson("/visits/{$w->visit->id}", $w->visitForm(['remarks' => 'x']))->assertUnauthorized();

    expect(SecurityWorld::state())->toBe($before);
});

// --- A reset ends all sessions ---------------------------------------------------------------

it('ends every session of a user when their password is reset, and keeps the others', function () {
    config(['session.driver' => 'database']);

    $admin = userWithRole('admin');
    $target = userWithRole('encoder');
    $other = userWithRole('viewer');

    foreach (['t1' => $target, 't2' => $target, 'o1' => $other] as $id => $owner) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'ip_address' => '10.0.0.5', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
    }

    $this->actingAs($admin)->post("/users/{$target->id}/reset-password", ['password' => 'Another2026Pw', 'password_confirmation' => 'Another2026Pw', 'own_password' => 'password'])->assertRedirect();

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'o1')->exists())->toBeTrue();
});

// --- Remember me ----------------------------------------------------------------------------------

it('never makes a remember-me cookie, whatever the form says, and changes the remember token with every password', function (mixed $remember) {
    $user = userWithRole('encoder');
    $token = $user->remember_token;

    $response = $this->post('/login', ['username' => $user->username, 'password' => 'password', 'remember' => $remember]);

    $names = collect($response->headers->getCookies())->map(fn ($cookie) => $cookie->getName());

    expect($names->contains(fn (string $name) => str_starts_with($name, 'remember_web')))->toBeFalse()
        ->and($user->fresh()->remember_token)->toBe($token);

    $this->put('/settings/password', ['current_password' => 'password', 'password' => 'Brandnew2026', 'password_confirmation' => 'Brandnew2026'])->assertRedirect();

    expect($user->fresh()->remember_token)->not->toBe($token);
})->with(['1', 'on', 'true', 1, true]);

it('does not sign in from an old remember cookie after a password change', function () {
    $user = userWithRole('encoder');
    $user->setRememberToken('old-remember-token');
    $user->saveQuietly();

    $guard = auth()->guard('web');
    $cookieValue = $user->getAuthIdentifier().'|old-remember-token|'.$user->getAuthPassword();

    // The password changes: the token changes with it.
    $this->actingAs($user)->put('/settings/password', ['current_password' => 'password', 'password' => 'Brandnew2026', 'password_confirmation' => 'Brandnew2026'])->assertRedirect();
    auth()->forgetGuards();

    $response = $this->withUnencryptedCookie($guard->getRecallerName(), $cookieValue)->get('/encode');

    expect($response->getStatusCode())->toBeIn([302, 303]);
    $this->assertGuest();
});

// --- CSRF -------------------------------------------------------------------------------------------

it('refuses a state-changing request that has no token and does not come from this site, on every such route', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    // The checks are switched off while testing; switch them on.
    app()['env'] = 'local';

    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $methods = array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));

        if ($methods === [] || $route->uri() === '{fallbackPlaceholder}' || str_starts_with($route->uri(), 'storage/')) {
            continue;
        }

        $uri = '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => match ($m[1]) {
            'period' => '2026-09', 'list' => 'diagnoses', 'type' => 'visits', 'span' => '2026-09', 'who' => 'prepared', 'drive' => 'E:', default => '1',
        }, $route->uri()), '/');

        $before = SecurityWorld::state();

        // A script on the same machine without a token (no Sec-Fetch-Site header).
        $bare = $this->json($methods[0], $uri, ['reason' => 'Duplicate entry']);
        // A page on another site (the browser says so).
        $cross = $this->json($methods[0], $uri, ['reason' => 'Duplicate entry'], ['Sec-Fetch-Site' => 'cross-site']);
        // A wrong token.
        $wrong = $this->json($methods[0], $uri, ['reason' => 'Duplicate entry'], ['X-CSRF-TOKEN' => 'not-the-token']);

        foreach ([$bare, $cross, $wrong] as $response) {
            expect($response->getStatusCode())->toBe(419, "{$methods[0]} {$uri}");
        }

        expect(SecurityWorld::state())->toBe($before, "{$methods[0]} {$uri} changed something without a token");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(30);
});

it('accepts the same request with the right token, or from this site as the browser reports it', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    app()['env'] = 'local';

    expect($this->withSession(['_token' => 'known-token-value'])->json('POST', '/backups/marks/usb-copy', [], ['X-CSRF-TOKEN' => 'known-token-value'])->getStatusCode())->not->toBe(419)
        ->and($this->json('POST', '/backups/marks/test-restore', [], ['Sec-Fetch-Site' => 'same-origin'])->getStatusCode())->not->toBe(419);
});

it('sends a browser form that lost its token back to the page with a message, and changes nothing', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);
    app()['env'] = 'local';

    $before = SecurityWorld::state();
    $response = $this->from('/trash')->post('/backups/marks/usb-copy');

    $response->assertRedirect('/trash');
    expect(SecurityWorld::state())->toBe($before);
});
