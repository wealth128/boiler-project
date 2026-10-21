<?php

namespace App\Services\Import;

/**
 * The columns of the downtime import file (Step 6j), in their exact order,
 * and the limits. The template download, the file reader and the preview all
 * read this one list.
 */
final class ImportColumns
{
    /** Most data rows one file may have (blank rows are skipped, not counted). */
    public const MAX_ROWS = 2000;

    /** Biggest file, in kilobytes (Laravel's "max" file rule counts kilobytes). */
    public const MAX_KILOBYTES = 2048;

    /** The earliest visit date the system accepts (planning/mvp-scope.md). */
    public const EARLIEST_DATE = '2026-01-01';

    /**
     * Key => [header text without the star, required?].
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const LIST = [
        'visit_date' => ['Visit Date', true],
        'visit_time' => ['Visit Time', true],
        'patient_no' => ['Patient ID', false],
        'last_name' => ['Last Name', true],
        'first_name' => ['First Name', true],
        'middle_initial' => ['M.I.', false],
        'sex' => ['Sex', true],
        'birthdate' => ['Birthdate', true],
        'age' => ['Age', false],
        'branch' => ['Branch', true],
        'rank' => ['Rank / Type', true],
        'rank_other' => ['Rank Other (specify)', false],
        'diagnosis' => ['Diagnosis', true],
        'diagnosis_other' => ['Diagnosis Other (specify)', false],
        'category' => ['Category', true],
        'remarks' => ['Remarks', false],
        'written_by' => ['Written by', true],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LIST);
    }

    public static function label(string $key): string
    {
        return self::LIST[$key][0];
    }

    public static function isRequired(string $key): bool
    {
        return self::LIST[$key][1];
    }

    /**
     * The header row of the template: "Visit Date *", "Visit Time *", ...
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return array_values(array_map(
            fn (array $column): string => $column[0].($column[1] ? ' *' : ''),
            self::LIST,
        ));
    }

    /**
     * A header cell compared ignoring case, spaces and the star:
     * "Visit Date *", "visit date" and "VISITDATE*" are the same.
     */
    public static function headerKey(string $header): string
    {
        return (string) preg_replace('/[\s*]+/u', '', mb_strtolower($header));
    }

    /** The Excel column letter of a column key: visit_date is A, written_by is Q. */
    public static function letter(string $key): string
    {
        return chr(ord('A') + array_search($key, self::keys(), true));
    }
}
