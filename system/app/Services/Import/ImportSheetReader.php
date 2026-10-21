<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;
use ZipArchive;

/**
 * Reads an import file (.xlsx or .csv) into rows, with maatwebsite/excel.
 *
 * - .xlsx: the "Visits" sheet, or the first sheet when there is none.
 * - .csv: UTF-8, comma-separated. A file saved by Excel's plain "CSV" (Windows-1252,
 *   not valid UTF-8) is converted to UTF-8 first, so ñ and Ñ survive.
 * - The first row that is not blank is the header row. It must hold the
 *   columns of ImportColumns in order (ignoring case, spaces and the star).
 * - Blank rows are skipped. More than 2,000 data rows, or any data far below
 *   them (past row 10,000), is refused, never silently dropped.
 * - An .xlsx whose sheets are huge once unpacked (a sheet formatted down a
 *   million rows) is refused with a friendly message BEFORE it is read, so
 *   the server can't run out of memory.
 *
 * What comes back is the file's own values, not interpreted yet:
 * - text and numbers as trimmed text,
 * - a cell formatted as a date or time as a DateTimeImmutable,
 * - a formula as its own text ("=1+1"). Nothing is ever calculated, so a
 *   formula in the file can never run; it ends up as plain text.
 *
 * Reading does not touch the database.
 */
class ImportSheetReader
{
    /** Stop reading far past the data (a sheet formatted down a million rows). */
    private const LAST_ROW_READ = 10000;

    /** Most the sheets of an .xlsx may weigh once unpacked (a real 2,000-row file is about 1 MB). */
    public const MAX_UNPACKED_BYTES = 20 * 1024 * 1024;

    /**
     * @return array{rows: array<int, array<string, mixed>>}  File row number => values by column key
     *
     * @throws ImportFileException
     */
    public function read(string $relativePath, string $extension): array
    {
        $collector = $extension === 'csv' ? new CsvRows : new SheetRows;

        if ($extension === 'csv') {
            $this->toUtf8($relativePath);
        } else {
            $this->checkUnpackedSize(Storage::disk('local')->path($relativePath));
        }

        // The package skips cell styles by default. Styles are needed to tell a
        // date or time cell from a plain number.
        $readOnly = config('excel.imports.read_only');
        config(['excel.imports.read_only' => false]);

        try {
            if ($extension === 'csv') {
                Excel::import($collector, $relativePath, 'local', ExcelType::CSV);
            } else {
                Excel::import(new VisitsWorkbook('Visits', $collector), $relativePath, 'local', ExcelType::XLSX);

                // No sheet called "Visits": use the first sheet.
                if (! $collector->sheetFound) {
                    Excel::import(new VisitsWorkbook(0, $collector), $relativePath, 'local', ExcelType::XLSX);
                }
            }
        } catch (ImportFileException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new ImportFileException("This file can't be read. Save it as an Excel (.xlsx) or CSV file and upload it again.");
        } finally {
            config(['excel.imports.read_only' => $readOnly]);
        }

        return ['rows' => $this->toRows($collector->lines)];
    }

    /**
     * Excel's plain "CSV" is Windows-1252 on Windows. When the bytes are not
     * valid UTF-8, convert them (in place: the waiting file is ours) so
     * "PEÑA" is still PEÑA. A valid UTF-8 file is left alone.
     */
    private function toUtf8(string $relativePath): void
    {
        $disk = Storage::disk('local');
        $content = (string) $disk->get($relativePath);

        if (mb_check_encoding($content, 'UTF-8')) {
            return;
        }

        $disk->put($relativePath, mb_convert_encoding($content, 'UTF-8', 'Windows-1252'));
    }

    /**
     * Refuse an .xlsx that is far bigger once unpacked than any real list of
     * visits (a zip of a few KB can hold gigabytes of rows that are only
     * formatted). Looks at the zip's table of contents only; reads nothing.
     *
     * @throws ImportFileException
     */
    private function checkUnpackedSize(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return; // not a zip: the reader gives its own "can't be read" message
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $stat === false ? 0 : (int) $stat['size'];
        }

        $zip->close();

        if ($total > self::MAX_UNPACKED_BYTES) {
            throw new ImportFileException('This file is too large to read. Its sheet is probably formatted far below your last row. Copy your rows into a new copy of the downtime template, and upload that file.');
        }
    }

    /**
     * Header check, blank rows skipped, values keyed by column.
     *
     * @param  array<int, list<mixed>>  $lines  File row number => cell values (column A first)
     * @return array<int, array<string, mixed>>
     *
     * @throws ImportFileException
     */
    private function toRows(array $lines): array
    {
        $headerAt = null;

        foreach ($lines as $number => $cells) {
            if (! $this->blank($cells)) {
                $headerAt = $number;

                break;
            }
        }

        if ($headerAt === null) {
            throw new ImportFileException('The file is empty. Use the downtime template: the first row must be the column names.');
        }

        $this->checkHeader($lines[$headerAt]);

        $keys = ImportColumns::keys();
        $rows = [];

        foreach ($lines as $number => $cells) {
            if ($number <= $headerAt || $this->blank($cells)) {
                continue;
            }

            if (count($rows) >= ImportColumns::MAX_ROWS) {
                throw new ImportFileException('This file has more than '.number_format(ImportColumns::MAX_ROWS).' rows. Split it into smaller files.');
            }

            // Anything after the last column is not part of the format and is ignored.
            $rows[$number] = array_combine($keys, array_slice(array_pad($cells, count($keys), null), 0, count($keys)));
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $cells
     *
     * @throws ImportFileException
     */
    private function checkHeader(array $cells): void
    {
        $expected = ImportColumns::keys();

        foreach ($expected as $i => $key) {
            $found = $cells[$i] ?? null;
            $found = is_string($found) ? $found : '';

            if (ImportColumns::headerKey($found) !== ImportColumns::headerKey(ImportColumns::label($key))) {
                $shown = trim($found) === '' ? 'empty' : '“'.mb_substr(trim($found), 0, 40).'”';

                throw new ImportFileException(
                    'The first row must be the column names of the downtime template, in the same order. '
                    .'Column '.($i + 1).' should be “'.ImportColumns::label($key).'” but it is '.$shown.'. '
                    .'Download a new template (Records > Import) and copy your rows into it.'
                );
            }
        }
    }

    /** @param  list<mixed>  $cells */
    private function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * One cell's value (see the class comment).
     */
    public static function cellValue(Cell $cell): mixed
    {
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return trim((string) $cell->getValue());
        }

        $value = $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            if (Date::isDateTime($cell)) {
                return \DateTimeImmutable::createFromMutable(Date::excelToDateTimeObject($value));
            }

            return floor((float) $value) === (float) $value && abs($value) < 1.0E+15 ? (string) (int) $value : (string) $value;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Collects the rows of one sheet, column A first (up to column R: one
     * more than the format has, so the "extra column" is seen and ignored).
     */
    public static function collect(Row $row, SheetRows $into): void
    {
        $index = $row->getIndex();

        if ($index > self::LAST_ROW_READ) {
            // Blank rows this far down are only formatting. Any value here is data that
            // would be dropped, so the file is refused instead.
            foreach ($row->getDelegate()->getCellIterator('A', ImportColumns::letter('written_by'), true) as $cell) {
                if (self::cellValue($cell) !== null) {
                    throw new ImportFileException('This file has more than '.number_format(ImportColumns::MAX_ROWS).' rows. Split it into smaller files.');
                }
            }

            return;
        }

        $cells = array_fill(0, count(ImportColumns::LIST), null);

        foreach ($row->getDelegate()->getCellIterator('A', ImportColumns::letter('written_by'), true) as $letter => $cell) {
            $cells[ord((string) $letter) - ord('A')] = self::cellValue($cell);
        }

        $into->lines[$index] = $cells;
    }
}
