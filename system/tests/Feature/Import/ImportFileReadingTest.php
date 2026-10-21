<?php

use App\Services\Import\ImportColumns;
use App\Services\Import\ImportFileException;
use App\Services\Import\ImportFileStore;
use App\Services\Import\ImportSheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFile;

/*
 * Reading the file (Step 6j): .xlsx and .csv, the "Visits" sheet or the
 * first one, the header row, blank rows, dates and times as text or as
 * real Excel cells, formulas kept as text, and the file limits.
 * The reader does not touch the database.
 */

beforeEach(function () {
    Storage::fake('local');
});

/** Put a file where the reader looks and read it. */
function readImportFile(UploadedFile $file): array
{
    $extension = strtolower($file->getClientOriginalExtension());
    Storage::disk('local')->putFileAs('imports', $file, "t.{$extension}");

    return (new ImportSheetReader)->read("imports/t.{$extension}", $extension)['rows'];
}

it('reads an .xlsx file from the Visits sheet', function () {
    $rows = readImportFile(ImportFile::xlsx([ImportFile::row(), ImportFile::row(['last_name' => 'Santos'])]));

    expect(array_keys($rows))->toBe([2, 3])
        ->and($rows[2]['last_name'])->toBe('Dela Cruz')
        ->and($rows[3]['last_name'])->toBe('Santos')
        ->and($rows[2]['written_by'])->toBe('Nurse A. Ramos');
});

it('uses the Visits sheet even when it is not the first sheet', function () {
    $rows = readImportFile(ImportFile::xlsx([ImportFile::row()], ['firstSheet' => 'How to fill']));

    expect($rows)->toHaveCount(1)->and($rows[2]['first_name'])->toBe('Juan');
});

it('uses the first sheet when there is no sheet called Visits', function () {
    $rows = readImportFile(ImportFile::xlsx([ImportFile::row()], ['sheet' => 'Sheet1']));

    expect($rows)->toHaveCount(1)->and($rows[2]['first_name'])->toBe('Juan');
});

it('reads a UTF-8 .csv file, with or without a byte order mark', function (bool $bom) {
    $rows = readImportFile(ImportFile::csv([ImportFile::row(['last_name' => 'Peña, Jr.', 'remarks' => "Said \"hi\""])], ['bom' => $bom]));

    expect($rows)->toHaveCount(1)
        ->and($rows[2]['last_name'])->toBe('Peña, Jr.')
        ->and($rows[2]['remarks'])->toBe('Said "hi"')
        ->and($rows[2]['visit_date'])->toBe('2026-10-05');
})->with([false, true]);

it('matches the headers ignoring case, spaces and the star', function () {
    $headers = ['visit date', 'VISIT TIME*', 'Patient  ID', 'last name *', 'First Name', 'm.i.', 'sex', 'BIRTHDATE', 'age', 'branch', 'rank / type', 'rank other (specify)', 'diagnosis', 'diagnosis other (specify)', 'category', 'remarks', 'Written By'];

    expect(readImportFile(ImportFile::csv([ImportFile::row()], ['headers' => $headers])))->toHaveCount(1);
});

it('skips blank rows but keeps the row numbers of the file', function () {
    $rows = readImportFile(ImportFile::xlsx([ImportFile::row(), [], ['', '', ''], ImportFile::row(['last_name' => 'Reyes'])]));

    expect(array_keys($rows))->toBe([2, 5]);
});

it('refuses a file whose first row is not the template header', function (string $case, string $expected) {
    $headers = App\Services\Import\ImportColumns::headers();

    $headers = match ($case) {
        'a wrong name' => array_replace($headers, [3 => 'Surname *']),
        'a missing column' => array_values(array_diff($headers, ['Age'])),
        'the wrong order' => array_replace($headers, [0 => 'Visit Time *', 1 => 'Visit Date *']),
    };

    expect(fn () => readImportFile(ImportFile::xlsx([ImportFile::row()], ['headers' => $headers])))
        ->toThrow(ImportFileException::class, $expected);
})->with([
    'a wrong name' => ['a wrong name', 'Column 4 should be “Last Name”'],
    'a missing column' => ['a missing column', 'Column 9 should be “Age”'],
    'the wrong order' => ['the wrong order', 'Column 1 should be “Visit Date”'],
]);

it('refuses an empty file', function () {
    expect(fn () => readImportFile(ImportFile::xlsx([], ['headers' => null])))
        ->toThrow(ImportFileException::class, 'The file is empty');
});

it('refuses a file that is not a spreadsheet', function () {
    expect(fn () => readImportFile(ImportFile::raw('this is not a workbook', 'fake.xlsx')))
        ->toThrow(ImportFileException::class, "This file can't be read");
});

it('refuses a file with more than 2,000 rows and accepts exactly 2,000', function () {
    $row = ImportFile::row();

    expect(readImportFile(ImportFile::csv(array_fill(0, 2000, $row))))->toHaveCount(2000);

    expect(fn () => readImportFile(ImportFile::csv(array_fill(0, 2001, $row))))
        ->toThrow(ImportFileException::class, 'more than 2,000 rows');
});

it('hands over real Excel date and time cells as dates, and text as text', function () {
    $rows = readImportFile(ImportFile::xlsx([ImportFile::row([
        'visit_date' => ImportFile::excelDate('2026-10-05'),
        'visit_time' => ImportFile::excelTime('14:05'),
        'birthdate' => ImportFile::excelDate('1988-05-14'),
        'age' => 36,
    ])]));

    expect($rows[2]['visit_date'])->toBeInstanceOf(DateTimeInterface::class)
        ->and($rows[2]['visit_date']->format('Y-m-d'))->toBe('2026-10-05')
        ->and($rows[2]['visit_time']->format('H:i'))->toBe('14:05')
        ->and($rows[2]['birthdate']->format('Y-m-d'))->toBe('1988-05-14')
        ->and($rows[2]['age'])->toBe('36');
});

it('never calculates a formula: it comes back as its own text', function () {
    $rows = readImportFile(ImportFile::xlsx([ImportFile::row(['remarks' => ImportFile::formula('=1+1')])]));
    $csv = readImportFile(ImportFile::csv([ImportFile::row(['remarks' => '=1+1'])]));

    expect($rows[2]['remarks'])->toBe('=1+1')
        ->and($csv[2]['remarks'])->toBe('=1+1');
});

it('keeps an uploaded file only for an hour and only for the person who uploaded it', function () {
    $this->travelTo(now());
    $admin = userWithRole('admin');
    $other = userWithRole('encoder');
    $store = app(ImportFileStore::class);

    $staged = $store->store(ImportFile::csv([ImportFile::row()]), 'csv', $admin);

    expect(Storage::disk('local')->exists($staged->relativePath()))->toBeTrue()
        ->and($store->find($staged->token, $admin))->not->toBeNull()
        ->and($store->find($staged->token, $other))->toBeNull()
        ->and($store->find('../../.env', $admin))->toBeNull();

    $this->travel(61)->minutes();

    expect($store->find($staged->token, $admin))->toBeNull()
        ->and(Storage::disk('local')->exists($staged->relativePath()))->toBeFalse();
});

it('purges files older than an hour', function () {
    $store = app(ImportFileStore::class);
    $old = $store->store(ImportFile::csv([ImportFile::row()]), 'csv', userWithRole('admin'));
    $new = $store->store(ImportFile::csv([ImportFile::row()]), 'csv', userWithRole('admin', ['username' => 'second.admin', 'is_active' => false]));

    foreach (["imports/{$old->token}.csv", "imports/{$old->token}.json"] as $path) {
        touch(Storage::disk('local')->path($path), time() - 7200);
    }

    expect($store->purgeExpired())->toBe(2)
        ->and(Storage::disk('local')->exists("imports/{$old->token}.csv"))->toBeFalse()
        ->and(Storage::disk('local')->exists("imports/{$new->token}.csv"))->toBeTrue();
});

// --- Limits and encodings (6j review fixes) ----------------------------------------

it('refuses data far below the last row instead of dropping it', function (string $kind) {
    if ($kind === 'xlsx') {
        $book = new PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Visits');
        $sheet->fromArray([ImportColumns::headers()], null, 'A1');
        $sheet->setCellValue('A2', '2026-10-05');
        $sheet->setCellValue('D15000', 'Hidden');
        $path = sys_get_temp_dir().'/far-'.uniqid().'.xlsx';
        PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save($path);
        $file = new UploadedFile($path, 'far.xlsx', null, null, true);
    } else {
        $file = ImportFile::raw(implode(',', ImportColumns::headers())."\n".str_repeat("\n", 10100)."2026-10-05,08:15,,Hidden\n", 'far.csv');
    }

    expect(fn () => readImportFile($file))
        ->toThrow(ImportFileException::class, 'This file has more than 2,000 rows. Split it into smaller files.');
})->with(['xlsx', 'csv']);

it('refuses a sheet that is huge once unpacked with a friendly message, before reading it', function () {
    $path = sys_get_temp_dir().'/huge-'.uniqid().'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    // A million formatted, empty rows: tiny in the zip, far too big to load.
    $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('<row r="1" s="1" customFormat="1"/>', 700000));
    $zip->close();

    expect(filesize($path))->toBeLessThan(2 * 1024 * 1024)
        ->and(fn () => readImportFile(new UploadedFile($path, 'huge.xlsx', null, null, true)))
        ->toThrow(ImportFileException::class, 'This file is too large to read.');
});

it('shows the too-large message under the file field when such a file is uploaded', function () {
    $path = sys_get_temp_dir().'/huge-'.uniqid().'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('<row r="1" s="1" customFormat="1"/>', 700000));
    $zip->close();

    $admin = userWithRole('admin');

    $this->actingAs($admin)->from('/records/import')
        ->post('/records/import', ['file' => new UploadedFile($path, 'huge.xlsx', null, null, true)])
        ->assertSessionHasErrors(['file' => 'This file is too large to read. Its sheet is probably formatted far below your last row. Copy your rows into a new copy of the downtime template, and upload that file.']);

    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('reads a Windows-1252 .csv (Excel\'s plain CSV) so ñ and Ñ survive', function () {
    $utf8 = ImportFile::csv([ImportFile::row(['last_name' => 'PEÑA', 'first_name' => 'Niño', 'remarks' => 'Cañete'])]);
    $ansi = ImportFile::raw(mb_convert_encoding((string) file_get_contents($utf8->getPathname()), 'Windows-1252', 'UTF-8'), 'excel.csv');

    expect(mb_check_encoding((string) file_get_contents($ansi->getPathname()), 'UTF-8'))->toBeFalse();

    $row = array_values(readImportFile($ansi))[0];

    expect($row['last_name'])->toBe('PEÑA')
        ->and($row['first_name'])->toBe('Niño')
        ->and($row['remarks'])->toBe('Cañete');
});

it('keeps a valid UTF-8 .csv exactly as it is', function () {
    $row = array_values(readImportFile(ImportFile::csv([ImportFile::row(['last_name' => 'PEÑA'])])))[0];

    expect($row['last_name'])->toBe('PEÑA');
});

it('purges waiting files every 15 minutes', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'import-files-purge');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *');
});
