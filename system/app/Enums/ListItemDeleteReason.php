<?php

namespace App\Enums;

use App\Enums\Concerns\ListsDeleteReasons;

/**
 * Why a dropdown list item (diagnosis, rank, age bracket) goes to Trash
 * (Dropdown Lists).
 */
enum ListItemDeleteReason: string implements DeleteReason
{
    use ListsDeleteReasons;

    case DuplicateItem = 'Duplicate item';
    case Misspelled = 'Misspelled, replaced with a corrected one';
    case NoLongerUsed = 'No longer used';
    case Other = 'Other (specify)';
}
