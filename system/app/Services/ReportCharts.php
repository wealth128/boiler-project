<?php

namespace App\Services;

use App\Services\Charts\ChartPalette;
use App\Services\Charts\ChartSvg;

/**
 * The charts of a report page, made from the tables of ReportSections (and,
 * for the daily census, the report's own day by day figures that its table
 * only totals), so a chart can never show a number its table does not. Each
 * chart sits above the table it comes from (its key is that table's key).
 * Nothing is counted here, so a closed month's saved copy gives the same
 * charts every time.
 *
 * A chart is (see ChartSvg for how it is drawn):
 *   key, kind, title, subtitle, aria, empty, message, series, rows,
 *   source {table, first, last, label_col, cols}   where its numbers are in the table: rows first to
 *                                                  last, the label in column label_col, one series per column in cols
 *   svg, svg_narrow                                the drawing, wide and for phones
 *
 * Summary: daily census, Male vs Female, age range by sex, top 10 diagnoses,
 * branch of service, this period against the previous one. Each category:
 * daily census and top 10 diagnoses.
 */
class ReportCharts
{
    /** Bars of the diagnoses chart. */
    public const TOP = 10;

    public const NO_VISITS = 'No visits in this period';

    /** The hidden Excel sheet holding the day by day figures of the daily chart. */
    public const DATA_SHEET = 'Chart data';

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections  ReportSections::pages()
     * @param  array{by: string, rows: list<array{label: string, counts: array<string, int>}>}  $daily  the report's daily census
     * @return array<string, array<string, array<string, mixed>>> page ("summary" or a category) => chart key => chart
     */
    public function pages(array $sections, string $periodLabel, array $daily): array
    {
        $pages = [];

        foreach ($sections as $page => $tables) {
            $byKey = array_column($tables, null, 'key');
            $charts = [];

            foreach ($this->specs($page, $byKey, $periodLabel, $daily) as $chart) {
                $charts[$chart['key']] = $this->drawn($chart);
            }

            // In the order of the tables they sit above.
            $pages[$page] = array_replace(array_intersect_key(array_flip(array_keys($byKey)), $charts), $charts);
        }

        return $pages;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables  the page's tables by key
     * @return list<array<string, mixed>>
     */
    private function specs(string $page, array $tables, string $period, array $daily): array
    {
        $summary = $page === 'summary';
        $charts = [];

        if (isset($tables['daily'])) {
            $charts[] = $this->daily($tables['daily'], $daily, $summary ? null : $page, $period);
        }

        if ($summary && isset($tables['sex'])) {
            $charts[] = $this->sex($tables['sex'], $period);
        }

        if ($summary && isset($tables['age_sex'])) {
            $charts[] = $this->ageSex($tables['age_sex'], $period);
        }

        if (isset($tables['diagnoses'])) {
            $charts[] = $this->diagnoses($tables['diagnoses'], $summary ? null : $page, $period);
        }

        if ($summary && isset($tables['branches'])) {
            $charts[] = $this->branches($tables['branches'], $period);
        }

        if ($summary && isset($tables['comparison'])) {
            $charts[] = $this->comparison($tables['comparison'], $period);
        }

        return $charts;
    }

    /** The chart with its two drawings. */
    private function drawn(array $chart): array
    {
        return [
            ...$chart,
            'svg' => ChartSvg::render($chart),
            'svg_narrow' => ChartSvg::render($chart, narrow: true),
        ];
    }

    // --- One chart for each table ----------------------------------------------------------

    /**
     * Visits each day (or month), one column each, stacked by category on the
     * Summary; a single color on a category's page. The numbers are the
     * report's daily census (the table above holds only their totals); in Excel they are
     * in the hidden sheet "Chart data", which the chart reads.
     *
     * @param  array<string, mixed>  $table  the totals table the chart sits above
     * @param  array{by: string, rows: list<array{label: string, counts: array<string, int>}>}  $daily
     * @return array<string, mixed>
     */
    private function daily(array $table, array $daily, ?string $category, string $period): array
    {
        $names = array_keys($daily['rows'][0]['counts'] ?? []);
        $shown = $category === null ? $names : [$category];
        $series = array_map(fn (string $name): array => ['label' => $name, 'color' => ChartPalette::category($name)], $shown);
        $many = count($daily['rows']) > 12;

        $lines = [];

        foreach ($daily['rows'] as $row) {
            $values = array_map(fn (string $name): int => (int) ($row['counts'][$name] ?? 0), $shown);
            $parts = [];

            foreach ($shown as $position => $name) {
                $parts[] = $name.' '.number_format($values[$position]);
            }

            $total = array_sum($values);
            $lines[] = [
                'label' => $row['label'],
                'short' => $this->shortName($row['label'], $many),
                'values' => $values,
                'tip' => $row['label'].': '.(count($shown) > 1 ? implode(', ', $parts).' ('.$this->visits($total).')' : $this->visits($total)),
            ];
        }

        $totals = [];

        foreach ($series as $position => $one) {
            $totals[$one['label']] = array_sum(array_column(array_column($lines, 'values'), $position));
        }

        arsort($totals);
        $title = $daily['by'] === 'day' ? 'Daily census' : 'Monthly census';

        return $this->chart('daily', 'columns', $title, $period.' · visits', $series, $lines, empty: array_sum($totals) === 0,
            aria: $title.', '.$period.'. '.$this->ranked($totals, 'visits'),
            source: [
                'table' => $table['key'],
                // Excel: the rows are in the hidden sheet "Chart data" (header in its row 1), one column for each category after the day.
                'sheet' => self::DATA_SHEET,
                'header_row' => 1,
                'first' => 0,
                'last' => max(0, count($lines) - 1),
                'label_col' => 0,
                'cols' => array_map(fn (string $name): int => array_search($name, $names, true) + 1, $shown),
            ]);
    }

    /**
     * One 100% bar: Male and Female patients of all categories, from the "All categories" line of the Sex table.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function sex(array $table, string $period): array
    {
        $rows = $this->bodyRows($table, include: ['total']);
        [$index, $row] = $rows[0] ?? [0, ['cells' => [null, 0, 0]]];
        $male = (int) $row['cells'][1];
        $female = (int) $row['cells'][2];
        $total = $male + $female;

        $series = [
            ['label' => $table['columns'][1]['label'], 'color' => ChartPalette::MALE],
            ['label' => $table['columns'][2]['label'], 'color' => ChartPalette::FEMALE],
        ];

        $tip = sprintf('Male %s (%s), Female %s (%s) of %s patients', number_format($male), $this->percent($male, $total), number_format($female), $this->percent($female, $total), number_format($total));

        return $this->chart('sex', 'share', 'Male vs Female', $period.' · patients, all categories', $series,
            [['label' => (string) $row['cells'][0], 'values' => [$male, $female], 'tip' => $tip]],
            empty: $total === 0,
            aria: "Male vs Female, {$period}. Male ".number_format($male).' patients ('.$this->percent($male, $total).'), Female '.number_format($female).' ('.$this->percent($female, $total).').',
            source: ['table' => $table['key'], 'first' => $index, 'last' => $index, 'label_col' => 0, 'cols' => [1, 2]]);
    }

    /**
     * The pyramid: Male patients to the left and Female to the right, one row for each age range.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function ageSex(array $table, string $period): array
    {
        $rows = $this->bodyRows($table, include: ['body']);
        $series = [
            ['label' => $table['columns'][1]['label'], 'color' => ChartPalette::MALE],
            ['label' => $table['columns'][2]['label'], 'color' => ChartPalette::FEMALE],
        ];

        $lines = [];
        $male = 0;
        $female = 0;
        $parts = [];

        foreach ($rows as [, $row]) {
            $values = [(int) $row['cells'][1], (int) $row['cells'][2]];
            $male += $values[0];
            $female += $values[1];
            $parts[] = $row['cells'][0].' male '.number_format($values[0]).', female '.number_format($values[1]);
            $lines[] = [
                'label' => (string) $row['cells'][0],
                'values' => $values,
                'tip' => $row['cells'][0].': Male '.number_format($values[0]).', Female '.number_format($values[1]),
            ];
        }

        return $this->chart('age_sex', 'pyramid', 'Age range by sex', $period.' · patients', $series, $lines,
            empty: $male + $female === 0,
            aria: "Age range by sex, {$period}. Male ".number_format($male).' patients, Female '.number_format($female).'. '.implode('; ', $parts).'.',
            source: $this->source($table, $rows, [1, 2]));
    }

    /**
     * Horizontal bars of the ten most common diagnoses, highest first as in the table.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function diagnoses(array $table, ?string $category, string $period): array
    {
        $rows = array_slice($this->bodyRows($table, include: ['body']), 0, self::TOP);
        $color = $category === null ? ChartPalette::SINGLE : ChartPalette::category($category);

        $lines = [];
        $parts = [];

        foreach ($rows as [, $row]) {
            $count = (int) $row['cells'][1];
            $percent = ReportSections::format($row['cells'][2], 'pct');
            $parts[] = $row['cells'][0].' '.number_format($count);
            $lines[] = [
                'label' => (string) $row['cells'][0],
                'values' => [$count],
                'tip' => $row['cells'][0].': '.$this->visits($count).' ('.$percent.' of visits)',
            ];
        }

        $title = count($rows) >= self::TOP ? 'Top '.self::TOP.' diagnoses' : 'Top diagnoses';

        return $this->chart('diagnoses', 'bars', $title, $period.' · visits'.($category === null ? ', all categories' : ''), [['label' => 'Visits', 'color' => $color]], $lines,
            empty: array_sum(array_column(array_column($lines, 'values'), 0)) === 0,
            aria: $title.', '.$period.'. '.($parts === [] ? self::NO_VISITS.'.' : implode(', ', $parts).' (visits).'),
            source: $this->source($table, $rows, [1]));
    }

    /**
     * Horizontal bars of the patients of each branch of service (Army, Navy, Air Force, Marines, Others),
     * the lines of the Branch of service table above its AFP personnel and Others totals.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function branches(array $table, string $period): array
    {
        $rows = $this->bodyRows($table, include: ['body']);

        $lines = [];
        $parts = [];

        foreach ($rows as [, $row]) {
            $count = (int) $row['cells'][1];
            $percent = ReportSections::format($row['cells'][2], 'pct');
            $parts[] = $row['cells'][0].' '.number_format($count);
            $lines[] = [
                'label' => (string) $row['cells'][0],
                'values' => [$count],
                'note' => ' · '.$percent,
                'tip' => $row['cells'][0].': '.number_format($count).($count === 1 ? ' patient' : ' patients').' ('.$percent.')',
            ];
        }

        return $this->chart('branches', 'bars', 'Branch of service', $period.' · patients', [['label' => 'Patients', 'color' => ChartPalette::SINGLE]], $lines,
            empty: array_sum(array_column(array_column($lines, 'values'), 0)) === 0,
            aria: "Branch of service, {$period}. ".($parts === [] ? self::NO_VISITS.'.' : implode(', ', $parts).' (patients).'),
            source: $this->source($table, $rows, [1]));
    }

    /**
     * Paired bars: this period against the one before, for patients and for visits.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>
     */
    private function comparison(array $table, string $period): array
    {
        $rows = $this->bodyRows($table, include: ['body']);
        $previous = (string) $table['columns'][2]['label'];
        $series = [
            ['label' => 'This period ('.$period.')', 'color' => ChartPalette::THIS_PERIOD],
            ['label' => $previous, 'color' => ChartPalette::PREVIOUS],
        ];

        $lines = [];
        $parts = [];
        $sum = 0;

        foreach ($rows as [, $row]) {
            [$now, $before] = [(int) $row['cells'][1], (int) $row['cells'][2]];
            $change = ReportSections::format($row['cells'][3], 'delta');
            $note = $change.($row['cells'][4] === null ? '' : ' ('.ReportSections::format($row['cells'][4], 'deltapct').')');
            $sum += $now + $before;
            $parts[] = $row['cells'][0].' '.number_format($now).' this period, '.number_format($before).' in '.$previous.', change '.$note;
            $lines[] = [
                'label' => (string) $row['cells'][0],
                'values' => [$now, $before],
                'note' => $note,
                'tip' => $row['cells'][0].': '.number_format($now).' this period, '.number_format($before).' in '.$previous.' (change '.$note.')',
            ];
        }

        return $this->chart('comparison', 'paired', 'This period vs previous', $period.' compared with '.$previous.' · patients and visits', $series, $lines,
            empty: $sum === 0,
            aria: "This period against the previous one, {$period}. ".implode('; ', $parts).'.',
            source: $this->source($table, $rows, [1, 2]));
    }

    // --- Helpers ---------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $series
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function chart(string $key, string $kind, string $title, string $subtitle, array $series, array $rows, bool $empty, string $aria, array $source): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $subtitle,
            'aria' => $aria,
            'empty' => $empty || $rows === [],
            'message' => self::NO_VISITS,
            'series' => $series,
            'rows' => $rows,
            'source' => $source,
        ];
    }

    /**
     * Where a chart's numbers are in its table: the first and last row (0 is
     * the first row under the header), the label column and the value columns.
     *
     * @param  list<array{0: int, 1: array<string, mixed>}>  $rows
     * @param  list<int>  $cols
     * @return array<string, mixed>
     */
    private function source(array $table, array $rows, array $cols): array
    {
        return [
            'table' => $table['key'],
            'first' => $rows === [] ? 0 : $rows[0][0],
            'last' => $rows === [] ? 0 : $rows[array_key_last($rows)][0],
            'label_col' => 0,
            'cols' => $cols,
        ];
    }

    /**
     * The rows of a table with one of the given styles, each with its place
     * (0 = first row under the header).
     *
     * @param  array<string, mixed>  $table
     * @param  list<string>  $include
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    private function bodyRows(array $table, array $include): array
    {
        $found = [];

        foreach ($table['rows'] as $index => $row) {
            if (in_array($row['style'], $include, true)) {
                $found[] = [$index, $row];
            }
        }

        return $found;
    }

    /** "3" for "03-Sep-2026 (Thu)"; "Sep" (or "Sep 26" when there are many months) for "September 2026". */
    private function shortName(string $label, bool $withYear): string
    {
        if (preg_match('/^(\d{1,2})-/', $label, $m)) {
            return (string) (int) $m[1];
        }

        if (preg_match('/^(\p{L}{3})\p{L}*\s+(\d{4})$/u', $label, $m)) {
            return $withYear ? $m[1].' '.substr($m[2], 2) : $m[1];
        }

        return $label;
    }

    /**
     * "ER 120 visits, OPD 40, Admission 8": the biggest first, the unit once.
     *
     * @param  array<string, int>  $totals
     */
    private function ranked(array $totals, string $unit): string
    {
        $parts = [];

        foreach ($totals as $label => $count) {
            $parts[] = $label.' '.number_format($count).($parts === [] ? ' '.$unit : '');
        }

        return implode(', ', $parts).'.';
    }

    private function visits(int $count): string
    {
        return number_format($count).($count === 1 ? ' visit' : ' visits');
    }

    private function percent(int $count, int $total): string
    {
        return number_format($total > 0 ? $count / $total * 100 : 0, 1).'%';
    }
}
