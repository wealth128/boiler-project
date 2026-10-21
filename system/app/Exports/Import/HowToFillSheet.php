<?php

namespace App\Exports\Import;

use App\Services\Import\ImportColumns;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The "How to fill" sheet: plain instructions for whoever writes the rows
 * while the system is down.
 */
class HowToFillSheet extends TemplateSheet implements WithColumnWidths, WithStyles
{
    public function __construct(private readonly CarbonInterface $downloadedAt)
    {
        parent::__construct('How to fill');
    }

    /** @return list<list<string>> */
    public function array(): array
    {
        $max = number_format(ImportColumns::MAX_ROWS);

        return [
            ['PATIENT VISITS – DOWNTIME SHEET'],
            ['Use this only when the system is down. When it is back, the Admin imports this file (Records > Import).'],
            [''],
            ['1. Fill the "Visits" sheet: one row = one visit. See the "Example" sheet. Columns with * are required. Up to '.$max.' rows in one file.'],
            ['2. Pick Sex, Branch, Rank / Type, Diagnosis and Category from the dropdowns. The Lists sheet shows which ranks belong to each branch.'],
            ['3. Dates as YYYY-MM-DD (2026-11-03). Time as 24-hour HH:MM (14:05). Use the time the patient was actually seen.'],
            ['4. Returning patient: write their Patient ID (e.g. P-000012) if you know it. If not, leave it blank: the import matches by name + birthdate and asks the Admin to confirm.'],
            ['5. Age: leave blank. It is computed from the birthdate on the visit date.'],
            ['6. "Other (specify)": when you pick it for Rank / Type or Diagnosis, write the real name in the next column. Do not write anything there for any other choice.'],
            ['7. Written by: your name (the staff who wrote the row).'],
            ['8. Do not change the column names or their order. Do not add columns. Do not merge cells. The Admin imports the "Visits" sheet only; "Example" and "Lists" are for you.'],
            ['9. Google Sheets: File > Import this .xlsx, fill it, then File > Download > Microsoft Excel (.xlsx) or CSV (Visits sheet only). In Excel, save as CSV UTF-8 if you use CSV; .xlsx is best.'],
            ['10. Keep the file private: it contains patient data. Delete extra copies after the import.'],
            [''],
            ['Lists in this file were copied from the system on '.$this->downloadedAt->format('F j, Y').'. If a name is missing, use "Other (specify)" and write it.'],
        ];
    }

    /** @return array<string, int> */
    public function columnWidths(): array
    {
        return ['A' => 130];
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:A'.$sheet->getHighestRow())->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        return [1 => ['font' => ['bold' => true, 'size' => 14]]];
    }
}
