<?php

namespace App\Enums;

use App\Enums\Concerns\ListsDeleteReasons;

/**
 * Why a patient goes to Trash (Patient Records, Patients tab).
 */
enum PatientDeleteReason: string implements DeleteReason
{
    use ListsDeleteReasons;

    case DuplicatePatient = 'Duplicate patient record';
    case EncodedByMistake = 'Encoded by mistake';
    case TestEntry = 'Test / training entry';
    case Other = 'Other (specify)';
}
