<?php

use App\Services\ReportSheetResolver;

/*
 * Step 7f: the theme cookie is read by the server on first paint (no white
 * flash), Light is the default, and everything that is paper stays light.
 */

it('paints the page dark from the first byte when the cookie says dark', function () {
    $html = $this->withUnencryptedCookie('appearance', 'dark')->get('/login')->assertOk()->getContent();

    expect($html)->toMatch('/<html[^>]*class="dark"/')
        ->and($html)->toContain('color-scheme: dark')
        ->and($html)->toContain("const appearance = 'dark';");
});

it('paints the page light when the cookie says light', function () {
    $html = $this->withUnencryptedCookie('appearance', 'light')->get('/login')->assertOk()->getContent();

    expect($html)->not->toMatch('/<html[^>]*class="dark"/')
        ->and($html)->toContain('color-scheme: light')
        ->and($html)->toContain("const appearance = 'light';");
});

it('defaults to Light with no cookie', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)->not->toMatch('/<html[^>]*class="dark"/')
        ->and($html)->toContain("const appearance = 'light';");
});

it('leaves System to the inline script, which follows the device setting', function () {
    $html = $this->withUnencryptedCookie('appearance', 'system')->get('/login')->assertOk()->getContent();

    expect($html)->not->toMatch('/<html[^>]*class="dark"/')
        ->and($html)->toContain("const appearance = 'system';")
        ->and($html)->toContain('prefers-color-scheme: dark');
});

it('treats an edited cookie as Light and never writes it into the page', function () {
    $html = $this->withUnencryptedCookie('appearance', "x';alert(1);//")->get('/login')->assertOk()->getContent();

    expect($html)->toContain("const appearance = 'light';")
        ->and($html)->not->toContain('alert(1)');
});

it('keeps the first-paint background colors in step with the page tokens', function () {
    $html = $this->get('/login')->getContent();
    $css = file_get_contents(resource_path('css/app.css'));

    preg_match('/--page:\s*(#[0-9a-f]{6})/i', $css, $light);
    preg_match('/@media screen \{\s*\.dark \{\s*--page:\s*(#[0-9a-f]{6})/i', $css, $dark);

    expect($html)->toContain('background-color: '.$light[1].';')
        ->and($html)->toContain('background-color: '.$dark[1].';');
});

it('does not add the dark theme to Print or PDF: no dark class, no dark tokens outside the screen', function () {
    // The PDF page is a plain HTML sheet of its own (no app layout, no theme classes).
    $data = app(ReportSheetResolver::class)->resolve(['type' => 'monthly', 'month' => '2026-09', 'category' => 'ER']);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now()])->render();

    expect($html)->not->toMatch('/class="[^"]*\bdark\b/')
        ->and($html)->not->toContain('dark:')
        ->and($html)->not->toContain('prefers-color-scheme');

    // In app.css the dark tokens live only inside @media screen.
    $css = file_get_contents(resource_path('css/app.css'));
    $outsideScreen = preg_replace('/@media screen \{.*?\R\}\R/s', '', $css);

    expect($outsideScreen)->not->toMatch('/\.dark\s*\{\s*--/');

    // The page also drops the dark class while printing.
    $hook = file_get_contents(resource_path('js/hooks/use-appearance.tsx'));

    expect($hook)->toContain("'beforeprint'")->and($hook)->toContain("'afterprint'");
});

it('has no fixed white or black left in the screens', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (! preg_match('/\.tsx$/', $path) || str_contains($path, '/wayfinder/')) {
            continue;
        }

        $code = file_get_contents($path);

        if (preg_match_all('/\b(?:bg|text|border|ring|fill|stroke|from|to|divide|outline|decoration|shadow)-(?:white|black)\b|\b(?:bg|text|border|ring)-(?:neutral|slate|gray|zinc|stone|red|amber|green|blue|yellow|emerald|sky|orange)-\d+/', $code, $matches)) {
            $offenders[$path] = array_unique($matches[0]);
        }
    }

    expect($offenders)->toBe([]);
});

it('draws the Hospital settings A4 preview and the crop picture as paper, whatever the theme', function () {
    $scaled = file_get_contents(resource_path('js/features/hospital/scaled-page.tsx'));
    $crop = file_get_contents(resource_path('js/features/hospital/crop-dialog.tsx'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($scaled)->toContain('paper')
        ->and($crop)->toContain('paper')
        // A dark: utility never applies inside paper.
        ->and($css)->toContain(':not(:where(.paper, .paper *))');
});
