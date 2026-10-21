<?php

namespace App\Exports\Import;

use App\Services\Import\ImportColumns;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The header row, and under it either empty rows to fill ("Visits") or a few
 * sample rows ("Example"). Both have the same look: dropdowns for Sex,
 * Branch, Rank / Type, Diagnosis and Category (from the "Lists" sheet), dates
 * shown as YYYY-MM-DD, and every other column as text so nothing is changed
 * on the way in (a Patient ID or an M.I. is never turned into a number or a date).
 */
class VisitsSheet extends TemplateSheet implements WithColumnWidths, WithEvents
{
    /** Width of each column, in the order of ImportColumns. */
    private const WIDTHS = [14, 11, 13, 18, 18, 6, 9, 13, 7, 12, 18, 20, 30, 26, 12, 30, 18];

    /**
     * @param  list<list<string|float|null>>  $rows  Rows under the header (empty for "Visits")
     * @param  int  $lastRow  The last row that has the formats and dropdowns
     */
    public function __construct(
        string $title,
        private readonly TemplateLists $lists,
        private readonly array $rows,
        private readonly int $lastRow,
    ) {
        parent::__construct($title);
    }

    /** @return list<list<string|float|null>> */
    public function array(): array
    {
        return [ImportColumns::headers(), ...$this->rows];
    }

    /** @return array<string, int> */
    public function columnWidths(): array
    {
        $widths = [];

        foreach (self::WIDTHS as $i => $width) {
            $widths[chr(ord('A') + $i)] = $width;
        }

        return $widths;
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last = ImportColumns::letter('written_by');

                $header = $sheet->getStyle("A1:{$last}1");
                $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2E5A8A');
                $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

                $sheet->freezePane('A2');

                // Formats are set for the WHOLE column, not for each of the 2,000 cells: the same
                // result for the person typing (every row below the header gets it), and the file
                // is made in a fraction of the time. Rows that already hold sample values (the
                // "Example" sheet) get the same formats cell by cell, because a cell that
                // exists keeps its own format.
                $filled = count($this->rows) + 1;

                foreach ($this->columnFormats() as $letter => $code) {
                    $sheet->getStyle("{$letter}:{$letter}")->getNumberFormat()->setFormatCode($code);

                    if ($filled > 1) {
                        $sheet->getStyle("{$letter}2:{$letter}{$filled}")->getNumberFormat()->setFormatCode($code);
                    }
                }
                $this->dropdown($sheet, 'sex', 'A', count($this->lists->sexes));
                $this->dropdown($sheet, 'category', 'B', count($this->lists->categories));
                $this->dropdown($sheet, 'branch', 'C', count($this->lists->branches));
                $this->dropdown($sheet, 'diagnosis', 'D', count($this->lists->diagnoses));
                $this->dropdown($sheet, 'rank', 'E', count($this->lists->ranks));
            },
        ];
    }

    /**
     * The number format of every column: text, except the two dates and the age.
     *
     * @return array<string, string> Column letter => format code
     */
    private function columnFormats(): array
    {
        $formats = [];

        foreach (ImportColumns::keys() as $key) {
            $formats[ImportColumns::letter($key)] = match ($key) {
                'visit_date', 'birthdate' => 'yyyy-mm-dd',
                'age' => '0',
                default => NumberFormat::FORMAT_TEXT,
            };
        }

        return $formats;
    }

    /**
     * A dropdown on a column of this sheet, listing a column of the "Lists" sheet.
     */
    private function dropdown(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $key, string $listColumn, int $count): void
    {
        $letter = ImportColumns::letter($key);
        $to = max(2, $count + 1);

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(false); // false = show the arrow
        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle('Not on the list');
        $validation->setError('Choose a value from the list. If it is not there, choose "Other (specify)" where it exists and write the name in the next column.');
        $validation->setFormula1("=Lists!\${$listColumn}\$2:\${$listColumn}\${$to}");

        $sheet->setDataValidation("{$letter}2:{$letter}{$this->lastRow}", $validation);
    }
}
