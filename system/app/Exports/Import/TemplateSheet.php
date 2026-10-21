<?php

namespace App\Exports\Import;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * One worksheet of the downtime template. Every text value is stored as plain
 * text, so a list name that starts with =, +, - or @ can never run as a formula
 * (planning/security.md section 2, rule 7).
 */
abstract class TemplateSheet extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithTitle
{
    public function __construct(private readonly string $sheetTitle) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
