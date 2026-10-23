<?php

use App\Http\Middleware\SecurityHeaders;
use App\Services\HospitalSettings;
use App\Services\RedactSecretsInLogs;
use App\Services\TablePaging;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;

/*
 * Step 7d-4, fixes 3 to 6: the page number is kept between 1 and 100000, the log
 * cleaner also catches JSON-style keys and nested context, the public logo sets no
 * cookie, and HSTS is sent only over HTTPS.
 */

beforeEach(function () {
    Vite::useHotFile(storage_path('framework/testing/no-hot-file'));
});

// --- 3. The page number ----------------------------------------------------------

it('keeps the page number between 1 and 100000', function (mixed $given, int $expected) {
    $request = Request::create('/x', 'GET', $given === null ? [] : ['page' => $given]);

    expect(TablePaging::page($request))->toBe($expected);
})->with([
    'missing' => [null, 1],
    'one' => ['1', 1],
    'five' => ['5', 5],
    'zero' => ['0', 1],
    'negative' => ['-3', 1],
    'text' => ['abc', 1],
    'decimal' => ['2.5', 1],
    'a list' => [['2'], 1],
    'the highest' => ['100000', 100000],
    'one more' => ['100001', 100000],
    'seven digits' => ['1000000', 100000],
    'twenty digits' => ['99999999999999999999', 100000],
    'padded zeros' => ['0000000000000005', 5],
]);

it('does not fail on a huge page number: System health and a database list', function (string $url) {
    $this->actingAs(userWithRole('admin'));

    $response = $this->get($url.'?page=99999999999999999999');

    expect($response->getStatusCode())->toBeLessThan(500);

    // A page past the end goes to the last page, which opens.
    $this->get($response->isRedirect() ? $response->headers->get('Location') : $url)->assertOk();
})->with(['/system/health', '/users', '/audit-log', '/records/visits']);

it('also clamps a number just inside the integer range', function () {
    $this->actingAs(userWithRole('admin'));

    expect($this->get('/users?page=9223372036854775807')->getStatusCode())->toBeLessThan(500)
        ->and($this->get('/system/health?page=9223372036854775807')->getStatusCode())->toBeLessThan(500);
});

// --- 4. The log cleaner ----------------------------------------------------------

it('cleans JSON-style keys, upper-case setting names and values that are not text', function (string $line, string $secret) {
    $clean = RedactSecretsInLogs::redact($line);

    expect($clean)->not->toContain($secret)->toContain(RedactSecretsInLogs::HIDDEN);
})->with([
    'password' => ['{"password":"Hunter2-pass","name":"x"}', 'Hunter2-pass'],
    'password with a space after the colon' => ['{"password": "Hunter2 pass"}', 'Hunter2 pass'],
    'DB_PASSWORD' => ['{"DB_PASSWORD":"dbpass-123456"}', 'dbpass-123456'],
    'RESTORE_TEST_PASSWORD' => ['{"RESTORE_TEST_PASSWORD":"restorepass-1"}', 'restorepass-1'],
    'token' => ['{"token":"tok-abcdef-99"}', 'tok-abcdef-99'],
    'a number' => ['{"password":87654321}', '87654321'],
    'a password with a quote in it' => ['{"password":"ab\"cd-secret"}', 'cd-secret'],
    'JSON inside a JSON string' => ['ctx {\"password\":\"inner-secret-9\"}', 'inner-secret-9'],
    'env style' => ['DB_PASSWORD=envpass-7777 next', 'envpass-7777'],
    'single quoted' => ["password = 'two words'", 'two words'],
]);

it('keeps the rest of a line that holds no secret', function () {
    $line = '{"visit_id":12,"category":"ER","user":"admin.garcia"}';

    expect(RedactSecretsInLogs::redact($line))->toBe($line);
});

it('cleans a password in the log context, also in nested arrays, and never fails on other value types', function () {
    $path = storage_path('framework/testing/redact-'.uniqid().'.log');
    File::ensureDirectoryExists(dirname($path));

    $logger = new Logger(new Monolog('test', [new StreamHandler($path)]));
    (new RedactSecretsInLogs)($logger);

    $logger->error('Login problem', [
        'password' => 'Plain-Secret-1',
        'user' => ['name' => 'a', 'DB_PASSWORD' => 'Nested-Secret-2', 'deeper' => [['token' => 'Deep-Token-3']]],
        'number' => 12345678,
        'flag' => true,
        'nothing' => null,
        'object' => new stdClass,
        'float' => 1.5,
        'resource_less' => ['password' => 98765432],
    ]);
    $logger->info('Message with a secret: RESTORE_TEST_PASSWORD=Restore-Secret-4');

    $log = (string) file_get_contents($path);
    File::delete($path);

    expect($log)->toContain('Login problem')->toContain(RedactSecretsInLogs::HIDDEN)
        ->not->toContain('Plain-Secret-1')->not->toContain('Nested-Secret-2')
        ->not->toContain('Deep-Token-3')->not->toContain('98765432')
        ->not->toContain('Restore-Secret-4');
});

it('does not fail when a configured secret is not text', function () {
    config(['database.connections.broken' => 'not an array', 'database.connections.mysql.password' => 12345678, 'app.previous_keys' => [['x']]]);

    expect(RedactSecretsInLogs::redact('plain text'))->toBe('plain text');
});

// --- 5. The public logo: no cookie, no session --------------------------------------

it('sends no cookie and starts no session for the public logo', function () {
    Storage::fake('local');
    app(HospitalSettings::class)->storeImage('logo', UploadedFile::fake()->image('logo.png', 200, 80));

    $response = $this->get('/hospital-logo')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'")
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('120');
});

it('sends no cookie for the public logo even when no logo is set', function () {
    Storage::fake('local');

    $response = $this->get('/hospital-logo')->assertNotFound();

    expect($response->headers->getCookies())->toBeEmpty();
});

it('still starts a session on the login page', function () {
    $response = $this->get('/login')->assertOk();

    expect($response->headers->getCookies())->not->toBeEmpty();
});

// --- 6. HSTS -------------------------------------------------------------------

it('sends Strict-Transport-Security only over HTTPS', function () {
    $plain = $this->get('/login');
    $secure = $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/login');

    expect($plain->headers->has('Strict-Transport-Security'))->toBeFalse()
        ->and($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        // The other headers do not depend on it.
        ->and($plain->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($secure->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

it('sends Strict-Transport-Security on an HTTPS error page too, and not on plain HTTP', function () {
    $secure = $this->get('https://localhost/no-such-page');
    $plain = $this->get('http://localhost/no-such-page');

    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('builds the same policy text as before', function () {
    expect(SecurityHeaders::policy('abc'))->toContain("script-src 'self' 'nonce-abc'");
});
