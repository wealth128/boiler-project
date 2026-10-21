<?php

use App\Models\Patient;
use App\Models\Rank;
use App\Models\Visit;
use App\Services\Import\ImportAnalyzer;
use App\Services\Import\ImportColumns;
use App\Services\Import\ImportRowResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ImportFile;
use Tests\Support\ImportScenario;

/*
 * The row-by-row checks of the downtime import (Step 6j): every error
 * and every warning, in plain words. The visit's own fields use the Encode
 * rules (App\Services\VisitFields); the rest is what only a file needs.
 * "Now" is 2026-10-09 14:00 (Asia/Manila).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-09 14:00', 'Asia/Manila'));
    $this->lists = ImportScenario::lists();
});

/**
 * The rows as the reader hands them over, checked.
 *
 * @param  list<array<string, mixed>>  $rows  Each one starts from a good row
 * @return list<ImportRowResult>
 */
function checkRows(array $rows, array $saveAsNew = []): array
{
    $raw = [];

    foreach ($rows as $i => $values) {
        $raw[$i + 2] = array_map(fn (string $key) => $values[$key] ?? null, ImportColumns::keys());
        $raw[$i + 2] = array_combine(ImportColumns::keys(), $raw[$i + 2]);
    }

    return (new ImportAnalyzer)->analyze($raw, $saveAsNew);
}

/** One row, checked. */
function checkRow(array $values = []): ImportRowResult
{
    return checkRows([ImportFile::row($values)])[0];
}

it('passes a good row as OK with nothing to say', function () {
    $row = checkRow();

    expect($row->status())->toBe('ok')
        ->and($row->errors)->toBe([])
        ->and($row->warnings)->toBe([])
        ->and($row->number)->toBe(2)
        ->and($row->visitedAt->format('Y-m-d H:i'))->toBe('2026-10-05 08:15')
        ->and($row->visitAttributes['age'])->toBe(36)
        ->and($row->visitAttributes['category'])->toBe('ER')
        ->and($row->writtenBy)->toBe('Nurse A. Ramos')
        ->and($row->selectedByDefault())->toBeTrue();
});

it('computes a blank age from the birthdate on the visit date, not today', function () {
    // Birthday 2026-10-06: still 35 on 2026-10-05, 36 today.
    expect(checkRow(['birthdate' => '1990-10-06'])->visitAttributes['age'])->toBe(35)
        ->and(checkRow(['birthdate' => '1990-10-05'])->visitAttributes['age'])->toBe(36)
        ->and(checkRow(['age' => '40'])->visitAttributes['age'])->toBe(40);
});

it('names the row number of the file', function () {
    $rows = checkRows([ImportFile::row(), ImportFile::row(['last_name' => '']), ImportFile::row(['first_name' => 'Pedro'])]);

    expect(array_map(fn ($r) => [$r->number, $r->status()], $rows))->toBe([[2, 'ok'], [3, 'error'], [4, 'ok']]);
});

// --- Errors -------------------------------------------------------------------

it('refuses a row with a required value missing', function (string $key, string $message) {
    $row = checkRow([$key => '']);

    expect($row->status())->toBe('error')->and($row->errors)->toContain($message);
})->with([
    'visit_date' => ['visit_date', 'Visit Date is missing.'],
    'visit_time' => ['visit_time', 'Visit Time is missing.'],
    'last_name' => ['last_name', 'Last Name is missing.'],
    'first_name' => ['first_name', 'First Name is missing.'],
    'sex' => ['sex', 'Sex is missing.'],
    'birthdate' => ['birthdate', 'Birthdate is missing.'],
    'branch' => ['branch', 'Branch is missing.'],
    'rank' => ['rank', 'Rank / Type is missing.'],
    'diagnosis' => ['diagnosis', 'Diagnosis is missing.'],
    'category' => ['category', 'Category is missing.'],
    'written_by' => ['written_by', 'Written by is missing. Write who filled in this row.'],
]);

it('refuses a bad date or time', function (array $values, string $message) {
    expect(checkRow($values)->errors)->toContain($message);
})->with([
    'day first' => [['visit_date' => '03/11/2026'], 'Visit Date “03/11/2026” is not a date. Write it as YYYY-MM-DD, like 2026-11-03.'],
    'not a real day' => [['visit_date' => '2026-02-30'], 'Visit Date “2026-02-30” is not a date. Write it as YYYY-MM-DD, like 2026-11-03.'],
    'a bare number' => [['birthdate' => '1988'], 'Birthdate “1988” is not a date. Write it as YYYY-MM-DD, like 2026-11-03.'],
    'hour 25' => [['visit_time' => '25:00'], 'Visit Time “25:00” is not a time. Write it as 24-hour HH:MM, like 14:05.'],
    'am / pm' => [['visit_time' => '8:15 AM'], 'Visit Time “8:15 AM” is not a time. Write it as 24-hour HH:MM, like 14:05.'],
]);

it('accepts times as 8:15, 08:15 and 14:05:00, and real Excel dates and times', function () {
    expect(checkRow(['visit_time' => '8:15'])->visitedAt->format('H:i'))->toBe('08:15')
        ->and(checkRow(['visit_time' => '14:05:00'])->visitedAt->format('H:i'))->toBe('14:05')
        ->and(checkRow(['visit_time' => ImportFile::excelTime('23:59')])->visitedAt->format('H:i'))->toBe('23:59')
        ->and(checkRow(['visit_date' => ImportFile::excelDate('2026-10-04'), 'birthdate' => ImportFile::excelDate('1990-05-01')])->visitedAt->format('Y-m-d'))->toBe('2026-10-04');
});

it('refuses a visit in the future, before January 2026 or in a closed month', function () {
    ImportScenario::closeMonth('2026-09', userWithRole('admin')->id);

    expect(checkRow(['visit_date' => '2026-10-10'])->errors)->toContain('Visit Date is in the future.')
        ->and(checkRow(['visit_date' => '2026-10-09', 'visit_time' => '14:01'])->errors)->toContain('Visit Time is in the future.')
        ->and(checkRow(['visit_date' => '2026-10-09', 'visit_time' => '14:00'])->errors)->toBe([])
        ->and(checkRow(['visit_date' => '2025-12-31'])->errors)->toContain('Visit Date is before January 2026. Older visits are not imported.')
        ->and(checkRow(['visit_date' => '2026-01-01'])->errors)->toBe([])
        ->and(checkRow(['visit_date' => '2026-09-15'])->errors)->toContain('September 2026 is closed. Reopen September 2026 first.');
});

it('refuses a birthdate after the visit date', function () {
    expect(checkRow(['birthdate' => '2026-10-06'])->errors)->toContain('Birthdate is after the Visit Date.')
        ->and(checkRow(['birthdate' => '2026-10-05', 'age' => '0'])->errors)->toBe([])
        ->and(checkRow(['birthdate' => '2027-01-01'])->errors)->toContain('Birthdate is after the Visit Date.', "Birthdate: The birthdate can't be in the future.");
});

it('refuses an age outside 0 to 120, typed or computed', function (array $values) {
    expect(checkRow($values)->errors)->toContain('Age: Enter an age from 0 to 120.');
})->with([
    'typed 121' => [['age' => '121']],
    'negative' => [['age' => '-1']],
    'words' => [['age' => 'old']],
    'fraction' => [['age' => '36.5']],
    'computed from an old birthdate' => [['birthdate' => '1850-01-01']],
]);

it('refuses a name that is not on the lists', function (array $values, string $message) {
    expect(checkRow($values)->errors)->toContain($message);
})->with([
    'branch' => [['branch' => 'Coast Guard'], 'Branch “Coast Guard” is not on the list.'],
    'rank of another branch' => [['rank' => 'ENS'], 'Rank / Type “ENS” is not on the Army list.'],
    'rank nowhere' => [['rank' => 'GEN'], 'Rank / Type “GEN” is not on the Army list.'],
    'diagnosis' => [['diagnosis' => 'Flu'], 'Diagnosis “Flu” is not on the list.'],
    'sex' => [['sex' => 'Other'], 'Sex: Choose Male or Female.'],
    'category' => [['category' => 'ICU'], 'Category: Choose OPD, ER or Admission.'],
]);

it('matches list names ignoring case and extra spaces', function () {
    $row = checkRow(['branch' => ' army ', 'rank' => 'cpl', 'diagnosis' => 'HYPERTENSION', 'sex' => 'male', 'category' => 'er']);

    expect($row->errors)->toBe([])
        ->and($row->visitAttributes['branch_id'])->toBe($this->lists['army']->id)
        ->and($row->visitAttributes['rank_id'])->toBe($this->lists['cpl']->id)
        ->and($row->visitAttributes['category'])->toBe('ER')
        ->and($row->patientAttributes['sex'])->toBe('Male');
});

it('refuses a branch that is turned off and a rank or diagnosis in Trash', function () {
    $this->lists['navy']->update(['is_active' => false]);
    $this->lists['sgt']->moveToTrash(userWithRole('admin'), 'No longer used');
    $this->lists['dengue']->moveToTrash(userWithRole('admin'), 'No longer used');

    expect(checkRow(['branch' => 'Navy', 'rank' => 'ENS'])->errors)->toContain('Branch: This branch is turned off. Choose another.')
        ->and(checkRow(['rank' => 'SGT'])->errors)->toContain('Rank / Type: This rank was removed from the list. Choose another.')
        ->and(checkRow(['diagnosis' => 'Dengue Fever'])->errors)->toContain('Diagnosis: This diagnosis was removed from the list. Choose another.');
});

it('needs the typed text for "Other (specify)" and refuses it for any other choice', function (array $values, string $message) {
    expect(checkRow($values)->errors)->toContain($message);
})->with([
    'rank other missing' => [['branch' => 'Others', 'rank' => 'Other (specify)'], 'Rank Other (specify) is missing. It is needed when Rank / Type is Other (specify).'],
    'diagnosis other missing' => [['diagnosis' => 'Other (specify)'], 'Diagnosis Other (specify) is missing. It is needed when Diagnosis is Other (specify).'],
    'rank other given' => [['rank_other' => 'Retired'], 'Rank Other (specify) is only for “Other (specify)”. Clear it, or choose Other (specify) in Rank / Type.'],
    'diagnosis other given' => [['diagnosis_other' => 'Migraine'], 'Diagnosis Other (specify) is only for “Other (specify)”. Clear it, or choose Other (specify) in Diagnosis.'],
]);

it('keeps the typed text of "Other (specify)"', function () {
    $row = checkRow(['branch' => 'Others', 'rank' => 'Other (specify)', 'rank_other' => 'Retired PNP', 'diagnosis' => 'Other (specify)', 'diagnosis_other' => 'Migraine']);

    expect($row->errors)->toBe([])
        ->and($row->visitAttributes['rank_other'])->toBe('Retired PNP')
        ->and($row->visitAttributes['diagnosis_other'])->toBe('Migraine');
});

it('refuses text that is too long', function (string $key, int $length, string $message) {
    expect(checkRow([$key => str_repeat('a', $length)])->errors)->toContain($message)
        ->and(checkRow([$key => str_repeat('a', $length - 1)])->errors)->not->toContain($message);
})->with([
    'last name' => ['last_name', 61, 'Last Name: Use at most 60 characters.'],
    'first name' => ['first_name', 61, 'First Name: Use at most 60 characters.'],
    'remarks' => ['remarks', 1001, 'Remarks: Use at most 1,000 characters.'],
    'written by' => ['written_by', 61, 'Written by: Use at most 60 characters.'],
]);

it('refuses typed Other text that is too long', function () {
    $rank = checkRow(['branch' => 'Others', 'rank' => 'Other (specify)', 'rank_other' => str_repeat('a', 61)]);
    $diagnosis = checkRow(['diagnosis' => 'Other (specify)', 'diagnosis_other' => str_repeat('a', 121)]);

    expect($rank->errors)->toContain('Rank Other (specify): Use at most 60 characters.')
        ->and($diagnosis->errors)->toContain('Diagnosis Other (specify): Use at most 120 characters.');
});

it('refuses an M.I. of more than 2 letters and cleans up "j."', function () {
    expect(checkRow(['middle_initial' => 'ABC'])->errors)->toContain('M.I.: Enter up to 2 letters.')
        ->and(checkRow(['middle_initial' => 'j.'])->patientAttributes['middle_initial'])->toBe('J');
});

it('refuses a Patient ID that is not found, in Trash, not valid or for someone else', function () {
    $admin = userWithRole('admin');
    $juan = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    $trashed = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    $trashed->moveToTrash($admin, 'Duplicate patient record');
    $other = Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'birthdate' => '1975-02-02']);

    expect(checkRow(['patient_no' => 'P-009999'])->errors)->toContain('Patient ID P-009999 was not found.')
        ->and(checkRow(['patient_no' => 'ABC'])->errors)->toContain('Patient ID “ABC” is not valid. It looks like P-000012.')
        ->and(checkRow(['patient_no' => $trashed->patient_no])->errors)->toContain("Patient {$trashed->patient_no} is in Trash. Restore the patient first.")
        ->and(checkRow(['patient_no' => $other->patient_no])->errors)->toContain("Patient ID {$other->patient_no} does not match this row: the name or birthdate is different from the patient on file.")
        ->and(checkRow(['patient_no' => $juan->patient_no, 'birthdate' => '1990-05-02'])->errors)->toContain("Patient ID {$juan->patient_no} does not match this row: the name or birthdate is different from the patient on file.")
        ->and(checkRow(['patient_no' => $juan->patient_no])->errors)->toBe([])
        ->and(checkRow(['patient_no' => strtolower($juan->patient_no), 'last_name' => 'DELA  CRUZ'])->errors)->toBe([]);
});

it('keeps formula-looking text as plain text', function () {
    $row = checkRow(['remarks' => '=1+1', 'last_name' => '=SUM(A1)']);

    expect($row->errors)->toBe([])
        ->and($row->visitAttributes['remarks'])->toBe('=1+1')
        ->and($row->patientAttributes['last_name'])->toBe('=SUM(A1)');
});

// --- Warnings -----------------------------------------------------------------

it('warns that a row without a Patient ID will be linked to a patient with the same name and birthdate', function () {
    $patient = Patient::factory()->create(['last_name' => 'DELA CRUZ', 'first_name' => 'juan', 'birthdate' => '1990-05-01', 'sex' => 'Male', 'middle_initial' => 'M']);

    $row = checkRow();

    expect($row->status())->toBe('warning')
        ->and($row->errors)->toBe([])
        ->and($row->warnings[0]['code'])->toBe('link')
        ->and($row->warnings[0]['text'])->toContain("Will be linked to {$patient->patient_no}")
        ->and($row->toPage()['can_save_as_new'])->toBeTrue()
        ->and($row->selectedByDefault())->toBeTrue()
        ->and($row->patient->id)->toBe($patient->id);
});

it('does not link when Admin chose to save as a new patient', function () {
    Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);

    $row = checkRows([ImportFile::row()], saveAsNew: [2])[0];

    expect($row->status())->toBe('ok')->and($row->patient)->toBeNull()->and($row->saveAsNew)->toBeTrue();
});

it('does not link a patient in Trash', function () {
    $trashed = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    $trashed->moveToTrash(userWithRole('admin'), 'Duplicate patient record');

    expect(checkRow()->status())->toBe('ok');
});

it('warns of a possible duplicate visit, which is not ticked by default', function () {
    $patient = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    $visit = Visit::factory()->for($patient)->create(['visited_at' => '2026-10-05 08:15:40', 'category' => 'ER', 'rank_id' => $this->lists['cpl']->id]);

    $row = checkRow(['patient_no' => $patient->patient_no]);

    expect($row->status())->toBe('warning')
        ->and($row->hasWarning('duplicate_visit'))->toBeTrue()
        ->and($row->warnings[0]['text'])->toContain('Possible duplicate visit')
        ->and($row->selectedByDefault())->toBeFalse();

    // Another category, another minute, or a visit in Trash is not a duplicate.
    expect(checkRow(['patient_no' => $patient->patient_no, 'category' => 'OPD'])->hasWarning('duplicate_visit'))->toBeFalse()
        ->and(checkRow(['patient_no' => $patient->patient_no, 'visit_time' => '08:16'])->hasWarning('duplicate_visit'))->toBeFalse();

    $visit->moveToTrash(userWithRole('admin'), 'Duplicate entry');

    expect(checkRow(['patient_no' => $patient->patient_no])->hasWarning('duplicate_visit'))->toBeFalse();
});

it('warns when the same patient is in the file twice at the same date and time', function () {
    $rows = checkRows([
        ImportFile::row(),
        ImportFile::row(['category' => 'OPD']),
        ImportFile::row(['visit_time' => '09:00']),
        ImportFile::row(['first_name' => 'Pedro']),
    ]);

    expect($rows[0]->status())->toBe('ok')
        ->and($rows[1]->hasWarning('file_duplicate'))->toBeTrue()
        ->and($rows[1]->warnings[0]['text'])->toBe('Same patient, date and time as row 2. It may be entered twice.')
        ->and($rows[1]->selectedByDefault())->toBeFalse()
        ->and($rows[2]->status())->toBe('ok')
        ->and($rows[3]->status())->toBe('ok');
});

it('does not count an earlier row with an error as a duplicate', function () {
    $rows = checkRows([ImportFile::row(['diagnosis' => 'Flu']), ImportFile::row()]);

    expect($rows[0]->status())->toBe('error')->and($rows[1]->status())->toBe('ok');
});

it('says when a typed Other matches a listed name', function () {
    $row = checkRow(['diagnosis' => 'Other (specify)', 'diagnosis_other' => '  dengue  FEVER ', 'branch' => 'Others', 'rank' => 'Other (specify)', 'rank_other' => 'dependent']);

    expect($row->status())->toBe('warning')
        ->and(collect($row->warnings)->pluck('text')->all())->toBe([
            'Rank / Type “dependent” is on the list. It will count as Dependent in reports.',
            'Diagnosis “dengue FEVER” is on the list. It will count as Dengue Fever in reports.',
        ])
        ->and($row->selectedByDefault())->toBeTrue();
});

it('warns when sex differs from the patient on file, and leaves the patient alone', function () {
    $patient = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01', 'sex' => 'Female']);

    $row = checkRow(['patient_no' => $patient->patient_no]);

    expect($row->hasWarning('differs'))->toBeTrue()
        ->and($patient->fresh()->sex->value)->toBe('Female');
});

it('summarizes OK, warnings and errors', function () {
    Patient::factory()->create(['last_name' => 'Santos', 'first_name' => 'Maria', 'birthdate' => '1975-02-02']);

    $rows = checkRows([
        ImportFile::row(),
        ImportFile::row(['last_name' => 'Santos', 'first_name' => 'Maria', 'birthdate' => '1975-02-02', 'sex' => 'Female']),
        ImportFile::row(['diagnosis' => 'Flu']),
    ]);

    expect(ImportAnalyzer::summary($rows))->toBe(['total' => 3, 'ok' => 1, 'warning' => 1, 'error' => 1]);
});

it('uses the same rules as the Encode form for a rank of the wrong branch', function () {
    // The Encode rule says "Choose a rank from the Army list." The import
    // says it in its own words because it also knows the name typed.
    $row = checkRow(['rank' => 'Dependent']);

    expect($row->errors)->toBe(['Rank / Type “Dependent” is not on the Army list.']);
    expect(Rank::query()->where('name', 'Dependent')->exists())->toBeTrue();
});

it('checks 2,000 rows with a small, fixed number of database queries', function () {
    $patient = Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    Visit::factory()->for($patient)->create(['visited_at' => '2026-10-05 08:15:00', 'category' => 'ER']);

    $rows = [];

    foreach (range(1, 2000) as $i) {
        $rows[] = match (true) {
            $i % 4 === 0 => ImportFile::row(['patient_no' => $patient->patient_no]),
            $i % 4 === 1 => ImportFile::row(),
            default => ImportFile::row(['last_name' => "Person{$i}", 'first_name' => 'Test', 'birthdate' => '1980-01-'.sprintf('%02d', ($i % 27) + 1)]),
        };
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $results = checkRows($rows);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($results)->toHaveCount(2000)
        ->and($queries)->toBeLessThan(50)
        // The checks still work: the matches and duplicates were found from the preloaded data.
        ->and($results[3]->hasWarning(ImportRowResult::DUPLICATE_VISIT))->toBeTrue()
        ->and($results[0]->hasWarning(ImportRowResult::LINK))->toBeTrue()
        ->and($results[1]->status())->toBe('ok');
});

it('ignores Save as a new patient for a row without a name and birthdate match', function () {
    $alone = checkRows([ImportFile::row(['last_name' => 'Nobody'])], [2])[0];

    expect($alone->saveAsNew)->toBeFalse()
        ->and($alone->status())->toBe('ok');

    Patient::factory()->create(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'birthdate' => '1990-05-01']);
    $matched = checkRows([ImportFile::row()], [2])[0];
    $other = checkRows([ImportFile::row(['last_name' => 'Nobody'])], [2])[0];

    expect($matched->saveAsNew)->toBeTrue()
        ->and($matched->patient)->toBeNull()
        ->and($other->saveAsNew)->toBeFalse();
});
