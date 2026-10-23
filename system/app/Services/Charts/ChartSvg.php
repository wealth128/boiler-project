<?php

namespace App\Services\Charts;

/**
 * Draws one report chart as a plain, flat, 2D inline SVG (no script, no
 * gradient, no shadow, no pattern). One drawer serves the screen, Print and
 * the PDF, so all three show the same chart.
 *
 * Two sizes are drawn from the same data: a wide one (640 units) and a
 * narrow one (300 units) for phones. Units are about one CSS pixel on the
 * screen they are made for, so the 12.5 unit text stays readable at 360 px
 * wide. In the PDF and in Print the wide one fills the page width and its
 * text comes out above 9 pt.
 *
 * A chart is the array ReportCharts makes:
 *   kind      columns | share | pyramid | bars | paired
 *   title, subtitle, aria, empty (bool), message
 *   series    [{label, color}]
 *   rows      [{label, values: [one number for each series], tip, short?, note?}]
 *
 * Every value is written as text on or beside its bar. Labels are wrapped,
 * never cut; the full text is also the tooltip (title) of the bar.
 */
final class ChartSvg
{
    public const WIDE = 640;

    public const NARROW = 280;

    private const FONT = "'DejaVu Sans', Verdana, Arial, sans-serif";

    /** The text size of everything but the title, and the title. */
    private const SIZE = 12.5;

    private const TITLE = 15;

    /** @var list<string> */
    private array $out = [];

    private float $y = 0;

    private function __construct(private readonly float $width, private readonly bool $narrow) {}

    /**
     * The chart as an SVG string.
     *
     * @param  array<string, mixed>  $chart
     */
    public static function render(array $chart, bool $narrow = false): string
    {
        $svg = new self($narrow ? self::NARROW : self::WIDE, $narrow);

        return $svg->draw($chart);
    }

    /** @param  array<string, mixed>  $chart */
    private function draw(array $chart): string
    {
        $pad = $this->pad();
        $this->y = $pad;

        $this->heading($chart['title'], $chart['subtitle']);

        if ($chart['empty']) {
            $this->message($chart['message']);
        } else {
            match ($chart['kind']) {
                'columns' => $this->columns($chart),
                'share' => $this->share($chart),
                'pyramid' => $this->pyramid($chart),
                'bars' => $this->bars($chart),
                'paired' => $this->paired($chart),
            };
        }

        $height = (int) ceil($this->y + $pad);
        $w = $this->n($this->width);

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %d" width="%s" height="%d" role="img" aria-label="%s" font-family="%s">',
            $w, $height, $w, $height, $this->e($chart['aria']), $this->e(self::FONT),
        ).'<rect x="0" y="0" width="'.$w.'" height="'.$height.'" fill="#FFFFFF"/>'.implode('', $this->out).'</svg>';
    }

    // --- Parts shared by every chart -----------------------------------------------

    private function pad(): float
    {
        return $this->narrow ? 8 : 12;
    }

    private function heading(string $title, string $subtitle): void
    {
        $pad = $this->pad();

        foreach ($this->wrap($title, $this->fit($this->width - 2 * $pad, self::TITLE, bold: true)) as $line) {
            $this->out[] = $this->text($pad, $this->y + self::TITLE, $line, self::TITLE, ChartPalette::INK, bold: true);
            $this->y += 19;
        }

        foreach ($this->wrap($subtitle, $this->fit($this->width - 2 * $pad, self::SIZE)) as $line) {
            $this->out[] = $this->text($pad, $this->y + self::SIZE + 1, $line, self::SIZE, ChartPalette::MUTED);
            $this->y += 17;
        }

        $this->y += 8;
    }

    private function message(string $message): void
    {
        $this->y += 10;
        $this->out[] = $this->text($this->width / 2, $this->y + self::SIZE, $message, 14, ChartPalette::MUTED, anchor: 'middle');
        $this->y += 34;
    }

    /**
     * A row of colored squares with their names, wrapping onto a new row when
     * it does not fit.
     *
     * @param  list<array{label: string, color: string}>  $items
     */
    private function legend(array $items): void
    {
        $pad = $this->pad();
        $x = $pad;

        foreach ($items as $item) {
            $itemWidth = 18 + $this->textWidth($item['label'], self::SIZE) + 14;

            if ($x > $pad && $x + $itemWidth - 14 > $this->width - $pad) {
                $x = $pad;
                $this->y += 20;
            }

            $this->out[] = '<rect x="'.$this->n($x).'" y="'.$this->n($this->y + 1).'" width="12" height="12" fill="'.$item['color'].'" stroke="'.ChartPalette::INK.'" stroke-width="0.6"/>';
            $this->out[] = $this->text($x + 18, $this->y + 11.5, $item['label'], self::SIZE, ChartPalette::INK);
            $x += $itemWidth;
        }

        $this->y += 24;
    }

    // --- Daily census: stacked columns ------------------------------------------------

    /** @param  array<string, mixed>  $chart */
    private function columns(array $chart): void
    {
        $pad = $this->pad();
        $series = $chart['series'];
        $rows = $chart['rows'];
        $count = count($rows);

        if (count($series) > 1) {
            $this->legend($series);
        }

        $totals = array_map(fn (array $row): int|float => array_sum($row['values']), $rows);
        [$axisMax, $step] = $this->scale((float) max($totals));

        $left = $pad + $this->textWidth($this->num($axisMax), self::SIZE) + 8;
        $right = $this->width - $pad;
        $plotWidth = $right - $left;
        $plotHeight = $this->narrow ? 150 : 200;
        $top = $this->y + 18;
        $bottom = $top + $plotHeight;

        // Light grid lines with the figure of each.
        for ($tick = 0.0; $tick <= $axisMax + 0.0001; $tick += $step) {
            $at = $bottom - $tick / $axisMax * $plotHeight;
            $this->out[] = $this->line($left, $at, $right, $at, $tick == 0.0 ? ChartPalette::AXIS : ChartPalette::GRID);
            $this->out[] = $this->text($left - 6, $at + 4, $this->num($tick), self::SIZE, ChartPalette::MUTED, anchor: 'end');
        }

        $slot = $plotWidth / $count;
        $barWidth = max(2.0, $slot * 0.72);

        // Each column has its total on top when the number fits over the column; if not,
        // only the busiest column has one (the exact figures are in the table below).
        $fits = $this->textWidth($this->num((float) max($totals)), self::SIZE) + 2 <= $slot;
        $peak = array_search(max($totals), $totals, true);

        foreach ($rows as $index => $row) {
            $x = $left + $index * $slot + ($slot - $barWidth) / 2;
            $stack = $bottom;
            $parts = [];

            foreach ($series as $position => $one) {
                $value = $row['values'][$position];

                if ($value <= 0) {
                    continue;
                }

                $height = $value / $axisMax * $plotHeight;
                $stack -= $height;
                $parts[] = '<rect x="'.$this->n($x).'" y="'.$this->n($stack).'" width="'.$this->n($barWidth).'" height="'.$this->n($height).'" fill="'.$one['color'].'" stroke="#FFFFFF" stroke-width="0.6"/>';
            }

            $this->out[] = '<g class="rc-bar" tabindex="0"><title>'.$this->e($row['tip']).'</title>'.implode('', $parts).'</g>';

            if ($totals[$index] > 0 && ($fits || $index === $peak)) {
                $this->out[] = $this->text($x + $barWidth / 2, $stack - 4, $this->num($totals[$index]), self::SIZE, ChartPalette::INK, anchor: 'middle');
            }
        }

        // Day or month names under the columns, every few when they would touch.
        $widest = max(array_map(fn (array $row): float => $this->textWidth($row['short'], self::SIZE), $rows));
        $every = max(1, (int) ceil(($widest + 6) / $slot));

        foreach ($rows as $index => $row) {
            if ($index % $every === 0) {
                $this->out[] = $this->text($left + $index * $slot + $slot / 2, $bottom + 17, $row['short'], self::SIZE, ChartPalette::MUTED, anchor: 'middle');
            }
        }

        $this->y = $bottom + 26;
    }

    // --- Male vs Female: one 100% bar ---------------------------------------------------

    /** @param  array<string, mixed>  $chart */
    private function share(array $chart): void
    {
        $pad = $this->pad();
        [$male, $female] = $chart['rows'][0]['values'];
        $total = $male + $female;
        $width = $this->width - 2 * $pad;
        $height = 30;
        $maleWidth = $width * $male / $total;

        $parts = [];
        $at = $pad;

        foreach ([[$male, $maleWidth, $chart['series'][0]['color'], '#FFFFFF'], [$female, $width - $maleWidth, $chart['series'][1]['color'], ChartPalette::INK]] as [$value, $segment, $color, $ink]) {
            if ($value <= 0) {
                continue;
            }

            $parts[] = '<rect x="'.$this->n($at).'" y="'.$this->n($this->y).'" width="'.$this->n($segment).'" height="'.$height.'" fill="'.$color.'" stroke="#FFFFFF" stroke-width="1"/>';
            $percent = $this->percent($value, $total);

            // The share inside the segment when it fits; the legend below always has it.
            if ($segment > $this->textWidth($percent, 13, bold: true) + 10) {
                $parts[] = $this->text($at + $segment / 2, $this->y + 20, $percent, 13, $ink, anchor: 'middle', bold: true);
            }

            $at += $segment;
        }

        $this->out[] = '<g class="rc-bar" tabindex="0"><title>'.$this->e($chart['rows'][0]['tip']).'</title>'.implode('', $parts).'</g>';
        $this->y += $height + 14;

        $this->legend([
            ['label' => $chart['series'][0]['label'].' '.$this->num($male).' · '.$this->percent($male, $total), 'color' => $chart['series'][0]['color']],
            ['label' => $chart['series'][1]['label'].' '.$this->num($female).' · '.$this->percent($female, $total), 'color' => $chart['series'][1]['color']],
        ]);
    }

    // --- Age by sex: pyramid --------------------------------------------------------------

    /** @param  array<string, mixed>  $chart */
    private function pyramid(array $chart): void
    {
        $pad = $this->pad();
        $this->legend($chart['series']);

        // Oldest at the top, like a population pyramid.
        $rows = array_reverse($chart['rows']);
        $max = (float) max(array_map(fn (array $row): int => max($row['values']), $rows));

        $labelWidth = max(array_map(fn (array $row): float => $this->textWidth($row['label'], self::SIZE), $rows)) + 8;
        $half = ($this->width - 2 * $pad - $labelWidth) / 2;
        $numberRoom = $this->textWidth($this->num($max), self::SIZE) + 6;
        $length = $half - $numberRoom;
        [$axisMax, $step] = $this->scale($max);
        $middle = $pad + $labelWidth + $half;
        $rowHeight = 25;
        $top = $this->y;
        $bottom = $top + count($rows) * $rowHeight;

        // Grid lines and their figures on both sides; on a narrow chart only every second (or third) figure, so none touch.
        $every = max(1, (int) ceil(($this->textWidth($this->num($axisMax), self::SIZE) + 6) / ($step / $axisMax * $length)));

        for ($tick = $step, $number = 1; $tick <= $axisMax + 0.0001; $tick += $step, $number++) {
            foreach ([-1, 1] as $side) {
                $x = $middle + $side * $tick / $axisMax * $length;
                $this->out[] = $this->line($x, $top, $x, $bottom, ChartPalette::GRID);

                if ($number % $every === 0) {
                    $this->out[] = $this->text($x, $bottom + 16, $this->num($tick), self::SIZE, ChartPalette::MUTED, anchor: 'middle');
                }
            }
        }

        foreach ($rows as $index => $row) {
            $y = $top + $index * $rowHeight;
            $parts = [$this->text($pad, $y + 17, $row['label'], self::SIZE, ChartPalette::INK)];

            foreach ([-1, 1] as $position => $side) {
                $value = $row['values'][$position];
                $barLength = $value / $axisMax * $length;

                if ($value > 0) {
                    $x = $side < 0 ? $middle - $barLength : $middle;
                    $parts[] = '<rect x="'.$this->n($x).'" y="'.$this->n($y + 3).'" width="'.$this->n($barLength).'" height="18" fill="'.$chart['series'][$position]['color'].'"/>';
                }

                // The count at the end of the bar (a 0 beside the middle line).
                $parts[] = $this->text($middle + $side * ($barLength + 4), $y + 17, $this->num($value), self::SIZE, ChartPalette::INK, anchor: $side < 0 ? 'end' : 'start');
            }

            $this->out[] = '<g class="rc-bar" tabindex="0"><title>'.$this->e($row['tip']).'</title>'.implode('', $parts).'</g>';
        }

        $this->out[] = $this->line($middle, $top, $middle, $bottom, ChartPalette::AXIS);
        $this->y = $bottom + 24;
    }

    // --- Horizontal bars: diagnoses, branch of service -------------------------------------

    /** @param  array<string, mixed>  $chart */
    private function bars(array $chart): void
    {
        $pad = $this->pad();
        $rows = $chart['rows'];
        $color = $chart['series'][0]['color'];
        $max = (float) max(array_map(fn (array $row): int => $row['values'][0], $rows));
        [$axisMax, $step] = $this->scale($max);

        $ends = array_map(fn (array $row): string => $this->num($row['values'][0]).($row['note'] ?? ''), $rows);
        $endRoom = max(array_map(fn (string $end): float => $this->textWidth($end, self::SIZE, bold: true), $ends)) + 8;

        // Wide: names to the left of the bars. Narrow: each name above its bar, over the whole width.
        $labelWidth = $this->narrow ? 0.0 : 200.0;
        $left = $pad + ($this->narrow ? 0 : $labelWidth + 10);
        $length = $this->width - $pad - $left - $endRoom;
        $lineHeight = 15;
        $barHeight = 18;

        $layout = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $lines = $this->wrap($row['label'], $this->fit($this->narrow ? $this->width - 2 * $pad : $labelWidth, self::SIZE));
            $height = $this->narrow
                ? count($lines) * $lineHeight + 4 + $barHeight + 10
                : max($barHeight, count($lines) * $lineHeight) + 10;
            $layout[] = [$lines, $height];
            $total += $height;
        }

        $top = $this->y;
        $bottom = $top + $total;

        for ($tick = 0.0; $tick <= $axisMax + 0.0001; $tick += $step) {
            $x = $left + $tick / $axisMax * $length;
            $this->out[] = $this->line($x, $top, $x, $bottom, $tick == 0.0 ? ChartPalette::AXIS : ChartPalette::GRID);
            $this->out[] = $this->text($x, $bottom + 16, $this->num($tick), self::SIZE, ChartPalette::MUTED, anchor: 'middle');
        }

        $y = $top;

        foreach ($rows as $index => $row) {
            [$lines, $height] = $layout[$index];
            $value = $row['values'][0];
            $barLength = $value > 0 ? max(1.0, $value / $axisMax * $length) : 0.0;
            $parts = [];

            if ($this->narrow) {
                foreach ($lines as $position => $line) {
                    $parts[] = $this->text($pad, $y + 5 + self::SIZE + $position * $lineHeight, $line, self::SIZE, ChartPalette::INK);
                }

                $barY = $y + 5 + count($lines) * $lineHeight + 3;
            } else {
                $block = max($barHeight, count($lines) * $lineHeight);

                foreach ($lines as $position => $line) {
                    $parts[] = $this->text($pad, $y + 5 + ($block - count($lines) * $lineHeight) / 2 + self::SIZE - 1 + $position * $lineHeight, $line, self::SIZE, ChartPalette::INK);
                }

                $barY = $y + 5 + ($block - $barHeight) / 2;
            }

            if ($barLength > 0) {
                $parts[] = '<rect x="'.$this->n($left).'" y="'.$this->n($barY).'" width="'.$this->n($barLength).'" height="'.$barHeight.'" fill="'.$color.'"/>';
            }

            $parts[] = $this->text($left + $barLength + 5, $barY + 13.5, $ends[$index], self::SIZE, ChartPalette::INK, bold: true);

            $this->out[] = '<g class="rc-bar" tabindex="0"><title>'.$this->e($row['tip']).'</title>'.implode('', $parts).'</g>';
            $y += $height;
        }

        $this->y = $bottom + 26;
    }

    // --- This period against the previous one ---------------------------------------------

    /** @param  array<string, mixed>  $chart */
    private function paired(array $chart): void
    {
        $pad = $this->pad();
        $this->legend($chart['series']);

        $rows = $chart['rows'];
        $max = (float) max(array_map(fn (array $row): int => max($row['values']), $rows));
        [$axisMax, $step] = $this->scale($max);
        $endRoom = $this->textWidth($this->num($max), self::SIZE, bold: true) + 10;
        $left = $pad;
        $length = $this->width - 2 * $pad - $endRoom;
        $groupHeight = 22 + 2 * 22 + 12;
        $top = $this->y;
        $bottom = $top + count($rows) * $groupHeight;

        for ($tick = 0.0; $tick <= $axisMax + 0.0001; $tick += $step) {
            $x = $left + $tick / $axisMax * $length;
            $this->out[] = $this->line($x, $top, $x, $bottom, $tick == 0.0 ? ChartPalette::AXIS : ChartPalette::GRID);
            $this->out[] = $this->text($x, $bottom + 16, $this->num($tick), self::SIZE, ChartPalette::MUTED, anchor: 'middle');
        }

        foreach ($rows as $index => $row) {
            $y = $top + $index * $groupHeight;

            // "Patients   +12 (+8.5%)" over the pair of bars.
            $parts = [$this->text($pad, $y + 15, trim($row['label'].'   '.($row['note'] ?? '')), 13, ChartPalette::INK, bold: true)];

            foreach ($row['values'] as $position => $value) {
                $barY = $y + 22 + $position * 22;
                $barLength = $value > 0 ? max(1.0, $value / $axisMax * $length) : 0.0;

                if ($barLength > 0) {
                    $parts[] = '<rect x="'.$this->n($left).'" y="'.$this->n($barY).'" width="'.$this->n($barLength).'" height="18" fill="'.$chart['series'][$position]['color'].'" stroke="'.ChartPalette::INK.'" stroke-width="0.6"/>';
                }

                $parts[] = $this->text($left + $barLength + 5, $barY + 13.5, $this->num($value), self::SIZE, ChartPalette::INK, bold: true);
            }

            $this->out[] = '<g class="rc-bar" tabindex="0"><title>'.$this->e($row['tip']).'</title>'.implode('', $parts).'</g>';
        }

        $this->y = $bottom + 26;
    }

    // --- Small helpers -----------------------------------------------------------------------

    /**
     * A round top for an axis and the gap between its grid lines, for
     * counts: whole numbers, in steps of 1, 2, 5 or 10 times a power of ten,
     * about four steps up to the top.
     *
     * @return array{0: float, 1: float}
     */
    private function scale(float $max): array
    {
        if ($max <= 0) {
            return [1.0, 1.0];
        }

        $raw = $max / 4;
        $power = 10 ** floor(log10($raw));
        $step = 10 * $power;

        foreach ([1, 2, 5, 10] as $multiple) {
            if ($multiple * $power >= $raw) {
                $step = $multiple * $power;
                break;
            }
        }

        $step = max(1.0, (float) $step);

        return [ceil($max / $step) * $step, $step];
    }

    /** About how wide the text is, at the given size (a little generous, so it never overlaps). */
    private function textWidth(string $text, float $size, bool $bold = false): float
    {
        return mb_strlen($text) * $size * ($bold ? 0.68 : 0.62);
    }

    /** How many characters fit in the width. */
    private function fit(float $width, float $size, bool $bold = false): int
    {
        return max(4, (int) floor($width / ($size * ($bold ? 0.68 : 0.62))));
    }

    /**
     * The text broken into lines of at most $max characters, at spaces; a
     * single word longer than a line is broken inside. Nothing is cut off.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $max): array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [''] as $word) {
            while (mb_strlen($word) > $max) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }

                $lines[] = mb_substr($word, 0, $max);
                $word = mb_substr($word, $max);
            }

            if ($line === '') {
                $line = $word;
            } elseif (mb_strlen($line) + 1 + mb_strlen($word) <= $max) {
                $line .= ' '.$word;
            } else {
                $lines[] = $line;
                $line = $word;
            }
        }

        $lines[] = $line;

        return $lines;
    }

    private function text(float $x, float $y, string $text, float $size, string $color, string $anchor = 'start', bool $bold = false): string
    {
        return '<text x="'.$this->n($x).'" y="'.$this->n($y).'" font-family="'.$this->e(self::FONT).'" font-size="'.$this->n($size).'" fill="'.$color.'"'
            .($anchor === 'start' ? '' : ' text-anchor="'.$anchor.'"')
            .($bold ? ' font-weight="bold"' : '').'>'.$this->e($text).'</text>';
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $color): string
    {
        return '<line x1="'.$this->n($x1).'" y1="'.$this->n($y1).'" x2="'.$this->n($x2).'" y2="'.$this->n($y2).'" stroke="'.$color.'" stroke-width="1"/>';
    }

    private function num(float|int $value): string
    {
        return number_format($value);
    }

    private function percent(float|int $value, float|int $total): string
    {
        return number_format($total > 0 ? $value / $total * 100 : 0, 1).'%';
    }

    /** A coordinate: at most two decimals, no trailing zeros. */
    private function n(float|int $value): string
    {
        $text = number_format($value, 2, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
