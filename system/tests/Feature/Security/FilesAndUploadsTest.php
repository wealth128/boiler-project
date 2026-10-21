<?php

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SecurityWorld;

/*
 * Step 7a, check 8: pictures and files. The logo and signature uploads
 * refuse anything that is not a small PNG or JPG and save a re-encoded copy
 * outside public/. Pictures are served only to Admin and Viewer. No address
 * parameter can reach another file.
 */

afterEach(function () {
    ($this->world ?? null)?->cleanUp();
});

/** The bytes of a real picture made with GD. */
function secPictureBytes(string $type, int $width = 40, int $height = 30): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));

    ob_start();
    match ($type) {
        'png' => imagepng($image),
        'jpeg' => imagejpeg($image),
        'gif' => imagegif($image),
        'webp' => imagewebp($image),
        'bmp' => imagebmp($image),
    };

    return (string) ob_get_clean();
}

function secUploadOf(string $bytes, string $name, string $mime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'pic');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, $mime, null, true);
}

/** Send the hospital form with one picture as Admin. */
function secSendPicture(object $test, string $field, UploadedFile $file)
{
    return $test->json('PUT', '/settings/hospital', ['hospital_name' => 'Picture Hospital', $field => $file]);
}

// --- Uploads that are refused ------------------------------------------------------------

it('refuses what is not a small PNG or JPG, for the logo and both signatures, and stores nothing', function (string $field, string $case) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $file = match ($case) {
        'an SVG' => secUploadOf('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>', 'logo.svg', 'image/svg+xml'),
        'an SVG renamed .png' => secUploadOf('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'logo.png', 'image/png'),
        'an SVG renamed .jpg' => secUploadOf('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.jpg', 'image/jpeg'),
        'PHP code renamed .png' => secUploadOf("<?php echo 'x'; ?>", 'logo.png', 'image/png'),
        'HTML renamed .png' => secUploadOf('<html><script>alert(1)</script></html>', 'logo.png', 'image/png'),
        'text renamed .jpg' => secUploadOf('just some text', 'logo.jpg', 'image/jpeg'),
        'an empty file named .png' => secUploadOf('', 'logo.png', 'image/png'),
        'a GIF' => secUploadOf(secPictureBytes('gif'), 'logo.gif', 'image/gif'),
        'a GIF renamed .png' => secUploadOf(secPictureBytes('gif'), 'logo.png', 'image/png'),
        'a WebP renamed .jpg' => secUploadOf(secPictureBytes('webp'), 'logo.jpg', 'image/jpeg'),
        'a BMP renamed .png' => secUploadOf(secPictureBytes('bmp'), 'logo.png', 'image/png'),
        'a PDF renamed .png' => secUploadOf("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF", 'logo.png', 'image/png'),
        'a zip renamed .png' => secUploadOf("PK\x03\x04".str_repeat("\0", 60), 'logo.png', 'image/png'),
        'a PNG over 1 MB' => UploadedFile::fake()->image('logo.png', 100, 100)->size(2048),
        'a JPG over 1 MB' => UploadedFile::fake()->image('logo.jpg', 100, 100)->size(1500),
        'a PNG 1001 pixels wide' => secUploadOf(secPictureBytes('png', 1001, 10), 'logo.png', 'image/png'),
        'a PNG 1001 pixels high' => secUploadOf(secPictureBytes('png', 10, 1001), 'logo.png', 'image/png'),
        'a JPG 2000 by 2000' => secUploadOf(secPictureBytes('jpeg', 2000, 2000), 'logo.jpg', 'image/jpeg'),
    };

    $before = Storage::disk('local')->allFiles();
    $settings = Setting::orderBy('key')->pluck('value', 'key')->all();

    $response = secSendPicture($this, $field, $file);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json("errors.{$field}.0"))->toBeString()->not->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBe($before)
        ->and(Setting::orderBy('key')->pluck('value', 'key')->all())->toBe($settings)
        ->and(AuditLog::where('action', 'like', 'settings.%')->count())->toBe(0);
})->with(['logo', 'prepared_signature', 'noted_signature'])->with([
    'an SVG', 'an SVG renamed .png', 'an SVG renamed .jpg', 'PHP code renamed .png', 'HTML renamed .png', 'text renamed .jpg', 'an empty file named .png',
    'a GIF', 'a GIF renamed .png', 'a WebP renamed .jpg', 'a BMP renamed .png', 'a PDF renamed .png', 'a zip renamed .png',
    'a PNG over 1 MB', 'a JPG over 1 MB', 'a PNG 1001 pixels wide', 'a PNG 1001 pixels high', 'a JPG 2000 by 2000',
]);

it('refuses several files or a folder where one picture is expected', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    $before = Storage::disk('local')->allFiles();

    $response = $this->json('PUT', '/settings/hospital', ['hospital_name' => 'X Hospital', 'logo' => [secUploadOf(secPictureBytes('png'), 'a.png', 'image/png'), secUploadOf(secPictureBytes('png'), 'b.png', 'image/png')]]);

    expect($response->getStatusCode())->toBe(422)
        ->and(Storage::disk('local')->allFiles())->toBe($before);
});

// --- Uploads that are accepted: re-encoded, kept private ---------------------------------------------

it('saves a good picture as a new copy with a name the server picks, outside public/', function (string $type, string $extension, string $mime) {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    secSendPicture($this, 'logo', secUploadOf(secPictureBytes($type, 120, 60), '../../../evil name; rm -rf.'.$extension, $mime))->assertRedirect();

    $files = Storage::disk('local')->allFiles();
    $stored = Setting::where('key', 'logo_path')->value('value');

    expect($files)->toBe([$stored])
        ->and($stored)->toBe($type === 'png' ? 'logo.png' : 'logo.jpg')
        ->and(file_exists(public_path($stored)))->toBeFalse()
        ->and(Storage::disk('local')->path($stored))->not->toStartWith(public_path())
        ->and(@getimagesizefromstring(Storage::disk('local')->get($stored))[0])->toBe(120);
})->with([['png', 'png', 'image/png'], ['jpeg', 'jpg', 'image/jpeg']]);

it('drops anything hidden in a picture: code after the image data, text blocks and metadata', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    // A real PNG with PHP and script text added at the end and a text chunk inside.
    $png = secPictureBytes('png');
    $polyglot = $png.'<?php system($_GET["c"]); ?><script>alert(1)</script>SECRET-TRAILER';

    secSendPicture($this, 'logo', secUploadOf($polyglot, 'logo.png', 'image/png'))->assertRedirect();

    $stored = Storage::disk('local')->get(Setting::where('key', 'logo_path')->value('value'));

    expect($stored)->not->toContain('<?php')->not->toContain('<script')->not->toContain('SECRET-TRAILER')
        ->and($stored)->not->toBe($polyglot)
        ->and(substr($stored, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    // A JPG with a comment segment (where cameras and tools keep names, places and other text).
    $jpeg = secPictureBytes('jpeg');
    $comment = 'SECRET-META-GPS-12.34N';
    $withComment = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2);

    secSendPicture($this, 'prepared_signature', secUploadOf($withComment, 'sig.jpg', 'image/jpeg'))->assertRedirect();

    $signature = Storage::disk('local')->get(Setting::where('key', 'prepared_by_signature')->value('value'));

    expect($withComment)->toContain($comment)
        ->and($signature)->not->toContain($comment)
        ->and(substr($signature, 0, 2))->toBe("\xFF\xD8");
});

it('keeps the image type by what the file is, not by what it is called', function () {
    $this->world = $w = SecurityWorld::boot('admin');
    $this->actingAs($w->actor);

    // A real JPG called .png is saved as a JPG, never as .png with JPG bytes behind it.
    secSendPicture($this, 'logo', secUploadOf(secPictureBytes('jpeg'), 'logo.png', 'image/png'))->assertRedirect();

    expect(Setting::where('key', 'logo_path')->value('value'))->toBe('logo.jpg')
        ->and(substr(Storage::disk('local')->get('logo.jpg'), 0, 2))->toBe("\xFF\xD8");
});

// --- Who is served a picture ---------------------------------------------------------------------------

it('serves the current and the frozen pictures only to Admin and Viewer, with safe headers', function (string $actor, bool $served) {
    $this->world = $w = SecurityWorld::boot($actor);
    $w->closedAugust();
    $w->withLogo();

    if ($w->actor !== null) {
        $this->actingAs($w->actor);
    }

    foreach (['/settings/hospital/logo', '/settings/hospital/signature/prepared', '/settings/hospital/signature/noted', '/reports/signatures/2026-08/prepared', '/reports/signatures/2026-08/noted'] as $url) {
        $response = $this->get($url);

        if ($served) {
            expect($response->getStatusCode())->toBe(200, $url)
                ->and($response->headers->get('Content-Type'))->toBeIn(['image/png', 'image/jpeg'])
                ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
                ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'")
                ->and($response->headers->get('Cache-Control'))->toContain('private')
                ->and($response->headers->get('Content-Disposition'))->not->toContain('attachment; filename="../');
        } else {
            expect($response->getStatusCode())->toBeIn([302, 303, 403], $url);
            $content = $response->baseResponse instanceof Symfony\Component\HttpFoundation\StreamedResponse ? (string) $response->streamedContent() : (string) $response->getContent();
            expect(str_contains($content, 'PNG'))->toBeFalse($url);
        }
    }
})->with([
    ['admin', true], ['viewer', true], ['encoder', false], ['system_admin', false], ['guest', false], ['must_change', false], ['deactivated', false],
]);

it('has no route that downloads a backup, a log, the settings file or a stored import', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => in_array('GET', $route->methods(), true));

    foreach ($routes as $route) {
        expect($route->uri())->not->toMatch('#backup.*(download|file)|download|logs?/|\.env|import/.*(file|download)#i', $route->uri());
    }

    expect($routes->filter(fn ($route) => str_starts_with($route->uri(), 'backups'))->map->uri()->values()->all())->toBe(['backups']);
});

// --- Path traversal in every address parameter ----------------------------------------------------------

dataset('evil parameters', [
    'parent folder' => '../../.env',
    'many parent folders' => '../../../../../../../../etc/passwd',
    'encoded dots' => '%2e%2e%2f%2e%2e%2f.env',
    'double encoded' => '%252e%252e%252f.env',
    'encoded backslash' => '..%5c..%5c.env',
    'absolute path' => '/etc/passwd',
    'null byte' => 'prepared%00.png',
    'null byte then path' => '../.env%00.png',
    'only dots' => '..',
    'one dot' => '.',
    'spaces' => '%20%20',
    'a very long name' => str_repeat('a', 3000),
    'unicode dots' => '%EF%BC%8E%EF%BC%8E%2F.env',
    'a wildcard' => '*',
    'a star path' => '*/*',
    'url' => 'http://evil.example/x',
    'php wrapper' => 'php://filter/resource=.env',
    'file wrapper' => 'file:///etc/passwd',
    'sql' => "1' OR '1'='1",
    'a list' => 'x[]',
    'semicolon' => 'a;b',
    'new line' => 'a%0d%0ab',
]);

it('turns away odd values in any address parameter, serves no file, and changes nothing', function (string $evil) {
    $this->world = $w = SecurityWorld::boot('admin');
    $w->closedAugust();
    $w->withLogo();
    $this->actingAs($w->actor);

    // A file the server must never hand out.
    Storage::disk('local')->put('canary-secret.txt', 'CANARY-SECRET-CONTENT');
    file_put_contents(base_path('.canary-test-file'), 'CANARY-SECRET-CONTENT');

    try {
        $count = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! preg_match('/\{(\w+)\??\}/', $route->uri()) || $route->uri() === '{fallbackPlaceholder}' || str_starts_with($route->uri(), 'storage/')) {
                continue;
            }

            foreach ($route->parameterNames() as $target) {
                $uri = '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($target, $evil, $w) {
                    if ($m[1] === $target) {
                        return $evil;
                    }

                    return match ($m[1]) {
                        'period' => '2026-08', 'list' => 'diagnoses', 'type' => 'visits', 'span' => '2026-08', 'who' => 'prepared',
                        'user' => (string) $w->otherUser->id, 'visit' => (string) $w->visit->id, 'patient' => (string) $w->patient->id, 'batch' => (string) $w->batch->id,
                        default => (string) $w->bracket->id,
                    };
                }, $route->uri()), '/');

                foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                    $before = SecurityWorld::state();
                    $response = $this->call($method, $uri, $method === 'GET' ? [] : ['reason' => 'Duplicate entry', 'confirm' => 'x', 'direction' => 'up']);
                    $body = $response instanceof Symfony\Component\HttpFoundation\StreamedResponse ? (string) $response->getContent().(string) $response->streamedContent() : (string) $response->getContent();

                    expect($response->getStatusCode())->toBeIn([404, 403, 405, 422, 400, 301, 302, 308], "{$method} {$uri} gave {$response->getStatusCode()}")
                        ->and(str_contains($body, 'CANARY-SECRET-CONTENT'))->toBeFalse("{$method} {$uri} served the canary file")
                        ->and(preg_match('/^(APP_KEY|DB_PASSWORD)=/m', $body))->toBe(0, "{$method} {$uri} served the settings file")
                        ->and(str_contains($body, '<?php'))->toBeFalse()
                        ->and(SecurityWorld::state())->toBe($before, "{$method} {$uri} changed something");

                    if (in_array($response->getStatusCode(), [301, 302, 308], true)) {
                        expect(parse_url((string) $response->headers->get('Location'), PHP_URL_HOST))->toBeIn([null, 'localhost']);
                    }

                    $count++;
                }
            }
        }

        expect($count)->toBeGreaterThan(20);
    } finally {
        @unlink(base_path('.canary-test-file'));
    }
})->with('evil parameters');
