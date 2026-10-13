<?php

namespace App\Enums;

use App\Enums\Concerns\ListsDeleteReasons;

/**
 * Why a visit goes to Trash (Patient Records, Visits tab).
 */
enum VisitDeleteReason: string implements DeleteReason
{
    use ListsDeleteReasons;

    case DuplicateEntry = 'Duplicate entry';
    case WrongPatient = 'Wrong patient selected';
    case EncodedByMistake = 'Encoded by mistake';
    case TestEntry = 'Test / training entry';
    case Other = 'Other (specify)';
}
