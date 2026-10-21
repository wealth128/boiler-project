<?php

namespace App\Exports\Import;

use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The "Lists" sheet: the values the dropdowns on "Visits" offer. Columns:
 * A Sex, B Category, C Branch, D Diagnosis, E Rank / Type (all), then F empty
 * and G onward one column of ranks for each branch, in seniority order.
 */
class ListsSheet extends TemplateSheet implements WithColumnWidths, WithStyles
{
    public function __construct(private readonly TemplateLists $lists)
    {
        parent::__construct('Lists');
    }

    /** @return list<list<string|null>> */
    public function array(): array
    {
        $columns = [
            ['Sex', ...$this->lists->sexes],
            ['Category', ...$this->lists->categories],
            ['Branch', ...$this->lists->branches],
            ['Diagnosis', ...$this->lists->diagnoses],
            ['Rank / Type (all)', ...$this->lists->ranks],
            [null],
        ];

        foreach ($this->lists->ranksByBranch as $branch => $ranks) {
            $columns[] = ["Ranks: {$branch}", ...$ranks];
        }

        $height = max(array_map('count', $columns));
        $rows = [];

        for ($i = 0; $i < $height; $i++) {
            $rows[] = array_map(fn (array $column): ?string => $column[$i] ?? null, $columns);
        }

        return $rows;
    }

    /** @return array<string, int> */
    public function columnWidths(): array
    {
        $widths = ['A' => 10, 'B' => 12, 'C' => 14, 'D' => 34, 'E' => 20, 'F' => 3];

        for ($i = 0; $i < count($this->lists->ranksByBranch); $i++) {
            $widths[chr(ord('G') + $i)] = 18;
        }

        return $widths;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
