<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * An .xlsx workbook: only the wanted sheet is read, a sheet name or 0 for
 * the first one. A workbook without that sheet is not an error here.
 */
class VisitsWorkbook implements Import, SkipsUnknownSheets, WithMultipleSheets
{
    public function __construct(private readonly string|int $sheet, private readonly SheetRows $rows) {}

    /** @return array<string|int, object> */
    public function sheets(): array
    {
        return [$this->sheet => $this->rows];
    }

    public function onUnknownSheet(string|int $sheetName): void
    {
        // Not found: the reader tries the first sheet next.
    }
}
