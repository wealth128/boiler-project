<?php

use Tests\Support\ThemeTokens;

/*
 * Step 7f: WCAG AA for both themes. Every pair of colors the screens put
 * together (text on its background, icons and field borders on the page, focus
 * rings) is read from resources/css/app.css and its contrast ratio computed:
 * 4.5:1 for text, 3:1 for field borders, icons and focus rings.
 *
 * Not checked on purpose: the data colors of the report charts (OPD, ER,
 * Admission, Male, Female). They are fixed so a report looks the same
 * everywhere, and every bar has its number written beside it.
 */

/** [foreground token, background token] pairs that carry text. */
const THEME_TEXT_PAIRS = [
    ['foreground', 'background'],
    ['foreground', 'page'],
    ['foreground', 'navy-soft'],
    ['foreground', 'accent'],
    ['card-foreground', 'card'],
    ['popover-foreground', 'popover'],
    ['muted-foreground', 'background'],
    ['muted-foreground', 'card'],
    ['muted-foreground', 'muted'],
    ['muted-foreground', 'navy-soft'],
    ['muted-foreground', 'accent'],
    ['secondary-foreground', 'secondary'],
    ['accent-foreground', 'accent'],
    ['primary-foreground', 'primary'],
    ['navy', 'background'],
    ['navy', 'card'],
    ['navy', 'navy-soft'],
    ['navy-foreground', 'navy'],
    ['link', 'background'],
    ['link', 'card'],
    ['link', 'navy-soft'],
    ['link', 'muted'],
    ['destructive', 'background'],
    ['destructive', 'card'],
    ['background', 'destructive'],
    ['bad', 'card'],
    ['bad', 'page'],
    ['bad', 'bad-soft'],
    ['background', 'bad'],
    ['warn', 'card'],
    ['warn', 'warn-soft'],
    ['ok', 'card'],
    ['ok', 'ok-soft'],
    ['bar-foreground', 'bar'],
    ['sidebar-foreground', 'sidebar'],
    ['sidebar-muted', 'sidebar'],
    ['sidebar-bad', 'sidebar'],
    ['sidebar-foreground', 'sidebar-accent'],
    ['sidebar-accent-foreground', 'sidebar-accent'],
    ['sidebar-primary-foreground', 'sidebar-primary'],
];

/** [token, background token] pairs for borders that matter, icons and focus rings. */
const THEME_GRAPHIC_PAIRS = [
    ['input', 'background'],
    ['input', 'card'],
    ['ring', 'background'],
    ['ring', 'card'],
    ['ring', 'navy-soft'],
    ['sidebar-ring', 'sidebar'],
    ['sidebar-ring', 'bar'],
    ['sidebar-bad', 'sidebar'],
];

dataset('themes', ['light', 'dark']);

it('gives every text pair of the theme at least 4.5:1', function (string $theme) {
    $tokens = ThemeTokens::for($theme);
    $failing = [];

    foreach (THEME_TEXT_PAIRS as [$foreground, $background]) {
        expect($tokens)->toHaveKeys([$foreground, $background]);

        $ratio = ThemeTokens::ratio($tokens[$foreground], $tokens[$background]);

        if ($ratio < 4.5) {
            $failing[] = sprintf('%s on %s = %.2f', $foreground, $background, $ratio);
        }
    }

    expect($failing)->toBe([]);
})->with('themes');

it('gives field borders, icons and focus rings at least 3:1', function (string $theme) {
    $tokens = ThemeTokens::for($theme);
    $failing = [];

    foreach (THEME_GRAPHIC_PAIRS as [$color, $background]) {
        expect($tokens)->toHaveKeys([$color, $background]);

        $ratio = ThemeTokens::ratio($tokens[$color], $tokens[$background]);

        if ($ratio < 3.0) {
            $failing[] = sprintf('%s on %s = %.2f', $color, $background, $ratio);
        }
    }

    expect($failing)->toBe([]);
})->with('themes');

it('keeps the chart text, grid edge and bar edge readable on the dark card', function () {
    $card = ThemeTokens::for('dark')['card'];

    expect(ThemeTokens::ratio(ThemeTokens::darkChart('.rc-ink', 'fill'), $card))->toBeGreaterThanOrEqual(4.5)
        ->and(ThemeTokens::ratio(ThemeTokens::darkChart('.rc-muted', 'fill'), $card))->toBeGreaterThanOrEqual(4.5)
        ->and(ThemeTokens::ratio(ThemeTokens::darkChart('.rc-axis', 'stroke'), $card))->toBeGreaterThanOrEqual(3.0)
        ->and(ThemeTokens::ratio(ThemeTokens::darkChart('rect:not(.rc-bg)', 'stroke'), $card))->toBeGreaterThanOrEqual(3.0);
});

it('keeps the light chart text readable on its white paper', function () {
    foreach ([\App\Services\Charts\ChartPalette::INK, \App\Services\Charts\ChartPalette::MUTED] as $text) {
        expect(ThemeTokens::ratio($text, '#ffffff'))->toBeGreaterThanOrEqual(4.5);
    }

    expect(ThemeTokens::ratio(\App\Services\Charts\ChartPalette::AXIS, '#ffffff'))->toBeGreaterThanOrEqual(3.0);
});

it('lists the light navy-and-white palette that was decided', function () {
    $light = ThemeTokens::for('light');

    expect($light['page'])->toBe('#ffffff')
        ->and($light['card'])->toBe('#ffffff')
        ->and($light['navy'])->toBe('#0b2545')
        ->and($light['bar'])->toBe('#0b2545')
        ->and($light['sidebar'])->toBe('#0b2545')
        ->and($light['primary'])->toBe('#0b2545')
        ->and($light['link'])->toBe('#1e5aa8')
        ->and($light['ring'])->toBe('#1e5aa8')
        ->and($light['navy-soft'])->toBe('#eef4fb');
});
