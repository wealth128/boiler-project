<?php

namespace App\Enums;

use App\Enums\Concerns\ListsDeleteReasons;

/**
 * Why a whole month report goes to Trash ("Delete month report").
 */
enum MonthReportDeleteReason: string implements DeleteReason
{
    use ListsDeleteReasons;

    case WrongMonth = 'Encoded in the wrong month';
    case TestData = 'Test / training data';
    case Other = 'Other (specify)';
}
