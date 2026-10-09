<?php

namespace App\Enums;

enum Sex: string
{
    case Male = 'Male';
    case Female = 'Female';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
