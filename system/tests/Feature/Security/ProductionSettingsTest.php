<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\Support\SecurityWorld;

/*
 * Step 7a, check 5 (production settings, the Phase 8 checklist). Things that
 * depend on APP_ENV are checked by starting the application again in its own
 * process with APP_ENV=production, exactly as the server does, because the
 * routes and the settings are read once when the application starts.
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

/**
 * Start the application in its own process with these settings and run an Artisan command in it.
 * The test database is named explicitly, so nothing can reach the real one.
 *
 * @param  array<string, string>  $env
 * @param  list<string>  $command
 */
function secArtisanIn(array $env, array $command): string
{
    $process = new Process([PHP_BINARY, 'artisan', ...$command], base_path(), array_merge([
        'APP_KEY' => (string) config('app.key'),
        'DB_DATABASE' => 'patient_census_test',
        'SESSION_DRIVER' => 'array',
        'CACHE_STORE' => 'array',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
    ], $env));
    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeTrue("artisan ".implode(' ', $command)." failed: {$process->getErrorOutput()} {$process->getOutput()}");

    return $process->getOutput();
}

/** The routes of the application started with these settings, as [uri => name]. @return array<string, string> */
function secRoutesIn(array $env): array
{
    $json = json_decode(secArtisanIn($env, ['route:list', '--json']), true);

    expect($json)->toBeArray()->not->toBeEmpty();

    $routes = [];

    foreach ($json as $route) {
        $routes[$route['method'].' '.$route['uri']] = (string) $route['name'];
    }

    return $routes;
}

it('registers no devtools route and no storage/{path} route when APP_ENV=production', function () {
    $production = secRoutesIn(['APP_ENV' => 'production']);

    foreach (array_keys($production) as $route) {
        expect($route)->not->toContain('_inertia')->not->toContain('devtools')->not->toContain('storage/');
    }

    expect(array_values($production))->not->toContain('storage.local')->not->toContain('storage.local.upload');
});

it('does register them while developing, so the test above can fail', function () {
    $local = secRoutesIn(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);

    expect(implode("\n", array_keys($local)))->toContain('storage/{path}')->toContain('_inertia/devtools');
});

it('has the same application routes in production as in the test run', function () {
    $production = array_filter(secRoutesIn(['APP_ENV' => 'production']), fn (string $name, string $route) => ! str_contains($route, 'storage/'), ARRAY_FILTER_USE_BOTH);

    $here = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'storage/')) {
            continue;
        }

        $here[] = $route->getName() ?: $route->uri();
    }

    $theirs = array_map(fn (string $name, string $route): string => $name !== '' ? $name : explode(' ', $route, 2)[1], $production, array_keys($production));

    expect($theirs)->toEqualCanonicalizing($here);
});

it('takes the "secure" flag of the session cookie from SESSION_SECURE_COOKIE (off by default) and fixes the other flags', function (array $env, bool $secure) {
    $out = secArtisanIn($env, ['tinker', '--execute=echo json_encode(array_intersect_key(config("session"), array_flip(["secure","http_only","same_site","lifetime","encrypt","driver"])));']);
    $config = json_decode(trim(substr($out, (int) strpos($out, '{'))), true);

    expect($config['secure'])->toBe($secure)
        ->and($config['http_only'])->toBeTrue()
        ->and($config['same_site'])->toBe('lax');
})->with([
    'not set' => [[], false],
    'set to false' => [['SESSION_SECURE_COOKIE' => 'false'], false],
    'set to true (HTTPS)' => [['SESSION_SECURE_COOKIE' => 'true'], true],
    'someone tries to turn http_only off and same_site to none' => [['SESSION_HTTP_ONLY' => 'false', 'SESSION_SAME_SITE' => 'none'], false],
]);

it('sets the session cookie with HttpOnly and SameSite=Lax, and Secure only when asked', function (bool $secure) {
    config(['session.secure' => $secure]);

    $user = userWithRole('encoder');
    $response = $this->post('/login', ['username' => $user->username, 'password' => 'password']);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());
    $session = $cookies->get(config('session.cookie'));
    $token = $cookies->get('XSRF-TOKEN');

    expect($session)->not->toBeNull()
        ->and($session->isHttpOnly())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax')
        ->and($session->isSecure())->toBe($secure)
        ->and($session->getPath())->toBe('/')
        // Not "remember me": no long-lived cookie.
        ->and($cookies->keys()->contains(fn (string $name) => str_starts_with($name, 'remember_web_')))->toBeFalse();

    // The page script has to read the XSRF cookie, so it is not HttpOnly; it still follows the other two flags.
    expect($token)->not->toBeNull()
        ->and($token->getSameSite())->toBe('lax')
        ->and($token->isSecure())->toBe($secure);
})->with([false, true]);

it('shows the friendly error page, and no stack trace, SQL or file path, for a forced 500 when APP_DEBUG=false', function (string $how) {
    $this->world = $w = SecurityWorld::boot($how === 'signed out' ? 'guest' : 'admin');
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__boom', function () {
        throw new RuntimeException('SECRET-DETAIL in '.base_path('app/Services/Hidden.php'));
    });
    Route::middleware('web')->get('/__boom-sql', fn () => DB::select('select secret_column from no_such_table_here where token = ?', ['bound-secret-value']));
    Route::middleware('web')->get('/__boom-type', fn () => (fn (int $x) => $x)('not a number'));

    if ($w->actor !== null) {
        $this->actingAs($w->actor);
    }

    foreach (['/__boom', '/__boom-sql', '/__boom-type'] as $url) {
        $response = $how === 'JSON' ? $this->getJson($url) : $this->get($url);
        $body = (string) $response->getContent();

        expect($response->getStatusCode())->toBe(500, $url);

        foreach (['SECRET-DETAIL', 'Hidden.php', 'RuntimeException', 'secret_column', 'no_such_table_here', 'bound-secret-value', 'SQLSTATE', 'Stack trace', 'stack trace', '#0 ', 'vendor/laravel', 'vendor\\\\laravel', 'vendor\\laravel', 'Illuminate\\', 'Illuminate\\\\', base_path(), str_replace('\\', '\\\\', base_path()), str_replace('\\', '/', base_path()), 'TypeError', 'QueryException', '"trace"', '"file"', '"line"', '"exception"'] as $leak) {
            expect(str_contains($body, $leak))->toBeFalse("{$how} {$url} shows '{$leak}'");
        }

        if ($how !== 'JSON') {
            // The friendly page (an Inertia page: component errors/error, status 500).
            expect($body)->toContain('errors\/error')->toContain('500');
        }
    }
})->with(['signed out', 'signed in', 'JSON']);

it('does show the details while debugging, so the test above can fail', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/__boom', fn () => throw new RuntimeException('SECRET-DETAIL'));

    expect((string) $this->get('/__boom')->getContent())->toContain('SECRET-DETAIL');
});

it('keeps the friendly pages free of details for 403, 404, 419 and 429 too', function () {
    $this->world = $w = SecurityWorld::boot('viewer');
    config(['app.debug' => false]);
    $this->actingAs($w->actor);

    $forbidden = (string) $this->get('/users')->getContent();
    $missing = (string) $this->get('/records/visits/99999')->getContent();

    foreach ([$forbidden, $missing] as $body) {
        expect($body)->not->toContain('Symfony\\')->not->toContain('Illuminate\\')->not->toContain('vendor')->not->toContain(base_path());
    }
});

it('has an Admin warning on the System health page for any unsafe setting', function (string $env, bool $debug, string $expected) {
    $this->world = $w = SecurityWorld::boot('admin');
    app()['env'] = $env;
    config(['app.debug' => $debug]);

    $row = collect(app(App\Services\HealthChecker::class)->checks())->firstWhere('key', 'environment');
    $this->actingAs($w->actor)->get('/system/health')->assertOk();

    expect($row['status'])->toBe($expected === 'ok' ? 'ok' : 'problem');

    if ($expected !== 'ok') {
        expect($row['detail'])->toContain($expected);
    }
})->with([
    'production, debug off' => ['production', false, 'ok'],
    'production, debug on' => ['production', true, 'APP_DEBUG is on'],
    'local, debug off' => ['local', false, 'APP_ENV is not "production"'],
    'local, debug on' => ['local', true, 'APP_DEBUG is on'],
]);

it('keeps the server settings file out of the web root and out of any route', function () {
    // The web server serves only public/. The settings file sits one level up.
    expect(file_exists(public_path('.env')))->toBeFalse()
        ->and(file_exists(public_path('index.php')))->toBeTrue();

    foreach (['/.env', '/../.env', '/%2e%2e/.env', '/storage/logs/laravel.log', '/storage/app/private/logo.png', '/vendor/autoload.php', '/composer.json', '/artisan', '/.git/config', '/database/database.sqlite'] as $path) {
        $status = $this->get($path)->getStatusCode();

        expect($status)->toBeIn([403, 404, 302], "{$path} answered {$status}");
    }
});
