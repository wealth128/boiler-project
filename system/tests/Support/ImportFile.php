<?php

namespace Tests\Support;

use App\Services\Import\ImportColumns;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Builds downtime import files for the tests: .xlsx (with real Excel date
 * and time cells, or a real formula cell) and .csv.
 *
 * A row is a list of 17 values in the order of ImportColumns, or an array
 * keyed by column key (visit_date, visit_time, ...). Missing columns stay empty.
 */
final class ImportFile
{
    /** A real Excel date cell (shown as YYYY-MM-DD). */
    public static function excelDate(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' 00:00:00');
    }

    /** A real Excel time cell (shown as HH:MM). */
    public static function excelTime(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('1899-12-30 '.$time.':00');
    }

    /** A cell that really holds a formula, not text that looks like one. */
    public static function formula(string $formula): object
    {
        return new class($formula)
        {
            public function __construct(public readonly string $formula) {}
        };
    }

    /**
     * One visit as a row keyed by column. Everything not given is empty.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function row(array $values = []): array
    {
        return [
            'visit_date' => '2026-10-05',
            'visit_time' => '08:15',
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan',
            'sex' => 'Male',
            'birthdate' => '1990-05-01',
            'branch' => 'Army',
            'rank' => 'CPL',
            'diagnosis' => 'Hypertension',
            'category' => 'ER',
            'written_by' => 'Nurse A. Ramos',
            ...$values,
        ];
    }

    /**
     * @param  list<array<int|string, mixed>>  $rows
     * @param  array{headers?: list<string>|null, sheet?: string, firstSheet?: string|null, name?: string}  $options
     */
    public static function xlsx(array $rows, array $options = []): UploadedFile
    {
        $book = new Spreadsheet;

        if (isset($options['firstSheet'])) {
            $book->getActiveSheet()->setTitle($options['firstSheet'])->setCellValue('A1', 'Read me');
            $sheet = $book->createSheet();
        } else {
            $sheet = $book->getActiveSheet();
        }

        $sheet->setTitle($options['sheet'] ?? 'Visits');

        self::fill($sheet, $rows, $options);

        $path = self::tempPath('.xlsx');
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, $options['name'] ?? 'downtime.xlsx', null, null, true);
    }

    /**
     * @param  list<array<int|string, mixed>>  $rows
     * @param  array{headers?: list<string>|null, name?: string, bom?: bool}  $options
     */
    public static function csv(array $rows, array $options = []): UploadedFile
    {
        $path = self::tempPath('.csv');
        $handle = fopen($path, 'w');

        if ($options['bom'] ?? false) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        $headers = array_key_exists('headers', $options) ? $options['headers'] : ImportColumns::headers();

        if ($headers !== null) {
            fputcsv($handle, $headers, ',', '"', '');
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($value) => $value instanceof DateTimeImmutable ? $value->format('Y-m-d') : (string) $value, self::cells($row)), ',', '"', '');
        }

        fclose($handle);

        return new UploadedFile($path, $options['name'] ?? 'downtime.csv', null, null, true);
    }

    /** A file with this exact content (for a file that is not a real spreadsheet). */
    public static function raw(string $content, string $name): UploadedFile
    {
        $path = self::tempPath('.'.pathinfo($name, PATHINFO_EXTENSION));
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param  list<array<int|string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     */
    private static function fill(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows, array $options): void
    {
        $headers = array_key_exists('headers', $options) ? $options['headers'] : ImportColumns::headers();
        $line = 1;

        if ($headers !== null) {
            foreach (array_values($headers) as $i => $header) {
                $sheet->setCellValueExplicit([$i + 1, $line], $header, DataType::TYPE_STRING);
            }

            $line++;
        }

        foreach ($rows as $row) {
            foreach (self::cells($row) as $i => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $coordinate = [$i + 1, $line];

                if ($value instanceof DateTimeImmutable) {
                    $isTime = $value->format('Y-m-d') === '1899-12-30';
                    $sheet->setCellValue($coordinate, Date::PHPToExcel($value));
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($isTime ? 'hh:mm' : 'yyyy-mm-dd');
                } elseif (is_object($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value->formula, DataType::TYPE_FORMULA);
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
                }
            }

            $line++;
        }
    }

    /**
     * A row as 17 cells in column order, from a list or from values keyed by column.
     *
     * @param  array<int|string, mixed>  $row
     * @return list<mixed>
     */
    private static function cells(array $row): array
    {
        if (array_is_list($row)) {
            return $row;
        }

        return array_map(fn (string $key) => $row[$key] ?? null, ImportColumns::keys());
    }

    private static function tempPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        rename($path, $path.$extension);

        return $path.$extension;
    }
}
