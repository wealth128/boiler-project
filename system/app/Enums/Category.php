<?php

namespace App\Enums;

/**
 * Visit category. Reports are produced per category.
 */
enum Category: string
{
    case OPD = 'OPD';
    case ER = 'ER';
    case Admission = 'Admission';

    public function label(): string
    {
        return $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
