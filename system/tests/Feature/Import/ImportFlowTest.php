<?php

use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\FolderZipper;
use App\Services\Import\ImportFileStore;
use App\Services\Import\ImportService;
use App\Services\Import\ImportSheetReader;
use App\Services\ReportBuilder;
use App\Services\TableStatistics;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ImportFile;
use Tests\Support\ImportScenario;

/*
 * The downtime import from the Admin's side (Step 6j): upload, preview,
 * confirm, past imports, undo. Access for the other roles and the template
 * download are in ImportAccessTest and ImportTemplateTest.
 * Times are Asia/Manila; "now" is 2026-10-09 14:00.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));
    $this->lists = ImportScenario::lists();
    $this->admin = userWithRole('admin', ['username' => 'admin.garcia']);
});

/** Upload a file as Admin and return the token of the waiting file. */
function uploadImport(object $test, UploadedFile $file): string
{
    $response = $test->actingAs($test->admin)->post('/records/import', ['file' => $file]);

    $response->assertSessionHasNoErrors();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['file'];
}

/** Everything that would change if something were saved. */
function importSnapshot(): array
{
    return [Patient::withTrashed()->count(), Visit::withTrashed()->count(), ImportBatch::count(), AuditLog::count()];
}

/** The first error message flashed for a field after a redirect. */
function sessionError(string $field): ?string
{
    return session('errors')['default']['messages'][$field][0] ?? null;
}

/** The rows of the preview, as the page receives them. */
function previewRows(object $test, string $token): array
{
    $rows = [];

    $test->actingAs($test->admin)->get("/records/import?file={$token}")
        ->assertInertia(function (Assert $page) use (&$rows): void {
            $rows = $page->toArray()['props']['preview']['rows'];
        });

    return $rows;
}

// --- Upload and preview --------------------------------------------------------

it('keeps the file and shows the preview with the summary and every row', function () {
    $file = ImportFile::xlsx([
        ImportFile::row(),
        ImportFile::row(['last_name' => 'Santos', 'first_name' => 'Maria', 'diagnosis' => 'Flu']),
        ImportFile::row(['last_name' => 'Reyes', 'first_name' => 'Pedro']),
    ], ['name' => 'november-er.xlsx']);

    $token = uploadImport($this, $file);

    expect(Storage::disk('local')->exists("imports/{$token}.xlsx"))->toBeTrue();

    $this->actingAs($this->admin)->get("/records/import?file={$token}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('records/import')
            ->where('preview.token', $token)
            ->where('preview.file_name', 'november-er.xlsx')
            ->where('preview.summary', ['total' => 3, 'ok' => 2, 'warning' => 0, 'error' => 1])
            ->has('preview.rows', 3)
            ->where('preview.rows.1.number', 3)
            ->where('preview.rows.1.status', 'error')
            ->where('preview.rows.1.errors.0', 'Diagnosis “Flu” is not on the list.')
            ->where('preview.rows.0.display.name', 'Dela Cruz, Juan')
            ->where('preview.rows.0.selected', true)
            ->where('preview.rows.1.selected', false));
});

it('saves nothing at all while previewing', function () {
    $before = importSnapshot();

    $token = uploadImport($this, ImportFile::csv([ImportFile::row(), ImportFile::row(['last_name' => 'Santos', 'diagnosis' => 'Flu'])]));
    $this->actingAs($this->admin)->get("/records/import?file={$token}")->assertOk();
    $this->actingAs($this->admin)->get("/records/import?file={$token}")->assertOk();

    expect(importSnapshot())->toBe($before);
});

it('takes dates written as text and real Excel date and time cells alike', function () {
    $token = uploadImport($this, ImportFile::xlsx([
        ImportFile::row(),
        ImportFile::row(['last_name' => 'Santos', 'visit_date' => ImportFile::excelDate('2026-10-05'), 'visit_time' => ImportFile::excelTime('09:30'), 'birthdate' => ImportFile::excelDate('1975-02-02')]),
    ]));

    $rows = previewRows($this, $token);

    expect(array_column($rows, 'status'))->toBe(['ok', 'ok'])
        ->and($rows[1]['display']['date'])->toBe('2026-10-05')
        ->and($rows[1]['display']['time'])->toBe('09:30');
});

it('reads a .csv file too', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));

    expect(Storage::disk('local')->exists("imports/{$token}.csv"))->toBeTrue()
        ->and(array_column(previewRows($this, $token), 'status'))->toBe(['ok']);
});

it('refuses a file that cannot be used and deletes it', function (string $case, string $message) {
    $file = match ($case) {
        'wrong header' => ImportFile::xlsx([ImportFile::row()], ['headers' => ['Date', 'Time']]),
        'not a spreadsheet' => ImportFile::raw('hello', 'x.xlsx'),
        'too many rows' => ImportFile::csv(array_fill(0, 2001, ImportFile::row())),
    };

    $this->actingAs($this->admin)->from('/records/import')->post('/records/import', ['file' => $file])
        ->assertRedirect('/records/import');

    expect(sessionError('file'))->toContain($message)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
})->with([
    'wrong header' => ['wrong header', 'Column 1 should be “Visit Date”'],
    'not a spreadsheet' => ['not a spreadsheet', "This file can't be read."],
    'too many rows' => ['too many rows', 'This file has more than 2,000 rows. Split it into smaller files.'],
]);

it('refuses a missing file, a big file and another kind of file', function () {
    $this->actingAs($this->admin)->from('/records/import');

    $this->post('/records/import', [])->assertSessionHasErrors(['file' => 'Choose the file to import.']);
    $this->post('/records/import', ['file' => UploadedFile::fake()->create('big.csv', 2049)])->assertSessionHasErrors(['file' => 'The file is bigger than 2 MB. Split it into smaller files.']);
    $this->post('/records/import', ['file' => UploadedFile::fake()->createWithContent('visits.txt', 'a,b')])->assertSessionHasErrors(['file' => 'Use an Excel (.xlsx) or a CSV (.csv) file.']);
    $this->post('/records/import', ['file' => UploadedFile::fake()->createWithContent('visits.xlsx.php', 'a,b')])->assertSessionHasErrors('file');

    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('accepts a file of exactly 2 MB and exactly 2,000 rows', function () {
    $token = uploadImport($this, ImportFile::csv(array_fill(0, 2000, ImportFile::row())));

    expect(importSnapshot()[1])->toBe(0)->and(strlen($token))->toBe(40);

    // Exactly 2 MB is not "bigger than 2 MB" (the file itself is no spreadsheet, so it fails for that).
    $this->actingAs($this->admin)->post('/records/import', ['file' => UploadedFile::fake()->create('ok.csv', 2048)]);

    expect(sessionError('file'))->not->toBe('The file is bigger than 2 MB. Split it into smaller files.');
});

it('says the file is gone after an hour, when it is cancelled, and for a made-up token', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $this->actingAs($this->admin);

    $this->get('/records/import?file=not-a-token')->assertRedirect('/records/import');
    $this->post('/records/import/cancel', ['token' => $token])->assertRedirect('/records/import');
    expect(Storage::disk('local')->exists("imports/{$token}.csv"))->toBeFalse();

    $this->get("/records/import?file={$token}")
        ->assertRedirect('/records/import')
        ->assertSessionHas('inertia.flash_data.toast.message', 'That file is no longer available. Upload it again.');

    $again = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $this->travel(61)->minutes();

    $this->get("/records/import?file={$again}")->assertRedirect('/records/import');
    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('keeps the waiting files out of the backup zip', function () {
    expect(ImportFileStore::path())->toEndWith('imports');

    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $private = Storage::disk('local')->path('');
    $zip = tempnam(sys_get_temp_dir(), 'zip').'.zip';

    (new FolderZipper)->zip($private, $zip, [ImportFileStore::path()]);

    $archive = new ZipArchive;
    $archive->open($zip);
    $names = array_map(fn ($i) => $archive->getNameIndex($i), range(0, $archive->numFiles - 1));
    $archive->close();

    expect(collect($names)->filter(fn ($name) => str_contains($name, $token))->all())->toBe([]);
});

// --- Confirm ---------------------------------------------------------------------

/** Confirm with these row numbers (and rows to save as new). */
function confirmImport(object $test, string $token, array $rows, array $asNew = []): TestResponse
{
    return $test->actingAs($test->admin)->from("/records/import?file={$token}")
        ->post('/records/import/confirm', ['token' => $token, 'rows' => $rows, 'new_patient_rows' => $asNew]);
}

it('refreshes the MySQL table statistics once after a confirmed import, so the first report is not slow', function () {
    $statistics = Mockery::mock(TableStatistics::class);
    $statistics->shouldReceive('refresh')->once();
    $this->instance(TableStatistics::class, $statistics);

    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));

    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(1);
});

it('does not refresh the statistics when the import is refused or imports nothing', function () {
    $statistics = Mockery::mock(TableStatistics::class);
    $statistics->shouldReceive('refresh')->never();
    $this->instance(TableStatistics::class, $statistics);

    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));

    // A row that is not in the file: refused, nothing saved.
    confirmImport($this, $token, [9])->assertSessionHasErrors('import');

    expect(Visit::count())->toBe(0);
});

it('imports the chosen rows as visits with a batch, a Visit No and the Written by', function () {
    $token = uploadImport($this, ImportFile::xlsx([
        ImportFile::row(),
        ImportFile::row(['last_name' => 'Santos', 'first_name' => 'Maria', 'sex' => 'Female', 'birthdate' => '1975-02-02', 'visit_time' => '23:30', 'written_by' => 'Nurse B. Cruz', 'category' => 'Admission', 'remarks' => 'From ER, admitted to ward']),
        ImportFile::row(['last_name' => 'Reyes', 'first_name' => 'Pedro', 'diagnosis' => 'Flu']),
    ], ['name' => 'er-sheet.xlsx']));

    confirmImport($this, $token, [2, 3])
        ->assertRedirect('/records/import')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Imported 2 visits, skipped 1.');

    $batch = ImportBatch::sole();
    $visits = Visit::query()->orderBy('id')->get();

    expect($batch->file_name)->toBe('er-sheet.xlsx')
        ->and([$batch->original_rows, $batch->imported_count, $batch->skipped_count])->toBe([3, 2, 1])
        ->and($batch->imported_by)->toBe($this->admin->id)
        ->and($batch->undone_at)->toBeNull()
        ->and($visits)->toHaveCount(2)
        ->and($visits[0]->visited_at->format('Y-m-d H:i'))->toBe('2026-10-05 08:15')
        ->and($visits[0]->visited_at->timezoneName)->toBe('Asia/Manila')
        ->and($visits[1]->visited_at->format('Y-m-d H:i'))->toBe('2026-10-05 23:30')
        ->and($visits[0]->visit_no)->toBe(Visit::formatNumber($visits[0]->id))
        ->and($visits[0]->encoded_by)->toBe($this->admin->id)
        ->and($visits[0]->written_by)->toBe('Nurse A. Ramos')
        ->and($visits[1]->written_by)->toBe('Nurse B. Cruz')
        ->and($visits[0]->import_batch_id)->toBe($batch->id)
        ->and($visits[0]->age)->toBe(36)
        ->and($visits[0]->branch_id)->toBe($this->lists['army']->id)
        ->and($visits[0]->rank_id)->toBe($this->lists['cpl']->id)
        ->and($visits[1]->category->value)->toBe('Admission')
        ->and($visits[1]->remarks)->toBe('From ER, admitted to ward');

    $patients = Patient::query()->orderBy('id')->get();

    expect($patients)->toHaveCount(2)
        ->and($patients[0]->patient_no)->toBe(Patient::formatNumber($patients[0]->id))
        ->and($patients[0]->import_batch_id)->toBe($batch->id)
        ->and($patients[0]->last_name)->toBe('Dela Cruz');

    // The waiting file is gone after the import (only the small "already imported" note stays).
    expect(array_values(array_filter(Storage::disk('local')->allFiles('imports'), fn (string $path): bool => ! str_ends_with($path, '.done'))))->toBe([]);
});

it('imports only the rows that were chosen', function () {
    $token = uploadImport($this, ImportFile::csv([
        ImportFile::row(),
        ImportFile::row(['last_name' => 'Santos']),
        ImportFile::row(['last_name' => 'Reyes']),
    ]));

    confirmImport($this, $token, [3])->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(1)
        ->and(Patient::sole()->last_name)->toBe('Santos')
        ->and(ImportBatch::sole()->skipped_count)->toBe(2);
});

it('never imports a row with an error, and imports nothing when one is sent', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(), ImportFile::row(['last_name' => 'Santos', 'diagnosis' => 'Flu'])]));
    $before = importSnapshot();

    confirmImport($this, $token, [2, 3])
        ->assertSessionHasErrors(['import' => 'Row 3 has an error and can\'t be imported. Nothing was imported. Check the preview again.']);
    confirmImport($this, $token, [9])->assertSessionHasErrors('import');
    confirmImport($this, $token, [])->assertSessionHasErrors('rows');

    expect(importSnapshot())->toBe($before)
        ->and(Storage::disk('local')->exists("imports/{$token}.csv"))->toBeTrue();
});

it('checks the rows again when confirming: a month closed after the preview stops the import', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(['visit_date' => '2026-09-15'])]));

    ImportScenario::closeMonth('2026-09', $this->admin->id);
    $before = importSnapshot();

    confirmImport($this, $token, [2])->assertSessionHasErrors('import');

    expect(importSnapshot())->toBe($before);
});

it('saves all of the rows or none: a database error in the third row leaves nothing behind', function () {
    $token = uploadImport($this, ImportFile::csv([
        ImportFile::row(),
        ImportFile::row(['last_name' => 'Santos']),
        ImportFile::row(['last_name' => 'Reyes']),
    ]));
    $before = importSnapshot();
    $created = 0;

    Visit::creating(function () use (&$created): void {
        if (++$created === 3) {
            throw new RuntimeException('disk full');
        }
    });

    confirmImport($this, $token, [2, 3, 4])
        ->assertSessionHasErrors(['import' => 'The import could not be saved, so nothing was imported. Try again. If it keeps happening, tell IT.']);

    expect($created)->toBe(3)
        ->and(importSnapshot())->toBe($before)
        ->and(Storage::disk('local')->exists("imports/{$token}.csv"))->toBeTrue();
});

it('links a row to the patient with the same name and birthdate, and Save as new makes a new patient', function () {
    $existing = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01', 'sex' => 'Male']);
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(), ImportFile::row(['visit_time' => '10:00'])]));

    $rows = previewRows($this, $token);

    expect(array_column($rows, 'status'))->toBe(['warning', 'warning'])
        ->and($rows[0]['warnings'][0]['code'])->toBe('link')
        ->and($rows[0]['can_save_as_new'])->toBeTrue();

    confirmImport($this, $token, [2, 3], [3])->assertSessionHasNoErrors();

    $visits = Visit::query()->orderBy('id')->get();

    expect(Patient::count())->toBe(2)
        ->and($visits[0]->patient_id)->toBe($existing->id)
        ->and($visits[1]->patient_id)->not->toBe($existing->id)
        ->and(Patient::query()->whereKey($visits[1]->patient_id)->value('import_batch_id'))->toBe(ImportBatch::sole()->id)
        ->and($existing->fresh()->import_batch_id)->toBeNull();
});

it('uses one new patient for the same person in two rows', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(), ImportFile::row(['visit_time' => '10:00', 'category' => 'OPD'])]));

    confirmImport($this, $token, [2, 3])->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(1)->and(Visit::count())->toBe(2)->and(Visit::distinct()->pluck('patient_id'))->toHaveCount(1);
});

it('adds the visit to the patient named by a Patient ID without changing the patient', function () {
    $patient = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_initial' => 'Q', 'birthdate' => '1990-05-01', 'sex' => 'Male']);
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(['patient_no' => $patient->patient_no, 'middle_initial' => 'R'])]));

    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(1)
        ->and(Visit::sole()->patient_id)->toBe($patient->id)
        ->and($patient->fresh()->middle_initial)->toBe('Q');
});

it('refuses a Patient ID that belongs to someone else, in the preview and in the import', function () {
    $other = Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'birthdate' => '1975-02-02']);
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(['patient_no' => $other->patient_no])]));

    expect(previewRows($this, $token)[0]['status'])->toBe('error');

    confirmImport($this, $token, [2])->assertSessionHasErrors('import');

    expect(Visit::count())->toBe(0);
});

it('imports a possible duplicate only when it was ticked', function () {
    $patient = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    Visit::factory()->for($patient)->create(['visited_at' => '2026-10-05 08:15:00', 'category' => 'ER']);
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(['patient_no' => $patient->patient_no])]));

    $rows = previewRows($this, $token);

    expect($rows[0]['status'])->toBe('warning')
        ->and($rows[0]['selected'])->toBeFalse();

    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(2);
});

it('stores formula-looking text as plain text', function () {
    $token = uploadImport($this, ImportFile::xlsx([ImportFile::row(['remarks' => '=1+1', 'written_by' => ImportFile::formula('=2+2')])]));

    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    expect(Visit::sole()->remarks)->toBe('=1+1')
        ->and(Visit::sole()->written_by)->toBe('=2+2');
});

it('counts imported visits in the reports like encoded ones', function () {
    $token = uploadImport($this, ImportFile::csv([
        ImportFile::row(),
        ImportFile::row(['last_name' => 'Santos', 'first_name' => 'Maria', 'category' => 'OPD']),
        ImportFile::row(['last_name' => 'Reyes', 'first_name' => 'Pedro', 'diagnosis' => 'Other (specify)', 'diagnosis_other' => 'dengue fever']),
    ]));

    confirmImport($this, $token, [2, 3, 4])->assertSessionHasNoErrors();

    $report = app(ReportBuilder::class)->build('2026-10');

    expect($report['categories']['ER']['patients'])->toBe(2)
        ->and($report['categories']['ER']['visits'])->toBe(2)
        ->and($report['categories']['OPD']['visits'])->toBe(1)
        ->and(collect($report['categories']['ER']['diagnoses'])->firstWhere('label', 'Dengue Fever')['count'])->toBe(1);
});

it('writes one import.created entry with counts and the file name, and no patient data', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(), ImportFile::row(['last_name' => 'Santos'])], ['name' => 'er-sheet.csv']));

    confirmImport($this, $token, [2, 3])->assertSessionHasNoErrors();

    $batch = ImportBatch::sole();
    $entry = AuditLog::query()->where('action', 'import.created')->sole();

    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->subject_type)->toBe('import_batches')
        ->and($entry->subject_id)->toBe($batch->id)
        ->and($entry->changes)->toEqual(['file_name' => 'er-sheet.csv', 'original_rows' => 2, 'imported' => 2, 'skipped' => 0, 'new_patients' => 2, 'linked_patients' => 0])
        ->and(json_encode($entry->changes))->not->toContain('Cruz')->not->toContain('Santos')
        ->and(AuditLog::where('action', 'visit.created')->count())->toBe(2)
        ->and(AuditLog::where('action', 'patient.created')->count())->toBe(2);
});

// --- Past imports and undo ----------------------------------------------------------

/** Import these rows as Admin and return the batch. */
function importRows(object $test, array $rows, ?array $choose = null): ImportBatch
{
    $token = uploadImport($test, ImportFile::csv($rows, ['name' => 'batch.csv']));
    confirmImport($test, $token, $choose ?? range(2, count($rows) + 1))->assertSessionHasNoErrors();

    return ImportBatch::orderByDesc('id')->firstOrFail();
}

it('lists past imports, 10 to a page, with who imported them', function () {
    foreach (range(1, 12) as $n) {
        ImportBatch::create(['file_name' => "file-{$n}.csv", 'original_rows' => 5, 'imported_count' => 4, 'skipped_count' => 1, 'imported_by' => $this->admin->id, 'created_at' => now()->subMinutes(20 - $n)]);
    }

    $this->actingAs($this->admin)->get('/records/import')
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches.data', 10)
            ->where('batches.data.0.file_name', 'file-12.csv')
            ->where('batches.data.0.imported_by', 'admin.garcia')
            ->where('batches.data.0.imported_count', 4)
            ->where('batches.data.0.skipped_count', 1)
            ->where('batches.data.0.can_undo', true)
            ->where('batches.meta.total', 12)
            ->where('batches.meta.last_page', 2)
            ->where('preview', null));

    $this->actingAs($this->admin)->get('/records/import?page=2')
        ->assertInertia(fn (Assert $page) => $page->has('batches.data', 2));

    $this->actingAs($this->admin)->get('/records/import?page=9')->assertRedirect('/records/import?page=2');
});

it('moves a whole import to Trash with the reason "Wrong import"', function () {
    $batch = importRows($this, [ImportFile::row(), ImportFile::row(['last_name' => 'Santos'])]);

    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect(Visit::count())->toBe(0)
        ->and(Visit::onlyTrashed()->pluck('delete_reason')->unique()->all())->toBe(['Wrong import'])
        ->and(Visit::onlyTrashed()->pluck('deleted_by')->unique()->all())->toBe([$this->admin->id])
        ->and(Patient::count())->toBe(0)
        ->and(Patient::onlyTrashed()->pluck('delete_reason')->unique()->all())->toBe(['Wrong import'])
        ->and($batch->fresh()->undone_at)->not->toBeNull()
        ->and($batch->fresh()->undone_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)->get('/records/import')
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.data.0.can_undo', false)
            ->where('batches.data.0.undone_by', 'admin.garcia'));

    // Once is enough.
    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")
        ->assertSessionHasErrors(['import' => 'This import was already undone.']);
});

it('keeps a patient that was there before the import, or has other visits', function () {
    $before = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    Visit::factory()->for($before)->create(['visited_at' => '2026-10-01 08:00:00']);

    $batch = importRows($this, [ImportFile::row(), ImportFile::row(['last_name' => 'Santos', 'first_name' => 'Maria', 'birthdate' => '1975-02-02', 'sex' => 'Female'])]);
    $santos = Patient::query()->where('last_name', 'Santos')->sole();

    // A visit encoded later for a patient the import created.
    Visit::factory()->for($santos)->create(['visited_at' => '2026-10-08 08:00:00']);

    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(2)
        ->and(Visit::count())->toBe(2)
        ->and(Visit::onlyTrashed()->count())->toBe(2)
        ->and($before->fresh()->trashed())->toBeFalse()
        ->and($santos->fresh()->trashed())->toBeFalse();
});

it('does not undo an import while one of its visits is in a closed month', function () {
    $batch = importRows($this, [ImportFile::row(), ImportFile::row(['last_name' => 'Santos', 'visit_date' => '2026-09-20'])]);
    ImportScenario::closeMonth('2026-09', $this->admin->id);

    $this->actingAs($this->admin)->get('/records/import')
        ->assertInertia(fn (Assert $page) => $page->where('batches.data.0.can_undo', false)->where('batches.data.0.blocked_month', 'September 2026'));

    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")
        ->assertSessionHasErrors(['import' => 'September 2026 is closed. Reopen September 2026 first, then undo this import.']);

    expect(Visit::count())->toBe(2)
        ->and(Patient::count())->toBe(2)
        ->and($batch->fresh()->undone_at)->toBeNull()
        ->and(AuditLog::where('action', 'import.undone')->exists())->toBeFalse();
});

it('logs import.undone with counts only, and the usual Trash entries for each record', function () {
    $batch = importRows($this, [ImportFile::row(), ImportFile::row(['last_name' => 'Santos'])]);

    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")->assertSessionHasNoErrors();

    $entry = AuditLog::query()->where('action', 'import.undone')->sole();

    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->subject_type)->toBe('import_batches')
        ->and($entry->changes)->toEqual(['file_name' => 'batch.csv', 'visits_moved_to_trash' => 2, 'patients_moved_to_trash' => 2])
        ->and(AuditLog::where('action', 'visit.deleted')->count())->toBe(2)
        ->and(AuditLog::where('action', 'patient.deleted')->count())->toBe(2)
        ->and(AuditLog::where('action', 'visit.deleted')->first()->changes['reason'])->toBe('Wrong import');
});

it('can restore one visit of an undone import from Trash, with its patient', function () {
    $batch = importRows($this, [ImportFile::row()]);
    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")->assertSessionHasNoErrors();

    $patient = Patient::onlyTrashed()->sole();
    $visit = Visit::onlyTrashed()->sole();

    $this->actingAs($this->admin)->post("/trash/visits/{$visit->id}/restore")->assertSessionHasErrors('item');
    $this->actingAs($this->admin)->post("/trash/patients/{$patient->id}/restore")->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/trash/visits/{$visit->id}/restore")->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(1)->and(Patient::count())->toBe(1);
});

it('shows the Imported label and Written by in Patient Records', function () {
    importRows($this, [ImportFile::row()]);
    Visit::factory()->create(['visited_at' => '2026-10-06 09:00:00']);

    $this->actingAs($this->admin)->get('/records/visits?from=2026-10-01&to=2026-10-09')
        ->assertInertia(fn (Assert $page) => $page
            ->where('visits.data', fn ($rows) => collect($rows)->sortBy('visited_at')->values()->pluck('imported')->all() === [true, false]
                && collect($rows)->firstWhere('imported', true)['written_by'] === 'Nurse A. Ramos'
                && collect($rows)->firstWhere('imported', false)['written_by'] === null));
});

it('keeps another user from using the waiting file of someone else', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $other = User::factory()->admin()->create(['is_active' => false]);

    // A second Admin can't exist (only one active); the store still refuses by owner.
    expect(app(ImportFileStore::class)->find($token, $other))->toBeNull()
        ->and(app(ImportFileStore::class)->find($token, $this->admin))->not->toBeNull();
});

// --- Safety of the confirm (6j review fixes) -------------------------------------

it('refuses the import when a month is closed in the moment between the check and the save', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(['visit_date' => '2026-09-15'])]));
    $before = importSnapshot();
    $closed = false;

    // Someone closes September just as the import's transaction starts.
    Event::listen(TransactionBeginning::class, function () use (&$closed): void {
        if (! $closed && collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->contains(fn (array $frame): bool => ($frame['class'] ?? null) === ImportService::class && $frame['function'] === 'save')) {
            $closed = true;
            ImportScenario::closeMonth('2026-09', $this->admin->id);
        }
    });

    confirmImport($this, $token, [2])
        ->assertSessionHasErrors(['import' => 'September 2026 is closed. Reopen September 2026 first. Nothing was imported.']);

    expect($closed)->toBeTrue()
        ->and(importSnapshot())->toBe($before)
        ->and(Storage::disk('local')->exists("imports/{$token}.csv"))->toBeTrue();
});

it('takes the month lock of every year in the file and refuses while that month is being closed', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $before = importSnapshot();
    config(['census.lock_wait_seconds' => 0]);

    $lock = Cache::lock('month-close:2026', 60);
    expect($lock->get())->toBeTrue();

    confirmImport($this, $token, [2])
        ->assertSessionHasErrors(['import' => 'A month is being closed or reopened right now. Wait a moment and try again.']);

    expect(importSnapshot())->toBe($before);

    $lock->release();
    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(1);
});

it('releases the month lock after an import, so a month can be closed afterwards', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));

    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    $lock = Cache::lock('month-close:2026', 5);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('imports a file only once: a second confirm with the same token saves nothing', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(), ImportFile::row(['last_name' => 'Santos'])]));

    confirmImport($this, $token, [2, 3])->assertSessionHas('inertia.flash_data.toast.message', 'Imported 2 visits.');
    $after = importSnapshot();

    confirmImport($this, $token, [2, 3])
        ->assertSessionHas('inertia.flash_data.toast.message', 'This file was already imported.');

    expect(importSnapshot())->toBe($after)
        ->and(Visit::count())->toBe(2)
        ->and(ImportBatch::count())->toBe(1);
});

it('lets only one of two confirms of the same file take it', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $before = importSnapshot();

    // Another confirm of this file is running right now.
    $lock = Cache::lock('import-confirm:'.$token, 60);
    expect($lock->get())->toBeTrue();

    confirmImport($this, $token, [2])->assertSessionHasErrors(['import' => 'This file was already imported.']);

    expect(importSnapshot())->toBe($before);

    $lock->release();
    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(1);
});

it('gives the file back after a failed import, so it can be tried again', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $created = 0;

    Visit::creating(function () use (&$created): void {
        if (++$created === 1) {
            throw new RuntimeException('disk full');
        }
    });

    confirmImport($this, $token, [2])->assertSessionHasErrors('import');
    expect(Storage::disk('local')->exists("imports/{$token}.csv"))->toBeTrue()
        ->and(Storage::disk('local')->exists("imports/{$token}.used"))->toBeFalse();

    confirmImport($this, $token, [2])->assertSessionHasNoErrors();

    expect(Visit::count())->toBe(1);
});

it('does not import the same file staged twice and confirmed twice: the second one finds duplicates', function () {
    $rows = [ImportFile::row(), ImportFile::row(['last_name' => 'Santos', 'first_name' => 'Maria'])];
    $first = uploadImport($this, ImportFile::csv($rows));
    $second = uploadImport($this, ImportFile::csv($rows));

    confirmImport($this, $first, [2, 3])->assertSessionHasNoErrors();
    $after = importSnapshot();

    confirmImport($this, $second, [2, 3])
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_starts_with($message, 'Nothing was imported. Not imported: rows 2 and 3 now look like a possible duplicate'));

    expect(importSnapshot())->toBe($after)
        ->and(Visit::count())->toBe(2)
        // The file waits, and its page shows the duplicates now.
        ->and(Storage::disk('local')->exists("imports/{$second}.csv"))->toBeTrue()
        ->and(array_column(previewRows($this, $second), 'status'))->toBe(['warning', 'warning'])
        ->and(previewRows($this, $second)[0]['selected'])->toBeFalse();
});

it('imports the other rows and lists the row that became a duplicate after the preview', function () {
    $patient = Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'birthdate' => '1990-05-01']);
    $token = uploadImport($this, ImportFile::csv([
        ImportFile::row(),
        ImportFile::row(['patient_no' => $patient->patient_no, 'last_name' => 'Santos', 'first_name' => 'Maria', 'sex' => $patient->sex->value]),
    ]));

    expect(array_column(previewRows($this, $token), 'status'))->toBe(['ok', 'ok']);

    // The same visit is saved on the Encode screen after the preview.
    Visit::factory()->for($patient)->create(['visited_at' => '2026-10-05 08:15:00', 'category' => 'ER']);

    confirmImport($this, $token, [2, 3])
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_starts_with($message, 'Imported 1 visit, skipped 1. Not imported: row 3 now looks like a possible duplicate'));

    $batch = ImportBatch::sole();

    expect([$batch->imported_count, $batch->skipped_count])->toBe([1, 1])
        ->and(Visit::query()->where('import_batch_id', $batch->id)->count())->toBe(1);
});

it('says so when the file was already imported and then opened again', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    confirmImport($this, $token, [2]);

    $this->actingAs($this->admin)->get("/records/import?file={$token}")
        ->assertRedirect('/records/import')
        ->assertSessionHas('inertia.flash_data.toast.message', 'That file is no longer available. Upload it again.');
});

it('refuses the import when a branch, rank or diagnosis was removed after the preview', function (string $what) {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    $before = importSnapshot();
    $done = false;

    Event::listen(TransactionBeginning::class, function () use (&$done, $what): void {
        if ($done || ! collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->contains(fn (array $frame): bool => ($frame['class'] ?? null) === ImportService::class && $frame['function'] === 'save')) {
            return;
        }

        $done = true;

        match ($what) {
            'diagnosis' => $this->lists['hypertension']->delete(),
            'rank' => $this->lists['cpl']->delete(),
            'branch' => $this->lists['army']->update(['is_active' => false]),
        };
    });

    confirmImport($this, $token, [2])->assertSessionHasErrors('import');

    expect(session('errors')->first('import'))->toContain('Row 2:')->toContain('after the preview. Nothing was imported.')
        ->and(importSnapshot())->toBe($before);
})->with(['diagnosis', 'rank', 'branch']);

it('keeps the checked preview for the page, and checks again when something changed', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row(['visit_date' => '2026-09-15'])]));
    $store = app(ImportFileStore::class);
    $staged = $store->find($token, $this->admin);

    expect(Storage::disk('local')->exists("imports/{$token}.analysis"))->toBeTrue();

    // A service whose reader fails: it proves when the file is read.
    $service = new ImportService(app(AuditLogger::class), new class extends ImportSheetReader
    {
        public function read(string $relativePath, string $extension): array
        {
            throw new RuntimeException('the file was read again');
        }
    }, $store, app(TableStatistics::class));

    // Paging and reloading do not read the file again.
    expect($service->preview($staged)['summary'])->toBe(['total' => 1, 'ok' => 1, 'warning' => 0, 'error' => 0]);

    // A month closed since: the rows are checked again.
    ImportScenario::closeMonth('2026-09', $this->admin->id);

    expect(fn () => $service->preview($staged))->toThrow(RuntimeException::class, 'the file was read again')
        ->and(app(ImportService::class)->preview($staged)['summary']['error'])->toBe(1);
});

it('removes the kept preview with the file', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));

    $this->actingAs($this->admin)->post('/records/import/cancel', ['token' => $token]);

    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('undoes an import under the month lock and refuses while a month is being closed', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));
    confirmImport($this, $token, [2]);
    $batch = ImportBatch::sole();
    config(['census.lock_wait_seconds' => 0]);

    $lock = Cache::lock('month-close:2026', 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")
        ->assertSessionHasErrors(['import' => 'A month is being closed or reopened right now. Wait a moment and try again.']);
    expect($batch->fresh()->undone_at)->toBeNull();

    $lock->release();
    $this->actingAs($this->admin)->post("/records/import/{$batch->id}/undo")->assertSessionHasNoErrors();

    expect($batch->fresh()->undone_at)->not->toBeNull();
});

it('ignores Save as a new patient for a row that has no name and birthdate match', function () {
    $token = uploadImport($this, ImportFile::csv([ImportFile::row()]));

    confirmImport($this, $token, [2], [2])->assertSessionHasNoErrors();

    expect(Patient::count())->toBe(1)
        ->and(Visit::count())->toBe(1);
});
