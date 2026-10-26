<?php

namespace Tests\Support;

/**
 * Reads the theme tokens from resources/css/app.css (the light ones from
 * :root, the dark ones from `.dark` inside `@media screen`) and computes the
 * WCAG contrast ratio of a pair. Used by the contrast test, and by
 * `php artisan tinker` / a script to print the token list.
 */
final class ThemeTokens
{
    /** @return array<string, string> name (no dashes prefix) => hex, for 'light' or 'dark' */
    public static function for(string $theme): array
    {
        // Windows or Unix line endings, whichever way git checked the file out.
        $css = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('css/app.css')));

        $light = self::block($css, ':root {');
        $dark = self::block($css, "@media screen {\n    .dark {");

        return $theme === 'light' ? $light : [...$light, ...$dark];
    }

    /** The color of one chart rule that only the dark screen uses, e.g. ('.rc-ink', 'fill'). */
    public static function darkChart(string $selector, string $property): string
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $pattern = '/\.dark \.rc-chart svg '.preg_quote($selector, '/').'\s*\{\s*'.$property.':\s*(#[0-9a-f]{6})/i';

        preg_match($pattern, $css, $match);

        return $match[1] ?? throw new \RuntimeException("No dark chart rule for $selector");
    }

    /** @return array<string, string> */
    private static function block(string $css, string $opening): array
    {
        $start = strpos($css, $opening);

        if ($start === false) {
            throw new \RuntimeException("Not found in app.css: $opening");
        }

        $end = strpos($css, '}', $start);
        $body = substr($css, $start + strlen($opening), $end - $start - strlen($opening));

        preg_match_all('/--([a-z-]+):\s*(#[0-9a-f]{6})\s*;/i', $body, $matches, PREG_SET_ORDER);

        $tokens = [];

        foreach ($matches as $match) {
            $tokens[$match[1]] = strtolower($match[2]);
        }

        return $tokens;
    }

    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(function (string $part): float {
            $value = hexdec($part) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function ratio(string $a, string $b): float
    {
        $one = self::luminance($a);
        $two = self::luminance($b);

        return (max($one, $two) + 0.05) / (min($one, $two) + 0.05);
    }
}
