<?php

use Symfony\Component\Process\Process;

/*
 * The charts are drawn from SVG the server sends, as React elements, never as
 * raw HTML (planning/security.md section 2). resources/js/features/reports/
 * svg-tree.ts keeps only chart tags (svg, g, rect, line, path, text, tspan,
 * title, desc) and chart attributes, and drops every on* handler, href and
 * xlink:href, style with url() and every unknown tag. Its tests are written for
 * vitest (svg-tree.test.ts); this test runs them as part of php artisan test.
 *
 * It needs the project's node_modules (npm install), like npm run build does.
 */

it('keeps the chart SVG whitelist: only chart tags and attributes, no handlers, links, url() styles or unknown tags', function () {
    $vitest = base_path('node_modules/.bin/'.(PHP_OS_FAMILY === 'Windows' ? 'vitest.cmd' : 'vitest'));

    if (! is_file($vitest)) {
        $this->markTestSkipped('node_modules is not installed here (run npm install).');
    }

    $process = new Process([$vitest, 'run', '--config', 'vitest.config.ts'], base_path());
    $process->setTimeout(180);
    $process->run();

    // The output first: when a test fails, it shows which.
    expect($process->getOutput().$process->getErrorOutput())->toContain('11 passed')
        ->and($process->getExitCode())->toBe(0);
});

it('has no raw HTML insertion for the charts: SvgChart builds React elements from the whitelisted tree', function () {
    $component = file_get_contents(resource_path('js/features/reports/svg-chart.tsx'));
    $figure = file_get_contents(resource_path('js/features/reports/chart-figure.tsx'));

    expect($component)->toContain('parseChartSvg')->and($component)->toContain('createElement')
        ->and($figure)->toContain('SvgChart')
        ->and($component.$figure)->not->toMatch('/dangerously|innerHTML|insertAdjacentHTML|__html/i');
});
