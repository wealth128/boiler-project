<?php

namespace App\Services\Import;

use App\Enums\Permission;
use App\Enums\VisitDeleteReason;
use App\Http\Controllers\Encode\VisitController;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\ImportBatch;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\MonthService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves a checked import file (Step 6j) and undoes it.
 *
 * Confirm: the rows are checked again from the file (the browser only says
 * which row numbers to import), then everything is saved in ONE transaction:
 * either all chosen rows are saved or nothing is. New patients take the same
 * lock as Encode's duplicate guard, so a person typed at the same moment on
 * the Encode screen is not saved twice.
 *
 * Safety, in order:
 * - The file is CLAIMED first (ImportFileStore::claim, an atomic rename) under
 *   the lock "import-confirm:<token>": a second confirm of the same file
 *   finds it taken and saves nothing ("This file was already imported.").
 * - The month locks ("month-close:YYYY", the ones closing a month takes) are
 *   held for every year of the chosen rows, and the closed months are read
 *   again inside the transaction, so a month closed while confirming can't
 *   receive visits.
 * - Branch, rank and diagnosis are read again inside the transaction (locked);
 *   one that went to Trash or was turned off since the preview refuses the import.
 * - A row that has a NEW "possible duplicate visit" warning (it was not in the
 *   preview Admin saw) is not imported and is listed in the result.
 *
 * Audit log: one "import.created" entry with the counts and the file name (no
 * patient data), and the usual "patient.created" / "visit.created" entries
 * from AuditObserver for each record.
 *
 * Undo: every visit of the batch goes to Trash (reason "Wrong import"), and
 * so do the patients only this batch created, unless they have other visits.
 * Refused while any of its visits is in a closed month.
 */
class ImportService
{
    public function __construct(private AuditLogger $audit, private ImportSheetReader $reader, private ImportFileStore $store) {}

    /**
     * Read and check a staged file.
     *
     * @param  list<int>  $saveAsNewRows
     * @return list<ImportRowResult>
     *
     * @throws ImportFileException
     */
    public function analyze(StagedImport $file, array $saveAsNewRows = []): array
    {
        return $this->analyzeAt($file->relativePath(), $file->extension, $saveAsNewRows);
    }

    /**
     * @param  list<int>  $saveAsNewRows
     * @return list<ImportRowResult>
     *
     * @throws ImportFileException
     */
    private function analyzeAt(string $relativePath, string $extension, array $saveAsNewRows = []): array
    {
        $rows = $this->reader->read($relativePath, $extension)['rows'];

        return (new ImportAnalyzer)->analyze($rows, $saveAsNewRows);
    }

    /**
     * What the Import page shows for a waiting file: the summary and every
     * row. The checked result is kept beside the file, so paging and reloads
     * don't read and check the whole file again. It is checked again when
     * something it depends on changed (closed months, the lists, patients,
     * visits), and always at confirm.
     *
     * @return array{signature: string, summary: array<string, int>, rows: list<array<string, mixed>>, duplicate_rows: list<int>}
     *
     * @throws ImportFileException
     */
    public function preview(StagedImport $file): array
    {
        $signature = $this->stateSignature();
        $cached = $this->store->analysis($file);

        if ($cached !== null && ($cached['signature'] ?? null) === $signature) {
            return $cached;
        }

        $results = $this->analyze($file);

        $preview = [
            'signature' => $signature,
            'summary' => ImportAnalyzer::summary($results),
            'rows' => array_map(fn (ImportRowResult $result): array => $result->toPage(), $results),
            'duplicate_rows' => array_values(array_map(
                fn (ImportRowResult $result): int => $result->number,
                array_filter($results, fn (ImportRowResult $result): bool => $result->hasWarning(ImportRowResult::DUPLICATE_VISIT)),
            )),
        ];

        $this->store->putAnalysis($file, $preview);

        return $preview;
    }

    /**
     * Changes whenever something a row's check depends on changes: closed
     * months, branches, ranks, diagnoses, patients, visits.
     */
    private function stateSignature(): string
    {
        return sha1((string) json_encode([
            MonthClosure::query()->where('is_closed', true)->orderBy('period')->pluck('period'),
            // The lists have no timestamps and are small: what the checks read from them.
            Branch::query()->get(['id', 'name', 'is_active'])->toArray(),
            Rank::withTrashed()->get(['id', 'branch_id', 'name', 'is_other', 'deleted_at'])->toArray(),
            Diagnosis::withTrashed()->get(['id', 'name', 'is_other', 'deleted_at'])->toArray(),
            Patient::withTrashed()->max('updated_at'),
            Visit::withTrashed()->max('id'),
            Visit::withTrashed()->max('updated_at'),
        ]));
    }

    /**
     * Import the chosen rows.
     *
     * @param  list<int>  $selected  File row numbers Admin chose
     * @param  list<int>  $saveAsNewRows  Rows to save as a new patient instead of linking
     *
     * @throws ValidationException With a plain-words message on `import` when nothing was saved
     * @throws ImportFileException
     */
    public function confirm(User $by, StagedImport $file, array $selected, array $saveAsNewRows): ImportOutcome
    {
        Gate::forUser($by)->authorize(Permission::ImportVisits->value);

        $selected = array_values(array_unique($selected));
        sort($selected);

        if ($selected === []) {
            throw ValidationException::withMessages(['import' => 'Tick at least one row to import.']);
        }

        // One confirm at a time for a file, and only the first one gets it.
        $claim = Cache::lock('import-confirm:'.$file->token, 600);

        if (! $claim->get()) {
            throw self::alreadyImported();
        }

        $taken = false;
        $done = false;

        try {
            if (! $this->store->claim($file)) {
                throw self::alreadyImported();
            }

            $taken = true;

            $outcome = $this->confirmClaimed($by, $file, $selected, $saveAsNewRows);

            if ($outcome->batch === null) {
                // Nothing was saved: the file stays, and the page checks it again.
                $this->store->forgetAnalysis($file);

                return $outcome;
            }

            $this->store->finish($file);
            $done = true;

            return $outcome;
        } finally {
            if ($taken && ! $done) {
                $this->store->unclaim($file);
            }

            $claim->release();
        }
    }

    private static function alreadyImported(): ValidationException
    {
        return ValidationException::withMessages(['import' => 'This file was already imported.']);
    }

    /**
     * @param  list<int>  $selected
     * @param  list<int>  $saveAsNewRows
     */
    private function confirmClaimed(User $by, StagedImport $file, array $selected, array $saveAsNewRows): ImportOutcome
    {
        $previewDuplicates = $this->store->analysis($file)['duplicate_rows'] ?? null;
        $results = $this->analyzeAt($file->claimedPath(), $file->extension, $saveAsNewRows);
        $byNumber = [];

        foreach ($results as $result) {
            $byNumber[$result->number] = $result;
        }

        foreach ($selected as $number) {
            $row = $byNumber[$number] ?? null;

            if ($row === null) {
                throw ValidationException::withMessages(['import' => "Row {$number} is not in the file. Upload the file again."]);
            }

            if ($row->isError()) {
                throw ValidationException::withMessages(['import' => "Row {$number} has an error and can't be imported. Nothing was imported. Check the preview again."]);
            }
        }

        $chosen = [];
        $heldBack = [];

        foreach ($selected as $number) {
            $row = $byNumber[$number];

            // A duplicate that Admin never saw in the preview (the same visit was saved in the
            // meantime) is not imported. One that was in the preview was ticked on purpose.
            if ($previewDuplicates !== null && $row->hasWarning(ImportRowResult::DUPLICATE_VISIT) && ! in_array($number, $previewDuplicates, true)) {
                $heldBack[] = $number;

                continue;
            }

            $chosen[] = $row;
        }

        if ($chosen === []) {
            return new ImportOutcome(null, $heldBack);
        }

        $years = array_map(fn (ImportRowResult $row): string => $row->visitedAt->format('Y'), $chosen);
        $monthLocks = MonthService::lockYears($years);
        $patientLocks = [];

        try {
            $patientLocks = $this->lockNewPatients($chosen);
            $batch = $this->save($by, $file, $chosen, count($results));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Log the kind of failure only: the message of a database error carries the row's values.
            report(new \RuntimeException('Import failed: '.$e::class.' ('.$e->getCode().')'));

            throw ValidationException::withMessages(['import' => 'The import could not be saved, so nothing was imported. Try again. If it keeps happening, tell IT.']);
        } finally {
            foreach ($patientLocks as $lock) {
                $lock->release();
            }

            MonthService::releaseLocks($monthLocks);
        }

        return new ImportOutcome($batch, $heldBack);
    }

    /**
     * @param  list<ImportRowResult>  $chosen
     */
    private function save(User $by, StagedImport $file, array $chosen, int $originalRows): ImportBatch
    {
        return DB::transaction(function () use ($by, $file, $chosen, $originalRows): ImportBatch {
            // Read again now that the month locks are held: no month can be closed from here on.
            $this->refuseClosedMonths($chosen);
            $this->refuseChangedLists($chosen);

            $batch = ImportBatch::create([
                'file_name' => $file->name,
                'original_rows' => $originalRows,
                'imported_count' => count($chosen),
                'skipped_count' => $originalRows - count($chosen),
                'imported_by' => $by->id,
                'created_at' => now(),
            ]);

            $newPatients = 0;

            foreach ($chosen as $row) {
                [$patient, $isNew] = $this->patientFor($row, $batch);
                $newPatients += $isNew ? 1 : 0;

                Visit::create([
                    ...$row->visitAttributes ?? [],
                    'patient_id' => $patient->id,
                    'visited_at' => $row->visitedAt,
                    'written_by' => $row->writtenBy,
                    'import_batch_id' => $batch->id,
                    'encoded_by' => $by->id,
                ]);
            }

            $this->audit->log('import.created', $batch, [
                'file_name' => $batch->file_name,
                'original_rows' => $originalRows,
                'imported' => count($chosen),
                'skipped' => $originalRows - count($chosen),
                'new_patients' => $newPatients,
                'linked_patients' => count($chosen) - $newPatients,
            ], by: $by);

            return $batch;
        });
    }

    /**
     * A month closed after the preview (even a moment ago) refuses the whole import.
     *
     * @param  list<ImportRowResult>  $chosen
     */
    private function refuseClosedMonths(array $chosen): void
    {
        $periods = array_values(array_unique(array_map(fn (ImportRowResult $row): string => $row->visitedAt->format('Y-m'), $chosen)));

        $closed = MonthClosure::query()->where('is_closed', true)->whereIn('period', $periods)->orderBy('period')->value('period');

        if ($closed !== null) {
            $label = Carbon::parse($closed.'-01')->format('F Y');

            throw ValidationException::withMessages(['import' => "{$label} is closed. Reopen {$label} first. Nothing was imported."]);
        }
    }

    /**
     * Branch, rank and diagnosis are read again inside the transaction, locked:
     * one that went to Trash or was turned off since the preview refuses the import.
     *
     * @param  list<ImportRowResult>  $chosen
     */
    private function refuseChangedLists(array $chosen): void
    {
        $ids = fn (string $key): array => array_values(array_unique(array_map(fn (ImportRowResult $row): int => (int) $row->visitAttributes[$key], $chosen)));

        $branches = Branch::query()->whereIn('id', $ids('branch_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $ranks = Rank::query()->whereIn('id', $ids('rank_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $diagnoses = Diagnosis::query()->whereIn('id', $ids('diagnosis_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($chosen as $row) {
            $attributes = $row->visitAttributes;
            $branch = $branches->get($attributes['branch_id']);
            $rank = $ranks->get($attributes['rank_id']);
            $diagnosis = $diagnoses->get($attributes['diagnosis_id']);

            $problem = match (true) {
                $branch === null || ! $branch->is_active => 'the branch was turned off or removed',
                $rank === null || $rank->branch_id !== $branch->id => 'the rank / type was removed from the list',
                $diagnosis === null => 'the diagnosis was removed from the list',
                default => null,
            };

            if ($problem !== null) {
                throw ValidationException::withMessages(['import' => "Row {$row->number}: {$problem} after the preview. Nothing was imported. Check the preview again."]);
            }
        }
    }

    /**
     * The patient for a row, checked again inside the transaction: the one
     * named by its Patient ID, the one that matches by name and birthdate,
     * or a new one.
     *
     * @return array{0: Patient, 1: bool} The patient and whether it was just created
     */
    private function patientFor(ImportRowResult $row, ImportBatch $batch): array
    {
        /** @var array<string, mixed> $who */
        $who = $row->patientAttributes;
        $last = (string) $who['last_name'];
        $first = (string) $who['first_name'];
        $birthdate = (string) $who['birthdate'];

        if ($row->byPatientNo) {
            $patient = Patient::query()->lockForUpdate()->find($row->patient?->id);

            if ($patient === null || ! ImportAnalyzer::samePerson($patient, $last, $first, $birthdate)) {
                throw ValidationException::withMessages(['import' => "Row {$row->number}: the patient was changed or removed after the preview. Nothing was imported. Check the preview again."]);
            }

            return [$patient, false];
        }

        if (! $row->saveAsNew) {
            $patient = Patient::query()
                ->where('last_name', $last)
                ->where('first_name', $first)
                ->whereDate('birthdate', $birthdate)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($patient !== null) {
                return [$patient, false];
            }
        }

        return [Patient::create([...$who, 'import_batch_id' => $batch->id]), true];
    }

    /**
     * The "new patient" lock of Encode for every row that may create a patient
     * from a name and birthdate (not rows with a Patient ID, nor rows Admin
     * chose to save as new: Encode does not lock those either). Taken in key
     * order, so two imports can't wait on each other.
     *
     * @param  list<ImportRowResult>  $chosen
     * @return list<Lock>
     */
    private function lockNewPatients(array $chosen): array
    {
        $keys = [];

        foreach ($chosen as $row) {
            if ($row->byPatientNo || $row->saveAsNew || $row->patientAttributes === null) {
                continue;
            }

            $who = $row->patientAttributes;
            $keys[] = VisitController::newPatientLockKey((string) $who['last_name'], (string) $who['first_name'], (string) $who['birthdate']);
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        $locks = [];

        try {
            foreach ($keys as $key) {
                $lock = Cache::lock($key, 300);
                $lock->block((int) config('census.lock_wait_seconds'));
                $locks[] = $lock;
            }
        } catch (LockTimeoutException) {
            foreach ($locks as $lock) {
                $lock->release();
            }

            throw ValidationException::withMessages(['import' => 'Another save of the same patient is running. Wait a moment and try again.']);
        }

        return $locks;
    }

    /**
     * Move a whole batch to Trash.
     *
     * @return array{visits: int, patients: int}
     *
     * @throws ValidationException
     */
    public function undo(User $by, ImportBatch $batch): array
    {
        Gate::forUser($by)->authorize(Permission::ImportVisits->value);

        // The same year locks as closing a month, so a month can't be closed in the middle of the undo.
        $years = Visit::query()->where('import_batch_id', $batch->id)->get(['visited_at'])
            ->map(fn (Visit $visit): string => $visit->visited_at->format('Y'))
            ->all();
        $locks = MonthService::lockYears($years);

        try {
            return $this->undoLocked($by, $batch);
        } finally {
            MonthService::releaseLocks($locks);
        }
    }

    /**
     * @return array{visits: int, patients: int}
     */
    private function undoLocked(User $by, ImportBatch $batch): array
    {
        return DB::transaction(function () use ($by, $batch): array {
            /** @var ImportBatch $batch */
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->isUndone()) {
                throw ValidationException::withMessages(['import' => 'This import was already undone.']);
            }

            $visits = Visit::query()->where('import_batch_id', $batch->id)->orderBy('id')->lockForUpdate()->get();

            $closed = MonthClosure::query()->where('is_closed', true)->pluck('period')->flip();
            $month = $visits->map(fn (Visit $visit): string => $visit->period)->unique()->sort()->first(fn (string $period): bool => $closed->has($period));

            if ($month !== null) {
                $label = Carbon::parse($month.'-01')->format('F Y');

                throw ValidationException::withMessages(['import' => "{$label} is closed. Reopen {$label} first, then undo this import."]);
            }

            // Lock the patients this batch created before looking at their other visits.
            $created = Patient::query()->where('import_batch_id', $batch->id)->orderBy('id')->lockForUpdate()->get();

            foreach ($visits as $visit) {
                $visit->moveToTrash($by, VisitDeleteReason::WrongImport->value);
            }

            $patients = 0;

            // Same reason text as the visits. One with other visits (added later, or by
            // another import) stays: a patient can only go to Trash with no visits.
            foreach ($created as $patient) {
                if (! $patient->visits()->exists()) {
                    $patient->moveToTrash($by, VisitDeleteReason::WrongImport->value);
                    $patients++;
                }
            }

            $batch->forceFill(['undone_at' => now(), 'undone_by' => $by->id])->save();

            $this->audit->log('import.undone', $batch, [
                'file_name' => $batch->file_name,
                'visits_moved_to_trash' => $visits->count(),
                'patients_moved_to_trash' => $patients,
            ], by: $by);

            return ['visits' => $visits->count(), 'patients' => $patients];
        });
    }
}
