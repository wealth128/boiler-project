<?php

namespace App\Enums;

enum BackupStatus: string
{
    case Success = 'success';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
