<?php

use App\Services\HospitalSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Step 7c: the logo on the login page and in the top bar.
 *
 * GET /hospital-logo is public (nobody is signed in on the login page), read
 * only and cached, and serves ONLY the logo file. The signatures stay behind
 * sign-in.
 */

beforeEach(function () {
    Storage::fake('local');
});

function storeLogo(): void
{
    app(HospitalSettings::class)->storeImage('logo', UploadedFile::fake()->image('logo.png', 200, 80));
}

function storeSignatures(): void
{
    $settings = app(HospitalSettings::class);
    $settings->storeImage('prepared_signature', UploadedFile::fake()->image('p.png', 120, 50));
    $settings->storeImage('noted_signature', UploadedFile::fake()->image('n.png', 90, 40));
}

it('serves the logo to a signed-out visitor, cached and with safe headers', function () {
    storeLogo();

    $response = $this->get('/hospital-logo')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=86400')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'");
});

it('serves exactly the logo bytes and nothing else', function () {
    storeLogo();
    storeSignatures();

    $logo = Storage::disk('local')->get('logo.png');
    $served = $this->get('/hospital-logo')->assertOk()->streamedContent();

    expect($served)->toBe($logo)
        ->and($served)->not->toBe(Storage::disk('local')->get('signature-prepared.png'))
        ->and($served)->not->toBe(Storage::disk('local')->get('signature-noted.png'));
});

it('answers 404 when no logo is set, even if signatures exist', function () {
    storeSignatures();

    $this->get('/hospital-logo')->assertNotFound();
});

it('has no way to ask for another file', function (string $uri) {
    storeLogo();
    storeSignatures();

    $logo = Storage::disk('local')->get('logo.png');
    $response = $this->get($uri);

    // Either the same logo (the extra input is ignored) or a 404, never another file.
    if ($response->getStatusCode() === 200) {
        expect($response->streamedContent())->toBe($logo);
    } else {
        $response->assertNotFound();
    }
})->with([
    'file name' => '/hospital-logo?file=signature-prepared.png',
    'who' => '/hospital-logo?who=prepared',
    'path' => '/hospital-logo?path=../.env',
    'subpath' => '/hospital-logo/signature-prepared.png',
    'version' => '/hospital-logo?v=../../.env',
]);

it('is read only', function (string $method) {
    storeLogo();

    expect($this->call($method, '/hospital-logo')->getStatusCode())->toBeIn([404, 405]);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('still keeps both signatures behind sign-in', function (string $who) {
    storeSignatures();

    $this->get("/settings/hospital/signature/{$who}")->assertRedirect(route('login'));
    $this->actingAs(userWithRole('encoder'))->get("/settings/hospital/signature/{$who}")->assertForbidden();
})->with(['prepared', 'noted']);

it('still keeps the signed-in logo route behind sign-in', function () {
    storeLogo();

    $this->get('/settings/hospital/logo')->assertRedirect(route('login'));
});

it('shares the public logo address on the login page and null when none is set', function () {
    $this->get('/login')->assertInertia(fn ($page) => $page->where('hospital.login_logo_url', null)->where('hospital.logo_url', null));

    storeLogo();

    $this->get('/login')->assertInertia(fn ($page) => $page
        ->where('hospital.login_logo_url', fn ($url) => str_starts_with($url, '/hospital-logo?v='))
        ->where('hospital.logo_url', null));
});

it('shares a logo address for the top bar to every role: the signed-in route for Admin and Viewer, the public route for the others', function (string $role, bool $signedIn) {
    storeLogo();

    $user = userWithRole($role);
    $this->actingAs($user);

    $page = $this->get('/settings/profile')->assertOk()->inertiaProps('hospital');

    expect($page['logo_url'])->toStartWith($signedIn ? '/settings/hospital/logo?v=' : '/hospital-logo?v=');

    $this->get($page['logo_url'])->assertOk();
})->with([
    'admin' => ['admin', true],
    'viewer' => ['viewer', true],
    'encoder' => ['encoder', false],
    'system_admin' => ['system_admin', false],
]);

it('loads the top bar logo for every role that is given the address', function (string $role) {
    storeLogo();

    $user = userWithRole($role);
    $url = $this->actingAs($user)->get('/settings/profile')->inertiaProps('hospital')['logo_url'];

    $this->get($url)->assertOk();
})->with(['admin', 'viewer']);

it('shares no top-bar logo to any role when none is set', function (string $role) {
    $this->actingAs(userWithRole($role));

    expect($this->get('/settings/profile')->inertiaProps('hospital')['logo_url'])->toBeNull();
})->with(['admin', 'viewer', 'encoder', 'system_admin']);
