<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * A CSV file: UTF-8, comma-separated.
 */
class CsvRows extends SheetRows implements WithCustomCsvSettings
{
    /** @return array<string, mixed> */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'input_encoding' => 'UTF-8',
        ];
    }
}
