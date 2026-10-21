<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Row;

/**
 * The rows of one sheet, as the reader collects them.
 */
class SheetRows implements Import, OnEachRow
{
    /** @var array<int, list<mixed>> */
    public array $lines = [];

    public bool $sheetFound = false;

    public function onRow(Row $row): void
    {
        $this->sheetFound = true;
        ImportSheetReader::collect($row, $this);
    }
}
