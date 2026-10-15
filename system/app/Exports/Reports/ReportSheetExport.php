<?php

namespace App\Exports\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet of the report workbook: rows added top to bottom, some of
 * them bold. A count of 0 is written as 0, not left empty (WithStrictNullComparison).
 *
 * Every text value is stored as plain text. A name or diagnosis that starts
 * with =, +, - or @ can therefore never run as a formula (planning/security.md
 * section 2, rule 7). Counts are integers, so they stay numbers.
 */
abstract class ReportSheetExport extends DefaultValueBinder implements FromArray, WithColumnWidths, WithCustomValueBinder, WithStrictNullComparison, WithStyles, WithTitle
{
    /** @var list<list<string|int>> */
    private array $rows = [];

    /** @var list<int> Row numbers (1-based) that are bold. */
    private array $boldRows = [];

    public function __construct(private readonly string $sheetTitle) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        return $this->rows;
    }

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
        $styles = [];

        foreach ($this->boldRows as $row) {
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    /**
     * Column A fits its longest label (a table row, not a heading that may
     * spill over the empty cells beside it); the number columns are narrow.
     *
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        $longest = 0;

        foreach ($this->rows as $row) {
            if (count($row) > 1) {
                $longest = max($longest, mb_strlen((string) $row[0]));
            }
        }

        return ['A' => min(70, max(24, $longest + 4)), 'B' => 14, 'C' => 14];
    }

    /** @param  list<string|int>  $cells */
    protected function add(array $cells = [], bool $bold = false): void
    {
        $this->rows[] = $cells;

        if ($bold) {
            $this->boldRows[] = count($this->rows);
        }
    }
}
