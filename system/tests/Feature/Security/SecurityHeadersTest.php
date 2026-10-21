<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\Support\SecurityWorld;

/*
 * Step 7a, check 5 (headers): every response carries X-Frame-Options,
 * X-Content-Type-Options, Referrer-Policy and a Content-Security-Policy. The
 * policy is the strictest that works with Inertia and the built Vite files:
 * scripts only from this server plus one inline script that has a new random
 * nonce on every response.
 */

beforeEach(function () {
    // The built files in public/build, not the Vite dev server (public/hot may exist on a developer PC).
    Vite::useHotFile(storage_path('framework/testing/no-hot-file'));
});

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

/** The Content-Security-Policy of a response as [directive => list of sources]. @return array<string, list<string>> */
function secCspOf(TestResponse $response): array
{
    $policy = [];

    foreach (explode(';', (string) $response->headers->get('Content-Security-Policy')) as $part) {
        $words = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);

        if ($words !== []) {
            $policy[array_shift($words)] = $words;
        }
    }

    return $policy;
}

function secAssertSecurityHeaders(TestResponse $response, string $where): void
{
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN', "{$where}: X-Frame-Options")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', "{$where}: X-Content-Type-Options")
        ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin', "{$where}: Referrer-Policy")
        ->and($response->headers->get('Content-Security-Policy'))->toBeString()->not->toBeEmpty("{$where}: Content-Security-Policy");
}

it('sends the security headers on every kind of response', function (string $case) {
    $this->world = $w = SecurityWorld::boot($case === 'login page' || $case === 'unknown page' || $case === 'health check' || $case === 'redirect to login' ? 'guest' : ($case === 'forbidden page' ? 'viewer' : 'admin'));
    $w->backupFakes();

    if ($w->actor !== null) {
        $this->actingAs($w->actor);
    }

    $response = match ($case) {
        'login page' => $this->get('/login'),
        'redirect to login' => $this->get('/encode'),
        'health check' => $this->get('/up'),
        'unknown page' => $this->get('/no-such-page'),
        'forbidden page' => $this->get('/encode'),
        'signed-in page' => $this->get('/encode'),
        'page loader (Inertia JSON)' => $this->get('/encode', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => inertia()->getVersion()]),
        'JSON answer' => $this->getJson('/patients/search?q=cruz'),
        'validation refusal (JSON)' => $this->postJson('/visits', []),
        'redirect after a save' => $this->put('/settings/hospital', ['hospital_name' => 'Header Hospital']),
        'Excel download' => $this->get('/reports/export/excel'),
        'PDF download' => $this->get('/reports/export/pdf'),
        'Excel template download' => $this->get('/records/import/template'),
        'not found record' => $this->get('/records/import/999999'),
    };

    secAssertSecurityHeaders($response, $case);
})->with([
    'login page', 'redirect to login', 'health check', 'unknown page', 'forbidden page', 'signed-in page', 'page loader (Inertia JSON)',
    'JSON answer', 'validation refusal (JSON)', 'redirect after a save', 'Excel download', 'PDF download', 'Excel template download', 'not found record',
]);

it('sends the security headers on a 429, a 500 and a picture, and keeps the stricter policy of the picture', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->withLogo();
    $this->actingAs($w->actor);

    // The picture sets its own, stricter policy: kept.
    $picture = $this->get('/settings/hospital/logo');
    secAssertSecurityHeaders($picture, 'logo picture');
    expect($picture->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'");

    // Too many requests.
    foreach (range(1, 10) as $i) {
        $this->get('/records/import/template');
    }

    $limited = $this->get('/records/import/template');
    expect($limited->getStatusCode())->toBe(429);
    secAssertSecurityHeaders($limited, '429 page');

    // A server error.
    config(['app.debug' => false]);
    Illuminate\Support\Facades\Route::middleware('web')->get('/__boom', fn () => throw new RuntimeException('boom'));
    $error = $this->get('/__boom');

    expect($error->getStatusCode())->toBe(500);
    secAssertSecurityHeaders($error, '500 page');
});

it('has a Content-Security-Policy that allows only this server, with no inline or eval script', function () {
    $response = $this->get('/login');
    $csp = secCspOf($response);

    expect($csp['default-src'])->toBe(["'self'"])
        ->and($csp['object-src'])->toBe(["'none'"])
        ->and($csp['base-uri'])->toBe(["'self'"])
        ->and($csp['form-action'])->toBe(["'self'"])
        ->and($csp['frame-ancestors'])->toBe(["'self'"])
        ->and($csp['connect-src'])->toBe(["'self'"])
        ->and($csp['img-src'])->toBe(["'self'", 'data:', 'blob:'])
        ->and($csp['font-src'])->toBe(["'self'", 'data:'])
        ->and($csp['script-src'][0])->toBe("'self'")
        ->and($csp['script-src'])->toHaveCount(2)
        ->and($csp['script-src'][1])->toMatch("/^'nonce-[A-Za-z0-9]{40}'$/")
        ->and($csp['script-src'])->not->toContain("'unsafe-inline'")->not->toContain("'unsafe-eval'")->not->toContain('*')->not->toContain('data:')->not->toContain('blob:')->not->toContain('http:')->not->toContain('https:');

    // No directive allows another site.
    foreach ($csp as $directive => $sources) {
        foreach ($sources as $source) {
            expect($source)->not->toMatch('#^(https?:|wss?:|//|\*)#', "{$directive} allows {$source}");
        }
    }
});

it('puts a new nonce on every response, and the same nonce on every script of that page', function () {
    $nonces = [];

    foreach (range(1, 3) as $i) {
        $response = $this->get('/login');
        $html = (string) $response->getContent();
        $nonce = trim(secCspOf($response)['script-src'][1], "'");
        $nonce = substr($nonce, strlen('nonce-'));
        $nonces[] = $nonce;

        preg_match_all('/<script\b([^>]*)>/i', $html, $tags);

        expect($tags[1])->not->toBeEmpty();

        foreach ($tags[1] as $attributes) {
            $isData = str_contains($attributes, 'type="application/json"');

            // Every script that can run is the page data (never run), or carries this response's nonce.
            expect($isData || str_contains($attributes, "nonce=\"{$nonce}\""))->toBeTrue("A script without the nonce: <script {$attributes}>");
        }

        // No inline event handlers and no javascript: addresses.
        expect(preg_match('/\son[a-z]+\s*=\s*["\']/i', $html))->toBe(0)
            ->and($html)->not->toContain('javascript:');

        // The inline script is there (dark mode), with the nonce.
        expect($html)->toContain("<script nonce=\"{$nonce}\">");
    }

    expect(array_unique($nonces))->toHaveCount(3);
});

it('loads every script, style and font of the page from this server', function () {
    $html = (string) $this->get('/login')->getContent();

    preg_match_all('/(?:src|href)="([^"]+)"/i', $html, $links);

    foreach ($links[1] as $link) {
        if (str_starts_with($link, 'data:') || str_starts_with($link, '#')) {
            continue;
        }

        expect(parse_url($link, PHP_URL_HOST))->toBeIn([null, 'localhost'], "A page link to another site: {$link}");
    }
});

it('has built files that need no other site, no eval and no dynamic code', function () {
    $assets = base_path('public/build/assets');

    if (! is_dir($assets)) {
        $this->markTestSkipped('The interface is not built (npm run build).');
    }

    $files = glob($assets.'/*.{js,css}', GLOB_BRACE) ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $name = basename($file);

        if (str_ends_with($file, '.js')) {
            // Code built from text would need 'unsafe-eval' in the policy.
            expect(preg_match('/(?<![\w.$])eval\s*\(|new\s+Function\s*\(/', $content))->toBe(0, "{$name} builds code from text");
        }

        if (str_ends_with($file, '.css')) {
            preg_match_all('/url\(\s*[\'"]?([^)\'"]+)/i', $content, $urls);

            foreach ($urls[1] as $url) {
                expect($url)->not->toMatch('#^(https?:)?//#', "{$name} loads {$url}");
            }
        }
    }
});

it('adds the Vite dev server to the policy only while the dev server runs and never in production', function () {
    $nonce = str_repeat('a', 40);
    $hot = storage_path('framework/testing/hot-'.uniqid());
    file_put_contents($hot, 'http://[::1]:5173');

    try {
        Vite::useHotFile($hot);

        $local = $this->get('/login');
        expect($local->headers->get('Content-Security-Policy'))->toContain('http://[::1]:5173')->toContain('ws://[::1]:5173');

        // The same dev file in production mode is ignored.
        app()['env'] = 'production';
        $production = $this->get('/login');
        expect($production->headers->get('Content-Security-Policy'))->not->toContain('5173');
    } finally {
        @unlink($hot);
    }

    expect(SecurityHeaders::policy($nonce))->not->toContain('5173')->toContain("'nonce-{$nonce}'");
});
