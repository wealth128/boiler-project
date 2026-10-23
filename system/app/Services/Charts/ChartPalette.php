<?php

namespace App\Services\Charts;

/**
 * The few colors every report chart uses (screen, Print, PDF and Excel), so
 * a category or a sex always looks the same. All come from the navy theme.
 *
 * Each pair is also easy to tell apart in black and white: the shades differ
 * a lot in brightness (OPD light, Admission middle, ER dark; Female light,
 * Male dark), and every bar has its number written beside it, so nothing
 * depends on color alone.
 */
final class ChartPalette
{
    /** Visit categories: light blue, navy and green. */
    public const CATEGORIES = [
        'OPD' => '#8FB4E3',
        'ER' => '#1F3864',
        'Admission' => '#2E8B72',
    ];

    public const MALE = '#27508C';

    public const FEMALE = '#F0A93B';

    /** One series charts (diagnoses, branch of service). */
    public const SINGLE = '#3A5A8C';

    /** This period and the previous period. */
    public const THIS_PERIOD = '#1F3864';

    public const PREVIOUS = '#AEB9CC';

    public const INK = '#111827';

    public const MUTED = '#475569';

    public const GRID = '#D9DEE7';

    public const AXIS = '#64748B';

    public static function category(string $name): string
    {
        return self::CATEGORIES[$name] ?? self::SINGLE;
    }

    /** Excel wants the color without the "#". */
    public static function argb(string $hex): string
    {
        return strtoupper(ltrim($hex, '#'));
    }
}
