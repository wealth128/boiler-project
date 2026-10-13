<?php

namespace App\Enums;

use App\Enums\Concerns\ListsDeleteReasons;

/**
 * Why a user account goes to Trash (User Accounts).
 */
enum UserDeleteReason: string implements DeleteReason
{
    use ListsDeleteReasons;

    case Separated = 'Resigned / separated';
    case TransferredOut = 'Transferred out';
    case CreatedByMistake = 'Created by mistake';
    case Other = 'Other (specify)';
}
