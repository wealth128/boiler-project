<?php

namespace App\Services\Import;

use App\Enums\Category;
use App\Enums\Sex;
use App\Http\Controllers\Encode\VisitController;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Visit;
use App\Services\ListItemRules;
use App\Services\VisitFields;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Checks every row of an import file and says, in plain words, whether it
 * is OK, has a Warning (Admin decides) or an Error (can't be imported).
 * Nothing is saved here: the preview, and the import itself, both call
 * analyze() on the file's rows.
 *
 * The visit's own fields are checked by App\Services\VisitFields, the same
 * rules the Encode form uses. This class adds what only a file needs:
 * reading dates and times, looking list names up, the date rules (not in
 * the future, not before 2026, not in a closed month), the age, and the
 * patient (Patient ID, or a match by name and birthdate) and duplicates.
 *
 * Create a new analyzer for every call: it remembers the rows it has seen.
 *
 * Speed: the lists, the patients named in the file and the visits that could
 * be duplicates are each read from the database ONCE for the whole file
 * (load()), not once per row, so 2,000 rows take a handful of queries.
 */
class ImportAnalyzer
{
    /** Validator field => the column the Admin sees in the file. */
    private const FIELD_COLUMNS = [
        'last_name' => 'Last Name',
        'first_name' => 'First Name',
        'middle_initial' => 'M.I.',
        'sex' => 'Sex',
        'birthdate' => 'Birthdate',
        'age' => 'Age',
        'branch_id' => 'Branch',
        'rank_id' => 'Rank / Type',
        'rank_other' => 'Rank Other (specify)',
        'diagnosis_id' => 'Diagnosis',
        'diagnosis_other' => 'Diagnosis Other (specify)',
        'category' => 'Category',
        'remarks' => 'Remarks',
    ];

    /** @var array<string, Branch> */
    private array $branches = [];

    /** @var array<int, array<string, Rank>> branch id => name key => rank */
    private array $ranks = [];

    /** @var array<string, Diagnosis> */
    private array $diagnoses = [];

    /** @var array<string, true> Closed months, e.g. "2026-09" */
    private array $closed = [];

    /** @var array<string, Patient> Patient ID => patient (including ones in Trash) */
    private array $patientsByNo = [];

    /** @var array<string, int> "who|date time" => the first row with it */
    private array $seen = [];

    /** @var array<string, Patient> Lock key of name + birthdate => the oldest active patient with it */
    private array $identity = [];

    /** @var array<string, true> "patient id|category|YYYY-MM-DD HH:MM" of the visits already saved */
    private array $existingVisits = [];

    /** @var array{branches: array<int, Branch>, ranks: array<int, Rank>, diagnoses: array<int, Diagnosis>} Every list item by id, Trash included */
    private array $lists = ['branches' => [], 'ranks' => [], 'diagnoses' => []];

    /**
     * @param  array<int, array<string, mixed>>  $rows  File row number => values (ImportSheetReader)
     * @param  list<int>  $saveAsNewRows  Rows where Admin chose "save as a new patient instead"
     * @return list<ImportRowResult>
     */
    public function analyze(array $rows, array $saveAsNewRows = []): array
    {
        $this->load($rows);

        $results = [];

        foreach ($rows as $number => $raw) {
            $results[] = $this->row($number, $raw, in_array($number, $saveAsNewRows, true));
        }

        return $results;
    }

    /**
     * "Ok, warning, error" counts for the summary line.
     *
     * @param  list<ImportRowResult>  $results
     * @return array{total: int, ok: int, warning: int, error: int}
     */
    public static function summary(array $results): array
    {
        $count = fn (string $status): int => count(array_filter($results, fn (ImportRowResult $r): bool => $r->status() === $status));

        return [
            'total' => count($results),
            'ok' => $count(ImportRowResult::OK),
            'warning' => $count(ImportRowResult::WARNING),
            'error' => $count(ImportRowResult::ERROR),
        ];
    }

    /**
     * A name as the database compares it: no accents, no capitals, one space
     * between words. "Peña", "PEÑA" and "Pena" are the same name.
     */
    public static function foldName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->value();
    }

    // --- Lookups, loaded once ------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function load(array $rows): void
    {
        foreach (Branch::query()->get() as $branch) {
            $this->branches[self::listKey($branch->name)] = $branch;
            $this->lists['branches'][$branch->id] = $branch;
        }

        // Items in Trash first, so an active item of the same name replaces them.
        foreach (Rank::withTrashed()->get()->sortByDesc(fn (Rank $rank): bool => $rank->trashed()) as $rank) {
            $this->ranks[$rank->branch_id][self::listKey($rank->name)] = $rank;
            $this->lists['ranks'][$rank->id] = $rank;
        }

        foreach (Diagnosis::withTrashed()->get()->sortByDesc(fn (Diagnosis $d): bool => $d->trashed()) as $diagnosis) {
            $this->diagnoses[self::listKey($diagnosis->name)] = $diagnosis;
            $this->lists['diagnoses'][$diagnosis->id] = $diagnosis;
        }

        $this->closed = MonthClosure::query()->where('is_closed', true)->pluck('period')->flip()->map(fn () => true)->all();

        $numbers = Collection::make($rows)
            ->map(fn (array $raw): ?string => self::patientNo($this->text($raw['patient_no'] ?? null)))
            ->filter()
            ->unique()
            ->values();

        $this->patientsByNo = Patient::withTrashed()->whereIn('patient_no', $numbers)->get()->keyBy('patient_no')->all();

        $this->loadIdentities($rows);
        $this->loadExistingVisits($rows);
    }

    /**
     * The patients that match the rows without a Patient ID by name and
     * birthdate: one query for the whole file. The database narrows the
     * candidates (case and accents ignored, like Encode's duplicate guard);
     * the exact match is then made here with the same folding as the lock
     * key. The oldest patient wins, as in the one-by-one lookup.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function loadIdentities(array $rows): void
    {
        $wanted = [];
        $lasts = [];
        $firsts = [];
        $births = [];

        foreach ($rows as $raw) {
            if ($this->text($raw['patient_no'] ?? null) !== '') {
                continue;
            }

            $last = $this->line($raw['last_name'] ?? null);
            $first = $this->line($raw['first_name'] ?? null);
            $birth = $this->quietDate($raw['birthdate'] ?? null);

            if ($last === '' || $first === '' || $birth === null) {
                continue;
            }

            $wanted[VisitController::newPatientLockKey($last, $first, $birth)] = true;
            $lasts[$last] = true;
            $firsts[$first] = true;
            $births[$birth] = true;
        }

        if ($wanted === []) {
            return;
        }

        $patients = Patient::query()
            ->whereIn('last_name', array_map('strval', array_keys($lasts)))
            ->whereIn('first_name', array_map('strval', array_keys($firsts)))
            ->whereIn('birthdate', array_keys($births))
            ->orderBy('id')
            ->get();

        foreach ($patients as $patient) {
            $key = VisitController::newPatientLockKey($patient->last_name, $patient->first_name, $patient->birthdate->format('Y-m-d'));

            if (isset($wanted[$key]) && ! isset($this->identity[$key])) {
                $this->identity[$key] = $patient;
            }
        }
    }

    /**
     * The visits already saved for the patients the file names (by Patient ID
     * or by name), within the dates of the file: one query, for the "possible
     * duplicate visit" warning.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function loadExistingVisits(array $rows): void
    {
        $ids = [];

        foreach ($this->identity as $patient) {
            $ids[$patient->id] = true;
        }

        foreach ($this->patientsByNo as $patient) {
            if (! $patient->trashed()) {
                $ids[$patient->id] = true;
            }
        }

        $dates = array_values(array_filter(array_map(fn (array $raw): ?string => $this->quietDate($raw['visit_date'] ?? null), $rows)));

        if ($ids === [] || $dates === []) {
            return;
        }

        $from = min($dates).' 00:00:00';
        $to = CarbonImmutable::parse(max($dates))->addDay()->format('Y-m-d').' 00:00:00';

        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            // toBase(): plain rows (Visit's Trash filter is still applied), no models to build.
            $visits = Visit::query()
                ->whereIn('patient_id', $chunk)
                ->where('visited_at', '>=', $from)
                ->where('visited_at', '<', $to)
                ->toBase()
                ->get(['patient_id', 'category', 'visited_at']);

            foreach ($visits as $visit) {
                $this->existingVisits[$visit->patient_id.'|'.$visit->category.'|'.substr((string) $visit->visited_at, 0, 16)] = true;
            }
        }
    }

    /** A date cell as YYYY-MM-DD for the preloading, or null (the row's own check says why). */
    private function quietDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = $this->text($value);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $part) === 1 && checkdate((int) $part[2], (int) $part[3], (int) $part[1]) ? $text : null;
    }

    private static function listKey(string $name): string
    {
        return mb_strtolower(ListItemRules::normalizeName($name));
    }

    /**
     * "P-000012" (also "p-12" or "P12") as the stored Patient ID, or null
     * when it does not look like one.
     */
    private static function patientNo(string $text): ?string
    {
        if (preg_match('/^P-?0*(\d{1,9})$/i', $text, $match) !== 1) {
            return null;
        }

        return Patient::formatNumber((int) $match[1]);
    }

    // --- One row -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     */
    private function row(int $number, array $raw, bool $saveAsNew): ImportRowResult
    {
        $r = new ImportRowResult($number);
        $t = fn (string $key): string => $this->text($raw[$key] ?? null);

        // --- When ---
        $date = $this->dateOf($raw['visit_date'] ?? null, 'visit_date', $r);
        $time = $this->timeOf($raw['visit_time'] ?? null, $r);
        $birthdate = $this->dateOf($raw['birthdate'] ?? null, 'birthdate', $r);
        $visitedAt = $this->checkWhen($date, $time, $r);

        $age = $t('age');
        $ownKeys = [];

        if ($age === '') {
            // Blank: the age on the visit date.
            if ($birthdate !== null && $date !== null && $birthdate <= $date) {
                $age = (string) (int) CarbonImmutable::parse($birthdate)->diffInYears(CarbonImmutable::parse($date));
            } else {
                $ownKeys[] = 'age';
            }
        }

        if ($birthdate !== null && $date !== null && $birthdate > $date) {
            $r->addError('Birthdate is after the Visit Date.');
        }

        // --- Who and what ---
        $last = $this->line($raw['last_name'] ?? null);
        $first = $this->line($raw['first_name'] ?? null);
        $this->required($r, 'last_name', $last, $ownKeys, 'last_name');
        $this->required($r, 'first_name', $first, $ownKeys, 'first_name');

        $sexText = $this->line($raw['sex'] ?? null);
        $sex = $this->enumValue(Sex::cases(), $sexText);
        $this->required($r, 'sex', $sexText, $ownKeys, 'sex');

        $categoryText = $this->line($raw['category'] ?? null);
        $category = $this->enumValue(Category::cases(), $categoryText);
        $this->required($r, 'category', $categoryText, $ownKeys, 'category');

        if ($birthdate === null) {
            $ownKeys[] = 'birthdate';
        }

        [$branch, $rank, $diagnosis] = $this->listItems($raw, $r, $ownKeys);

        // Typed "Other" text: only for "Other (specify)", and needed there.
        $rankOther = $this->line($raw['rank_other'] ?? null);
        $diagnosisOther = $this->line($raw['diagnosis_other'] ?? null);
        $this->otherText($r, 'rank_other', $rank?->is_other, $rankOther, 'Rank / Type', $ownKeys);
        $this->otherText($r, 'diagnosis_other', $diagnosis?->is_other, $diagnosisOther, 'Diagnosis', $ownKeys);

        $writtenBy = $this->line($raw['written_by'] ?? null);

        if ($writtenBy === '') {
            $r->addError('Written by is missing. Write who filled in this row.');
        } elseif (mb_strlen($writtenBy) > 60) {
            $r->addError('Written by: Use at most 60 characters.');
        }

        // --- The same field rules as the Encode form ---
        $input = VisitFields::normalize([
            'last_name' => $last,
            'first_name' => $first,
            'middle_initial' => $this->line($raw['middle_initial'] ?? null),
            'sex' => $sex,
            'birthdate' => $birthdate,
            'age' => $age === '' ? null : $age,
            'branch_id' => $branch?->id,
            'rank_id' => $rank?->id,
            'rank_other' => $rank?->is_other ? $rankOther : null,
            'diagnosis_id' => $diagnosis?->id,
            'diagnosis_other' => $diagnosis?->is_other ? $diagnosisOther : null,
            'category' => $category,
            'remarks' => $t('remarks') === '' ? null : $t('remarks'),
        ]);

        $fields = new VisitFields($input, null, $this->lists);
        $validator = Validator::make($input, $fields->rules(), $fields->messages());
        $validated = [];

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                if (in_array($field, $ownKeys, true)) {
                    continue; // already said in the row's own words
                }

                $r->addError((self::FIELD_COLUMNS[$field] ?? $field).': '.$messages[0]);
            }
        } else {
            $validated = $validator->validated();
        }

        // --- The patient ---
        $identityOk = $last !== '' && $first !== '' && $birthdate !== null && ! $validator->errors()->hasAny(['last_name', 'first_name', 'birthdate']);

        if ($identityOk) {
            $this->patientFor($r, $raw, $last, $first, $birthdate, $saveAsNew);
        }

        // --- What the preview shows ---
        $mi = $input['middle_initial'];
        $r->display = [
            'date' => $date ?? $t('visit_date'),
            'time' => $time ?? $t('visit_time'),
            'patient_no' => $t('patient_no'),
            'name' => trim($last.($first !== '' ? ', '.$first : '').($mi ? ' '.$mi.'.' : '')),
            'branch_rank' => trim(($branch?->name ?? $this->line($raw['branch'] ?? null)).' · '.($rank?->name ?? $this->line($raw['rank'] ?? null)).($rank?->is_other && $rankOther !== '' ? " ({$rankOther})" : ''), ' ·'),
            'diagnosis' => ($diagnosis?->name ?? $this->line($raw['diagnosis'] ?? null)).($diagnosis?->is_other && $diagnosisOther !== '' ? ": {$diagnosisOther}" : ''),
            'category' => $category ?? $categoryText,
            'written_by' => $writtenBy,
        ];

        if ($r->isError()) {
            $r->patient = null;

            return $r;
        }

        // --- Warnings (only for a row with no errors) ---
        $r->visitedAt = $visitedAt;
        $r->visitAttributes = $fields->visitAttributes($validated);
        $r->patientAttributes = [
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'middle_initial' => $validated['middle_initial'] ?? null,
            'sex' => $validated['sex'],
            'birthdate' => $validated['birthdate'],
        ];
        $r->writtenBy = $writtenBy;

        $this->warnings($r, $rank, $diagnosis, $rankOther, $diagnosisOther);

        return $r;
    }

    // --- Dates and times -------------------------------------------------------

    /**
     * A date cell as YYYY-MM-DD, or null with an error on the row. Accepts
     * text like 2026-11-03 and a real Excel date.
     */
    private function dateOf(mixed $value, string $key, ImportRowResult $r): ?string
    {
        $label = ImportColumns::label($key);

        if ($this->blank($value)) {
            $r->addError("{$label} is missing.");

            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = $this->text($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $part) === 1 && checkdate((int) $part[2], (int) $part[3], (int) $part[1])) {
            return $text;
        }

        $r->addError("{$label} “{$this->short($text)}” is not a date. Write it as YYYY-MM-DD, like 2026-11-03.");

        return null;
    }

    /**
     * A time cell as HH:MM (24-hour), or null with an error on the row.
     * Accepts text like 14:05 (also 8:15 and 14:05:00) and a real Excel time.
     */
    private function timeOf(mixed $value, ImportRowResult $r): ?string
    {
        if ($this->blank($value)) {
            $r->addError('Visit Time is missing.');

            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        $text = $this->text($value);

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d(\.\d+)?)?$/', $text, $part) === 1) {
            return sprintf('%02d:%s', (int) $part[1], $part[2]);
        }

        $r->addError("Visit Time “{$this->short($text)}” is not a time. Write it as 24-hour HH:MM, like 14:05.");

        return null;
    }

    /**
     * The visit's date and time as one moment (Asia/Manila), after the rules
     * for when a visit may be imported: not in the future, not before
     * January 2026, not in a closed month.
     */
    private function checkWhen(?string $date, ?string $time, ImportRowResult $r): ?CarbonImmutable
    {
        if ($date === null || $time === null) {
            return null;
        }

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", (string) config('app.timezone'));

        if ($at === false) {
            return null;
        }

        if ($date < ImportColumns::EARLIEST_DATE) {
            $r->addError('Visit Date is before January 2026. Older visits are not imported.');

            return $at;
        }

        if ($at->greaterThan(now())) {
            $r->addError($date > now()->format('Y-m-d') ? 'Visit Date is in the future.' : 'Visit Time is in the future.');

            return $at;
        }

        $period = $at->format('Y-m');

        if (isset($this->closed[$period])) {
            $month = $at->format('F Y');
            $r->addError("{$month} is closed. Reopen {$month} first.");
        }

        return $at;
    }

    // --- List items ------------------------------------------------------------

    /**
     * Branch, rank and diagnosis by the names in the file. A name that is not
     * on the list is an error here. A branch turned off, a rank or
     * diagnosis in Trash, or a rank of another branch is found by the field
     * rules afterwards, with their usual message.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $ownKeys  Fields whose problem is already on the row
     * @return array{0: Branch|null, 1: Rank|null, 2: Diagnosis|null}
     */
    private function listItems(array $raw, ImportRowResult $r, array &$ownKeys): array
    {
        $branchText = $this->line($raw['branch'] ?? null);
        $rankText = $this->line($raw['rank'] ?? null);
        $diagnosisText = $this->line($raw['diagnosis'] ?? null);

        $branch = null;
        $rank = null;
        $diagnosis = null;

        if ($branchText === '') {
            $r->addError('Branch is missing.');
            $ownKeys[] = 'branch_id';
        } elseif (($branch = $this->branches[self::listKey($branchText)] ?? null) === null) {
            $r->addError("Branch “{$this->short($branchText)}” is not on the list.");
            $ownKeys[] = 'branch_id';
        }

        if ($rankText === '') {
            $r->addError('Rank / Type is missing.');
            $ownKeys[] = 'rank_id';
        } elseif ($branch !== null) {
            $rank = $this->ranks[$branch->id][self::listKey($rankText)] ?? null;

            if ($rank === null) {
                $r->addError("Rank / Type “{$this->short($rankText)}” is not on the {$branch->name} list.");
                $ownKeys[] = 'rank_id';
            }
        } else {
            $ownKeys[] = 'rank_id'; // no branch to look it up in
        }

        if ($diagnosisText === '') {
            $r->addError('Diagnosis is missing.');
            $ownKeys[] = 'diagnosis_id';
        } elseif (($diagnosis = $this->diagnoses[self::listKey($diagnosisText)] ?? null) === null) {
            $r->addError("Diagnosis “{$this->short($diagnosisText)}” is not on the list.");
            $ownKeys[] = 'diagnosis_id';
        }

        return [$branch, $rank, $diagnosis];
    }

    /**
     * "Other (specify)" text: required with the Other choice, and refused
     * with any other choice.
     *
     * @param  list<string>  $ownKeys
     */
    private function otherText(ImportRowResult $r, string $field, ?bool $isOther, string $text, string $choiceColumn, array &$ownKeys): void
    {
        $column = self::FIELD_COLUMNS[$field];

        if ($isOther === true && $text === '') {
            $r->addError("{$column} is missing. It is needed when {$choiceColumn} is Other (specify).");
            $ownKeys[] = $field;
        } elseif ($isOther === false && $text !== '') {
            $r->addError("{$column} is only for “Other (specify)”. Clear it, or choose Other (specify) in {$choiceColumn}.");
            $ownKeys[] = $field;
        }
    }

    /**
     * @param  list<string>  $ownKeys
     */
    private function required(ImportRowResult $r, string $key, string $value, array &$ownKeys, string $field): void
    {
        if ($value === '') {
            $r->addError(ImportColumns::label($key).' is missing.');
            $ownKeys[] = $field;
        }
    }

    // --- The patient -----------------------------------------------------------

    /**
     * Who the visit is for: the patient named by the Patient ID (which must
     * be the same person), or the one that matches by name and birthdate
     * (a warning, so Admin can say "this is someone else"), or a new one.
     *
     * @param  array<string, mixed>  $raw
     */
    private function patientFor(ImportRowResult $r, array $raw, string $last, string $first, string $birthdate, bool $saveAsNew): void
    {
        $text = $this->text($raw['patient_no'] ?? null);

        if ($text === '') {
            $match = $this->matchByIdentity($last, $first, $birthdate);

            // "Save as a new patient instead" only means something for a row that would be
            // linked (the name + birthdate warning). For any other row it is ignored.
            if ($saveAsNew && $match !== null) {
                $r->saveAsNew = true;
            } else {
                $r->patient = $match;
            }

            return;
        }

        $r->byPatientNo = true;
        $no = self::patientNo($text);

        if ($no === null) {
            $r->addError("Patient ID “{$this->short($text)}” is not valid. It looks like P-000012.");

            return;
        }

        $patient = $this->patientsByNo[$no] ?? null;

        if ($patient === null) {
            $r->addError("Patient ID {$no} was not found.");
        } elseif ($patient->trashed()) {
            $r->addError("Patient {$no} is in Trash. Restore the patient first.");
        } elseif (! self::samePerson($patient, $last, $first, $birthdate)) {
            $r->addError("Patient ID {$no} does not match this row: the name or birthdate is different from the patient on file.");
        } else {
            $r->patient = $patient;
        }
    }

    /**
     * Same last name, first name and birthdate (case and accents ignored).
     */
    public static function samePerson(Patient $patient, string $last, string $first, string $birthdate): bool
    {
        return self::foldName($patient->last_name) === self::foldName($last)
            && self::foldName($patient->first_name) === self::foldName($first)
            && $patient->birthdate->format('Y-m-d') === $birthdate;
    }

    private function matchByIdentity(string $last, string $first, string $birthdate): ?Patient
    {
        // Preloaded for the whole file in loadIdentities().
        return $this->identity[VisitController::newPatientLockKey($last, $first, $birthdate)] ?? null;
    }

    // --- Warnings --------------------------------------------------------------

    private function warnings(ImportRowResult $r, ?Rank $rank, ?Diagnosis $diagnosis, string $rankOther, string $diagnosisOther): void
    {
        $patient = $r->patient;
        $at = $r->visitedAt;
        /** @var array<string, mixed> $who */
        $who = $r->patientAttributes;

        if ($patient !== null && ! $r->byPatientNo) {
            $r->addWarning(ImportRowResult::LINK, "Will be linked to {$patient->patient_no} (same name and birthdate). If this is a different person, tick “Save as a new patient instead”.");
        }

        if ($patient !== null && $at !== null) {
            $category = $r->visitAttributes['category'] ?? null;
            if (isset($this->existingVisits[$patient->id.'|'.$category.'|'.$at->format('Y-m-d H:i')])) {
                $r->addWarning(ImportRowResult::DUPLICATE_VISIT, "Possible duplicate visit: {$patient->patient_no} already has a {$category} visit on this date and time.");
            }

            $differs = $patient->sex->value !== $who['sex']
                || (($who['middle_initial'] ?? null) !== null && $patient->middle_initial !== null && mb_strtoupper(rtrim($patient->middle_initial, '.')) !== $who['middle_initial']);

            if ($differs) {
                $r->addWarning(ImportRowResult::DIFFERS, "Sex or M.I. is different from {$patient->patient_no} on file. The patient record is not changed.");
            }
        }

        if ($at !== null) {
            $person = $patient !== null
                ? 'p:'.$patient->id
                : 'n:'.VisitController::newPatientLockKey((string) $who['last_name'], (string) $who['first_name'], (string) $who['birthdate']);
            $slot = $person.'|'.$at->format('Y-m-d H:i');

            if (isset($this->seen[$slot])) {
                $r->addWarning(ImportRowResult::FILE_DUPLICATE, "Same patient, date and time as row {$this->seen[$slot]}. It may be entered twice.");
            } else {
                $this->seen[$slot] = $r->number;
            }
        }

        if ($rank?->is_other && $rankOther !== '') {
            $listed = collect($this->ranks[$rank->branch_id] ?? [])
                ->first(fn (Rank $item): bool => ! $item->is_other && ! $item->trashed() && ListItemRules::sameName($item->name, $rankOther));

            if ($listed !== null) {
                $r->addWarning(ImportRowResult::COUNTS_AS, "Rank / Type “{$this->short($rankOther)}” is on the list. It will count as {$listed->name} in reports.");
            }
        }

        if ($diagnosis?->is_other && $diagnosisOther !== '') {
            $listed = collect($this->diagnoses)
                ->first(fn (Diagnosis $item): bool => ! $item->is_other && ! $item->trashed() && ListItemRules::sameName($item->name, $diagnosisOther));

            if ($listed !== null) {
                $r->addWarning(ImportRowResult::COUNTS_AS, "Diagnosis “{$this->short($diagnosisOther)}” is on the list. It will count as {$listed->name} in reports.");
            }
        }
    }

    // --- Cell text -------------------------------------------------------------

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * A cell as text: trimmed, with control characters removed. Line
     * breaks stay (Remarks may have them).
     */
    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);

        return trim($text);
    }

    /** A cell as one line of text: runs of spaces and line breaks become one space. */
    private function line(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $this->text($value)));
    }

    /** A value cut to 40 characters for a message. */
    private function short(string $text): string
    {
        return mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text;
    }

    /**
     * The enum's value that matches the text ignoring case ("male" is Male),
     * or the text itself so the field rule can refuse it in its own words.
     *
     * @param  array<int, \BackedEnum>  $cases
     */
    private function enumValue(array $cases, string $text): string
    {
        foreach ($cases as $case) {
            if (mb_strtolower((string) $case->value) === mb_strtolower($text)) {
                return (string) $case->value;
            }
        }

        return $text;
    }
}
