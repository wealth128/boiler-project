<?php

namespace App\Exports\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The hidden sheet "Chart data": the day by day (or month by month) visits
 * of every category, which the daily census charts draw. The tables on the
 * report sheets only show the totals, so the charts need these figures
 * somewhere. The sheet is very hidden (it does not appear in Excel's
 * Unhide list), has no formatting and is never printed. It holds the
 * report's own daily census, not a new count.
 *
 *   row 1: Day (or Month), then one column for each category
 *   then one row for each day or month
 */
class ChartDataSheet extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithStrictNullComparison, WithStyles, WithTitle
{
    /** @var list<list<string|int>> */
    private array $rows;

    /**
     * @param  array{by: string, rows: list<array{label: string, counts: array<string, int>}>}  $daily
     */
    public function __construct(array $daily)
    {
        $names = array_keys($daily['rows'][0]['counts'] ?? []);
        $this->rows = [[$daily['by'] === 'day' ? 'Day' : 'Month', ...$names]];

        foreach ($daily['rows'] as $row) {
            $this->rows[] = [$row['label'], ...array_map(fn (string $name): int => (int) ($row['counts'][$name] ?? 0), $names)];
        }
    }

    public function title(): string
    {
        return 'Chart data';
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        return $this->rows;
    }

    /** Text stays text, so a label that starts with = can never run as a formula. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        return [];
    }
}
