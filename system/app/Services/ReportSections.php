<?php

namespace App\Services;

/**
 * The tables of a report page, worked out from the report array
 * (ReportBuilder, or its saved copy) and nothing else. The Reports screen,
 * Print, the PDF and the Excel file all draw these same tables, so they show
 * the same sections with the same numbers in the same order. Nothing is
 * counted here; only percentages and changes are worked out from the counts.
 *
 * A table is:
 *   key, title, note (string or null),
 *   columns [{label, type}]   type: text | int | pct | delta | deltapct
 *   rows    [{cells, style}]  style: body | child (indented under a group) | group | total
 * A cell is text, a whole number, a percentage (one decimal, 12.5 means 12.5%),
 * or null (no figure, drawn as a dash).
 *
 * The "totals" table is also shown as big numbers on screen, in the PDF and
 * in Print, so those leave it out of the list of tables.
 */
class ReportSections
{
    /** The key of the table that screen, Print and PDF show as big numbers instead. */
    public const TOTALS = 'totals';

    /**
     * The pages of a report: "summary", then each category in the report.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, list<array<string, mixed>>>
     */
    public function pages(array $report): array
    {
        $pages = ['summary' => $this->summary($report)];

        foreach ($report['categories'] as $name => $data) {
            $pages[$name] = $this->category($name, $data, $report['daily']);
        }

        return $pages;
    }

    /**
     * The Summary page: every category together.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    public function summary(array $report): array
    {
        $summary = $report['summary'];
        $patients = $report['totals']['patients'];
        $visits = $report['totals']['visits'];
        $categories = $report['categories'];

        $byCategory = array_map(
            fn (string $name, array $data): array => $this->row([$name, $data['patients'], $data['visits']]),
            array_keys($categories),
            $categories,
        );

        $sections = [
            $this->table(self::TOTALS, 'Patients and visits', [['Category', 'text'], ['Patients', 'int'], ['Visits', 'int']], [
                ...$byCategory,
                $this->row(['Total (all categories)', $patients, $visits], 'total'),
            ], 'The total counts each patient once across all categories, so it can be less than the categories added together.'),
        ];

        $sexRows = [];

        foreach ($categories as $name => $data) {
            $sexRows[] = $this->row([$name, ...$this->maleFemale($data['sex']), $data['patients']]);
        }

        $sexRows[] = $this->row(['All categories', ...$this->maleFemale($summary['sex']), $patients], 'total');
        $sections[] = $this->table('sex', 'Sex', [['Category', 'text'], ['Male', 'int'], ['Female', 'int'], ['Total patients', 'int']], $sexRows);

        $sections[] = $this->ageSex($summary['age_sex']);

        $sections[] = $this->table(
            'diagnoses',
            'Top '.ReportBuilder::TOP_DIAGNOSES.' diagnoses (per visit, all categories)',
            [['Diagnosis', 'text'], ['Visits', 'int'], ['% of visits', 'pct']],
            array_map(fn (array $line): array => $this->row([$line['label'], $line['count'], $this->percent($line['count'], $visits)]), $summary['diagnoses']),
            'Full list on each category page.',
        );

        $sections[] = $this->branches($summary['branches'], $summary['afp'], $patients);
        $sections[] = $this->ranks($summary['branches']);
        $sections[] = $this->returning($summary['returning'], 'Patients seen before this period are returning; the others had their first visit in it.');
        $sections[] = $this->daily($report['daily'], array_keys($categories), null);

        if ($report['previous'] !== null) {
            $sections[] = $this->comparison($report['previous'], $patients, $visits);
        }

        return $sections;
    }

    /**
     * One category's page.
     *
     * @param  array<string, mixed>  $data  one category of the report
     * @param  array<string, mixed>  $daily  the daily census of the report
     * @return list<array<string, mixed>>
     */
    public function category(string $name, array $data, array $daily): array
    {
        $sexRows = array_map(fn (array $line): array => $this->row([$line['label'], $line['count']]), $data['sex']);
        $sexRows[] = $this->row(['Total', $data['patients']], 'total');

        $diagnosisRows = array_map(
            fn (array $line): array => $this->row([$line['label'], $line['count'], $this->percent($line['count'], $data['visits'])]),
            $data['diagnoses'],
        );
        $diagnosisRows[] = $this->row(['Total', $data['visits'], $this->percent($data['visits'], $data['visits'])], 'total');

        return [
            $this->table(self::TOTALS, 'Patients and Visits', [[$name, 'text'], ['Count', 'int']], [
                $this->row(['Patients', $data['patients']]),
                $this->row(['Visits', $data['visits']]),
            ]),
            $this->table('sex', 'Sex', [['Sex', 'text'], ['Patients', 'int']], $sexRows),
            $this->ageSex($data['age_sex']),
            $this->table('diagnoses', 'Diagnosis (per visit)', [['Diagnosis', 'text'], ['Visits', 'int'], ['% of visits', 'pct']], $diagnosisRows),
            $this->branchRank($data),
            $this->returning($data['returning'], "Patients with no earlier {$name} visit are new; the others were seen in {$name} before this period."),
            $this->daily($daily, [$name], $name),
        ];
    }

    // --- Tables ------------------------------------------------------------------------

    /**
     * @param  list<array{label: string, male: int, female: int}>  $lines
     * @return array<string, mixed>
     */
    private function ageSex(array $lines): array
    {
        $rows = array_map(fn (array $line): array => $this->row([$line['label'], $line['male'], $line['female'], $line['male'] + $line['female']]), $lines);

        $male = array_sum(array_column($lines, 'male'));
        $female = array_sum(array_column($lines, 'female'));
        $rows[] = $this->row(['Total', $male, $female, $male + $female], 'total');

        return $this->table('age_sex', 'Age range by sex', [['Age range', 'text'], ['Male', 'int'], ['Female', 'int'], ['Total', 'int']], $rows);
    }

    /**
     * The Summary's Branch of service table: patients of each branch, then
     * the AFP personnel and Others totals.
     *
     * @param  list<array{name: string, total: int, ranks: list<array{label: string, count: int}>}>  $branches
     * @param  array{afp: int, others: int}  $afp
     * @return array<string, mixed>
     */
    private function branches(array $branches, array $afp, int $patients): array
    {
        $rows = array_map(fn (array $branch): array => $this->row([$branch['name'], $branch['total'], $this->percent($branch['total'], $patients)]), $branches);

        $rows[] = $this->row(['AFP personnel', $afp['afp'], $this->percent($afp['afp'], $patients)], 'total');
        $rows[] = $this->row(['Others (not AFP personnel)', $afp['others'], $this->percent($afp['others'], $patients)], 'total');

        return $this->table('branches', 'Branch of service', [['Branch of service', 'text'], ['Patients', 'int'], ['% of patients', 'pct']], $rows);
    }

    /**
     * Patients per rank, grouped by branch in seniority order, each branch
     * with its subtotal.
     *
     * @param  list<array{name: string, total: int, ranks: list<array{label: string, count: int}>}>  $branches
     * @return array<string, mixed>
     */
    private function ranks(array $branches): array
    {
        return $this->table('ranks', 'Ranks by branch', [['Branch / Rank', 'text'], ['Patients', 'int']], $this->branchRows($branches));
    }

    /**
     * A category's Branch × Rank table, with the AFP personnel and Others totals under it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function branchRank(array $data): array
    {
        $rows = $this->branchRows($data['branches']);
        $rows[] = $this->row(['AFP personnel', $data['afp']['afp']], 'total');
        $rows[] = $this->row(['Others (not AFP personnel)', $data['afp']['others']], 'total');

        return $this->table('branch_rank', 'Branch of service and rank', [['Branch / Rank', 'text'], ['Patients', 'int']], $rows);
    }

    /**
     * @param  list<array{name: string, total: int, ranks: list<array{label: string, count: int}>}>  $branches
     * @return list<array{cells: list<mixed>, style: string}>
     */
    private function branchRows(array $branches): array
    {
        $rows = [];

        foreach ($branches as $branch) {
            $rows[] = $this->row([$branch['name'], $branch['total']], 'group');

            foreach ($branch['ranks'] as $rank) {
                $rows[] = $this->row([$rank['label'], $rank['count']], 'child');
            }
        }

        return $rows;
    }

    /**
     * @param  array{new: int, returning: int}  $split
     * @return array<string, mixed>
     */
    private function returning(array $split, string $note): array
    {
        $patients = $split['new'] + $split['returning'];

        return $this->table('returning', 'New and returning patients', [['Patients', 'text'], ['Count', 'int'], ['% of patients', 'pct']], [
            $this->row(['New (first visit ever in this period)', $split['new'], $this->percent($split['new'], $patients)]),
            $this->row(['Returning (seen before)', $split['returning'], $this->percent($split['returning'], $patients)]),
            $this->row(['Total patients', $patients, $this->percent($patients, $patients)], 'total'),
        ], $note);
    }

    /**
     * The daily census (a monthly one when the period is long): visits in the
     * given categories, one row for each day or month, a total row and the
     * busiest day or month.
     *
     * @param  array{by: string, rows: list<array{label: string, counts: array<string, int>}>}  $daily
     * @param  list<string>  $names  the categories to show, one column each
     * @return array<string, mixed>
     */
    private function daily(array $daily, array $names, ?string $only): array
    {
        $byDay = $daily['by'] === 'day';
        $columns = [[$byDay ? 'Day' : 'Month', 'text']];

        foreach ($names as $name) {
            $columns[] = [$only === null ? $name : 'Visits', 'int'];
        }

        if ($only === null) {
            $columns[] = ['Total visits', 'int'];
        }

        $rows = [];
        $sums = array_fill_keys($names, 0);
        $busiest = null;

        foreach ($daily['rows'] as $line) {
            $counts = array_map(fn (string $name): int => $line['counts'][$name] ?? 0, $names);
            $total = array_sum($counts);

            foreach ($names as $index => $name) {
                $sums[$name] += $counts[$index];
            }

            if ($total > 0 && ($busiest === null || $total > $busiest['total'])) {
                $busiest = ['label' => $line['label'], 'total' => $total];
            }

            $rows[] = $this->row([$line['label'], ...$counts, ...($only === null ? [$total] : [])]);
        }

        $sumCells = array_values($sums);
        $rows[] = $this->row(['Total', ...$sumCells, ...($only === null ? [array_sum($sumCells)] : [])], 'total');

        $word = $byDay ? 'day' : 'month';
        $note = $busiest === null
            ? 'No visits in this period.'
            : sprintf('Busiest %s: %s, %s %s.', $word, $busiest['label'], number_format($busiest['total']), $busiest['total'] === 1 ? 'visit' : 'visits');

        return $this->table('daily', $byDay ? 'Daily census (visits)' : 'Monthly census (visits)', $columns, $rows, $note);
    }

    /**
     * @param  array{label: string, patients: int, visits: int}  $previous
     * @return array<string, mixed>
     */
    private function comparison(array $previous, int $patients, int $visits): array
    {
        $line = fn (string $label, int $now, int $before): array => $this->row([
            $label,
            $now,
            $before,
            $now - $before,
            $before > 0 ? round(($now - $before) / $before * 100, 1) : null,
        ]);

        return $this->table('comparison', 'Compared with the previous period', [
            ['Measure', 'text'], ['This period', 'int'], [$previous['label'], 'int'], ['Change', 'delta'], ['Change %', 'deltapct'],
        ], [
            $line('Patients', $patients, $previous['patients']),
            $line('Visits', $visits, $previous['visits']),
        ], 'The previous period is the one of the same length just before this one.');
    }

    // --- Helpers -----------------------------------------------------------------------

    /**
     * A cell as text for the PDF: thousands separators, one decimal and a
     * percent sign for percentages, a plus or minus sign for changes, a dash
     * for a missing figure. (The screen and Excel format the same way.)
     */
    public static function format(mixed $cell, string $type): string
    {
        if ($cell === null) {
            return '–';
        }

        return match ($type) {
            'int' => number_format($cell),
            'pct' => number_format($cell, 1).'%',
            'delta' => ($cell > 0 ? '+' : '').number_format($cell),
            'deltapct' => ($cell > 0 ? '+' : '').number_format($cell, 1).'%',
            default => (string) $cell,
        };
    }

    /**
     * @param  list<array{label: string, count: int}>  $lines  Male and Female lines
     * @return list<int>
     */
    private function maleFemale(array $lines): array
    {
        $counts = array_column($lines, 'count', 'label');

        return [$counts['Male'] ?? 0, $counts['Female'] ?? 0];
    }

    private function percent(int $count, int $total): float
    {
        return $total > 0 ? round($count / $total * 100, 1) : 0.0;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $columns  label and type of each column
     * @param  list<array{cells: list<mixed>, style: string}>  $rows
     * @return array<string, mixed>
     */
    private function table(string $key, string $title, array $columns, array $rows, ?string $note = null): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'note' => $note,
            'columns' => array_map(fn (array $column): array => ['label' => $column[0], 'type' => $column[1]], $columns),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<mixed>  $cells
     * @return array{cells: list<mixed>, style: string}
     */
    private function row(array $cells, string $style = 'body'): array
    {
        return ['cells' => $cells, 'style' => $style];
    }
}
