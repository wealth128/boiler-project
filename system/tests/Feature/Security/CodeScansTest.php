<?php

use App\Services\Setup\MysqlAdmin;
use Illuminate\Support\Facades\Route;

/*
 * Step 7a, check 4: scans of the source code. Each scan finds every use of
 * something risky. A use that is safe is listed below WITH the reason. A new
 * use that is not listed fails the test, so the next person has to look at it.
 * (The report lists each finding with its file and line:
 * docs/security-review-2026-10.md.)
 */

/** Every file under a folder with one of these extensions, as [relative path => lines]. @return array<string, list<string>> */
function secScanFiles(string $folder, array $extensions, array $skip = []): array
{
    $found = [];
    $root = realpath(base_path($folder));

    if ($root === false) {
        return $found;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/');

        if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
            continue;
        }

        foreach ($skip as $part) {
            if (str_contains($relative, $part)) {
                continue 2;
            }
        }

        $found[$relative] = preg_split('/\R/', (string) file_get_contents($file->getPathname()));
    }

    return $found;
}

/** Lines that match a pattern: ["file:line" => trimmed line]. @param  array<string, list<string>>  $files */
function secScanMatches(array $files, string $pattern): array
{
    $hits = [];

    foreach ($files as $path => $lines) {
        foreach ($lines as $i => $line) {
            // A comment is not code (only PHP, TypeScript and Blade comments are skipped).
            if (preg_match('/^\s*(\*|\/\/|\/\*|#|\{\{--)/', $line)) {
                continue;
            }

            if (preg_match($pattern, $line)) {
                $hits[$path.':'.($i + 1)] = trim($line);
            }
        }
    }

    return $hits;
}

it('has no dangerouslySetInnerHTML or other raw HTML / script insertion in the interface code', function () {
    $files = secScanFiles('resources/js', ['ts', 'tsx', 'js', 'jsx'], ['resources/js/actions/', 'resources/js/routes/', 'resources/js/wayfinder/']);

    expect($files)->not->toBeEmpty()
        ->and(secScanMatches($files, '/dangerouslySetInnerHTML|\.innerHTML\s*=|\.outerHTML\s*=|insertAdjacentHTML|document\.write\s*\(|\beval\s*\(|new Function\s*\(|\.srcdoc\s*=|javascript:/'))
        ->toBe([]);
});

it('has no unescaped output in the Blade views', function () {
    $files = secScanFiles('resources/views', ['php']);

    expect($files)->not->toBeEmpty()
        ->and(secScanMatches($files, '/\{!!/'))->toBe([])
        ->and(secScanMatches($files, '/@php\s*\(?\s*echo|<\?=|<\?php\s+echo/'))->toBe([]);
});

it('has raw SQL only with constants, bound values, or names checked first', function () {
    $files = secScanFiles('app', ['php']) + secScanFiles('routes', ['php']) + secScanFiles('database', ['php']);

    $hits = secScanMatches($files, '/DB::raw|whereRaw|selectRaw|orderByRaw|groupByRaw|havingRaw|joinRaw|DB::statement|->statement\(|unprepared|DB::select\(|DB::insert\(|DB::update\(|DB::delete\(|->raw\(/');

    // Raw SQL with a PHP variable inside a double-quoted string, or glued on with a dot, must be on this list, with the reason.
    $interpolated = array_filter($hits, fn (string $line): bool => (bool) preg_match('/"[^"]*\$[^"]*"|\.\s*\$/', $line));

    $allowed = [
        'app/Services/Setup/MysqlAdmin.php' => 'setup only (install, restore, test restore): names are checked against a fixed pattern or constant first, passwords go through PDO::quote',
    ];

    foreach ($interpolated as $where => $line) {
        expect(array_key_exists(explode(':', $where)[0], $allowed))->toBeTrue("Raw SQL with a variable at {$where}: {$line}");
    }

    // The searches that take typed text use a bound value (?), never the text itself.
    $searches = array_filter($hits, fn (string $line, string $where): bool => str_starts_with($where, 'app/Http/Controllers/') && str_contains($line, 'LIKE'), ARRAY_FILTER_USE_BOTH);

    expect($searches)->not->toBeEmpty();

    foreach ($searches as $where => $line) {
        expect($line)->toContain('LIKE ?');
    }
});

it('checks every database or user name in the setup SQL before it is used', function () {
    $mysql = new MysqlAdmin;

    foreach (['evil`name', "evil'name", 'a;drop', 'x y', '', str_repeat('a', 65), "a\nb", 'päth'] as $name) {
        expect(fn () => $mysql->recreateDatabase($name))->toThrow(RuntimeException::class);
        expect(fn () => $mysql->provision($name, 'census_app', 'secret-password-1234'))->toThrow(RuntimeException::class);
        expect(fn () => $mysql->provision('patient_census', $name, 'secret-password-1234'))->toThrow(RuntimeException::class);
    }

    foreach (['patient_census', 'other_db', "patient_census_restore_test`; DROP DATABASE patient_census; --"] as $name) {
        if ($name !== MysqlAdmin::SCRATCH_DATABASE) {
            expect(fn () => $mysql->resetScratchDatabase($name))->toThrow(RuntimeException::class);
            expect(fn () => $mysql->dropScratchDatabase($name))->toThrow(RuntimeException::class);
        }
    }
});

it('has no model without a fillable list, and no unguarded model or request-wide mass assignment', function () {
    $models = glob(app_path('Models/*.php'));

    expect($models)->not->toBeEmpty();

    foreach ($models as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if (! $reflection->isSubclassOf(Illuminate\Database\Eloquent\Model::class)) {
            continue;
        }

        $attributes = $reflection->getAttributes(Illuminate\Database\Eloquent\Attributes\Fillable::class);

        expect($attributes)->not->toBeEmpty("{$class} has no #[Fillable] list");
        expect((new $class)->getFillable())->not->toBeEmpty("{$class} has an empty fillable list");
        expect($reflection->hasProperty('guarded') && $reflection->getProperty('guarded')->getDefaultValue() === [])->toBeFalse("{$class} is unguarded");
    }

    $files = secScanFiles('app', ['php']) + secScanFiles('database', ['php']);

    expect(secScanMatches($files, '/Model::unguard|Model::unguarded|\$guarded\s*=\s*\[\s*\]/'))->toBe([]);

    // Nobody hands the whole request to a model.
    expect(secScanMatches(secScanFiles('app', ['php']), '/->(create|update|fill|forceFill|forceCreate|insert|upsert|firstOrCreate|updateOrCreate)\(\s*\$(request|this)->(all|input|post|json|validated)\(\)/'))->toBe([]);
});

it('keeps the user fillable list to name, username, password, role, office and active flag', function () {
    expect((new App\Models\User)->getFillable())->toBe(['name', 'username', 'password', 'role', 'office', 'is_active']);
});

it('redirects only to named routes, back(), or an address that was checked', function () {
    $files = secScanFiles('app', ['php']);

    $hits = secScanMatches($files, '/\bredirect\(\s*\$|\bredirect\(\)->(to|away)\(|->away\(|Redirect::(to|away)\(|\bto_route\(\s*\$/');

    // Each of these redirects to a value that was checked first.
    $allowed = [
        'app/Http/Controllers/Encode/VisitController.php' => 'ReturnToRecords: only /records/visits on this site, no host, no scheme',
        'app/Http/Controllers/Records/RecordSearch.php' => 'redirectBack: only the same Records page of this site',
        'app/Services/TablePaging.php' => 'the same page with ?page= changed (fullUrlWithQuery)',
    ];

    foreach ($hits as $where => $line) {
        expect(array_key_exists(explode(':', $where)[0], $allowed))->toBeTrue("A redirect to a variable at {$where}: {$line}");
    }
});

it('never sends a redirect to another site, whatever the address in the request says', function (string $address) {
    $user = userWithRole('admin');

    foreach (['return_to', 'return', 'redirect', 'next', 'url', 'intended'] as $key) {
        $this->actingAs($user);
        $response = $this->get("/encode?{$key}=".urlencode($address));
        $location = (string) $response->headers->get('Location');

        expect(parse_url($location, PHP_URL_HOST))->toBeIn([null, 'localhost']);
    }

    // After sign-in the browser goes to the role's own page, not to an address in the request.
    $encoder = userWithRole('encoder');
    $this->post('/logout');
    $response = $this->post('/login?redirect='.urlencode($address), ['username' => $encoder->username, 'password' => 'password', 'redirect' => $address, 'intended' => $address]);

    expect(parse_url((string) $response->headers->get('Location'), PHP_URL_HOST))->toBeIn([null, 'localhost']);
})->with(['https://evil.example/', '//evil.example/', 'http://localhost@evil.example/', '/\\evil.example']);

it('writes nothing under public/ and never makes the storage link', function () {
    $files = secScanFiles('app', ['php']);

    expect(secScanMatches($files, '/public_path\s*\(|disk\(\s*[\'"]public[\'"]|Storage::(put|putFile|putFileAs|move|copy|writeStream)\(.*public\//'))->toBe([]);
    expect(secScanMatches(secScanFiles('scripts', ['bat', 'ps1', 'cmd', 'sh']), '/storage:link|mklink/i'))->toBe([]);
    expect(file_exists(public_path('storage')))->toBeFalse();

    // The disks the code writes to are private.
    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
});

it('puts a role rule on every route whose controller sends a file', function () {
    $senders = [];

    foreach (secScanFiles('app/Http/Controllers', ['php']) as $path => $lines) {
        if (preg_match('/response\(\)->(file|download|streamDownload)|->response\(|Excel::raw|Excel::download|streamedContent|BinaryFileResponse|StreamedResponse/', implode("\n", $lines))) {
            $senders[] = 'App\\Http\\Controllers\\'.str_replace('/', '\\', substr($path, strlen('app/Http/Controllers/'), -4));
        }
    }

    expect($senders)->not->toBeEmpty();

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $controller = explode('@', (string) $route->getActionName())[0];

        if (! in_array($controller, $senders, true)) {
            continue;
        }

        // Step 7c: the login-page logo is public on purpose. It serves only the logo, never a signature (PublicLogoTest).
        if ($route->getName() === 'hospital.logo.public') {
            continue;
        }

        expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:')))
            ->toBeTrue("{$route->uri()} sends a file but has no role: rule");
    }
});

it('runs programs only with a list of arguments and never through a shell', function () {
    $files = secScanFiles('app', ['php']);

    // Real PHP tokens (a "`" inside a string or a comment is not the shell operator).
    $shell = [];

    foreach ($files as $path => $lines) {
        $tokens = token_get_all(implode("\n", $lines));

        foreach ($tokens as $i => $token) {
            if ($token === '`') {
                $shell[] = "{$path}: backtick shell operator";
            }

            if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'], true)) {
                $next = $tokens[$i + 1] ?? null;
                $previous = $tokens[$i - 1] ?? null;
                $previous = is_array($previous) && $previous[0] === T_WHITESPACE ? ($tokens[$i - 2] ?? null) : $previous;
                $isCall = $next === '(' && ! (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true));

                if ($isCall) {
                    $shell[] = "{$path}:{$token[2]}: {$token[1]}()";
                }
            }
        }
    }

    expect($shell)->toBe([]);

    // Every ->run( on a Process gets a list (an array or a method that returns one), never a string.
    $runs = secScanMatches($files, '/->run\(/');

    foreach ($runs as $where => $line) {
        expect($line)->not->toMatch('/->run\(\s*[\'"]/', "{$where} runs a command string: {$line}");
    }

    // The places that start a program, and what they start (nothing a user typed).
    $starts = array_keys(secScanMatches($files, '/Process::(timeout|path|env|input|run)\(/'));
    $folders = array_unique(array_map(fn (string $where): string => explode(':', $where)[0], $starts));
    sort($folders);

    expect($folders)->toBe([
        'app/Services/BackupService.php',
        'app/Services/Setup/AppMaintenance.php',
        'app/Services/Setup/MysqlAdmin.php',
        'app/Services/UsbCopyService.php',
    ]);
});

it('has no secret in a tracked file and keeps .env and _private out of Git', function () {
    $root = dirname(base_path());
    $ignore = (string) file_get_contents($root.'/.gitignore')."\n".(string) file_get_contents(base_path('.gitignore'));

    expect($ignore)->toContain('_private/')->toContain('.env');

    // .env.example has no value for any password, key or secret (only empty, null or "").
    $secretKeys = '/^(APP_KEY|[A-Z_]*PASSWORD|[A-Z_]*SECRET[A-Z_]*|[A-Z_]*TOKEN[A-Z_]*|AWS_ACCESS_KEY_ID)=(.*)$/m';
    preg_match_all($secretKeys, (string) file_get_contents(base_path('.env.example')), $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    foreach ($matches as $match) {
        expect(trim($match[2]))->toBeIn(['', '""', "''", 'null'], "{$match[1]} in .env.example has a value");
    }

    // No private key, cloud key or real-looking APP_KEY in the code, config, scripts, docs or planning.
    $files = secScanFiles('app', ['php']) + secScanFiles('config', ['php']) + secScanFiles('routes', ['php']) + secScanFiles('database', ['php']) + secScanFiles('resources', ['php', 'ts', 'tsx']) + secScanFiles('scripts', ['bat', 'ps1']);

    foreach (['docs', 'planning'] as $folder) {
        foreach (glob($root.'/'.$folder.'/*.md') ?: [] as $doc) {
            $files[$folder.'/'.basename($doc)] = preg_split('/\R/', (string) file_get_contents($doc));
        }
    }

    $found = secScanMatches($files, '/-----BEGIN [A-Z ]*PRIVATE KEY-----|AKIA[0-9A-Z]{16}|APP_KEY\s*=\s*base64:[A-Za-z0-9+\/=]{20,}|DB_PASSWORD\s*=\s*[^\s"\'][^\s]*|RESTORE_TEST_PASSWORD\s*=\s*[^\s"\'][^\s]*/');

    expect($found)->toBe([]);

    // Passwords in code are never a literal string (the test seeds and factories are in tests/ and database/factories).
    $code = secScanFiles('app', ['php']) + secScanFiles('config', ['php']);
    $literal = secScanMatches($code, '/[\'"](password|secret|api_key|token)[\'"]\s*=>\s*[\'"][^\'"\$]{6,}[\'"]/i');
    $literal = array_filter($literal, fn (string $line): bool => ! preg_match('/message|label|required|rules|=> \'(hashed|boolean|datetime|array|string)\'/i', $line));

    expect($literal)->toBe([]);
});
