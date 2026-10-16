<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Trash\TrashRows;
use App\Models\AgeBracket;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\MonthDeletion;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use App\Services\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the Audit log screen shows (prototype/prototype.html, "Audit log"):
 * the filtered query and, for one page of entries, the plain-language rows.
 *
 * Only the fields the table needs go to the page. Passwords never do: any
 * key containing "password" or "remember_token" is dropped from the saved
 * changes before they are read (see clean()).
 */
final class AuditRows
{
    /** subject_type (morph map name) => model. */
    private const SUBJECTS = [
        'users' => User::class,
        'patients' => Patient::class,
        'visits' => Visit::class,
        'diagnoses' => Diagnosis::class,
        'ranks' => Rank::class,
        'age_brackets' => AgeBracket::class,
        'month_closures' => MonthClosure::class,
        'month_deletions' => MonthDeletion::class,
    ];

    /** Readable names of changed fields. Others show their column name in words. */
    private const FIELDS = [
        'visited_at' => 'Date & time',
        'patient_id' => 'Patient',
        'branch_id' => 'Branch',
        'rank_id' => 'Rank / type',
        'rank_other' => 'Rank (other)',
        'diagnosis_id' => 'Diagnosis',
        'diagnosis_other' => 'Diagnosis (other)',
        'encoded_by' => 'Encoded by',
        'updated_by' => 'Edited by',
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'middle_initial' => 'M.I.',
        'birthdate' => 'Birthdate',
        'min_age' => 'From age',
        'max_age' => 'To age',
        'sort_order' => 'Order',
        'is_other' => 'Is "Other (specify)"',
        'is_active' => 'Active',
        'name' => 'Name',
        'hospital_name' => 'Hospital name',
        'report_office' => 'Office line',
        'prepared_by' => 'Prepared by (name)',
        'prepared_by_title' => 'Prepared by (title)',
        'noted_by' => 'Noted by (name)',
        'noted_by_title' => 'Noted by (title)',
    ];

    /** Keys in the saved changes that the Record column already shows. */
    private const IN_RECORD = ['label', 'period'];

    /**
     * Entries matching the filters, newest first, with their users loaded.
     *
     * @param  array{from: string, to: string, user: string, action: string, record: string}  $filters
     * @return Builder<AuditLog>
     */
    public static function query(array $filters): Builder
    {
        $query = AuditLog::query()->with('user:id,username,name,deleted_at');

        if ($filters['from'] !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay());
        }

        if ($filters['to'] !== '') {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->startOfDay());
        }

        if ($filters['user'] !== '') {
            $query->where('user_id', (int) $filters['user']);
        }

        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if ($filters['record'] !== '') {
            self::matchRecord($query, $filters['record']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Exact match on a Visit No., Patient ID or username. A record deleted
     * permanently is found by the ID or name saved in its entry.
     *
     * @param  Builder<AuditLog>  $query
     */
    private static function matchRecord(Builder $query, string $record): void
    {
        $query->where(function (Builder $q) use ($record): void {
            $q->where(fn (Builder $w) => $w->where('subject_type', 'visits')
                ->whereIn('subject_id', Visit::withTrashed()->where('visit_no', $record)->select('id')))
                ->orWhere(fn (Builder $w) => $w->where('subject_type', 'patients')
                    ->whereIn('subject_id', Patient::withTrashed()->where('patient_no', $record)->select('id')))
                ->orWhere(fn (Builder $w) => $w->where('subject_type', 'users')
                    ->whereIn('subject_id', User::withTrashed()->where('username', $record)->select('id')))
                ->orWhere(fn (Builder $w) => $w->where('action', 'like', '%.purged')->where('changes->label', $record));
        });
    }

    /**
     * The rows for one page of entries, in the same order.
     *
     * @param  iterable<AuditLog>  $entries
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $entries): array
    {
        $entries = collect($entries);
        $changes = $entries->mapWithKeys(fn (AuditLog $e): array => [$e->id => self::clean($e->changes ?? [])]);

        $subjects = self::subjects($entries);
        $names = self::names($changes);

        return $entries->map(fn (AuditLog $entry): array => [
            'id' => $entry->id,
            'created_at' => $entry->created_at->toIso8601String(),
            'username' => $entry->user?->username,
            'user_name' => $entry->user?->name,
            'action' => $entry->action,
            'action_label' => AuditActions::label($entry->action, $changes[$entry->id]),
            'record' => self::record($entry, $changes[$entry->id], $subjects),
            'ip_address' => $entry->ip_address,
            'details' => self::details($changes[$entry->id], $names),
        ])->values()->all();
    }

    /**
     * Drop every key containing "password" or "remember_token", at any depth.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|remember_token/i', $key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $clean;
    }

    /**
     * The records the page's entries point at: one query per type.
     *
     * @param  Collection<int, AuditLog>  $entries
     * @return array<string, Collection<int, Model>>
     */
    private static function subjects($entries): array
    {
        $found = [];

        foreach ($entries->groupBy('subject_type') as $type => $group) {
            $class = self::SUBJECTS[$type] ?? null;

            if ($class === null) {
                continue;
            }

            $query = method_exists($class, 'withTrashed') ? $class::withTrashed() : $class::query();

            $found[$type] = $query
                ->when($class === Rank::class, fn ($q) => $q->with('branch'))
                ->whereKey($group->pluck('subject_id')->unique()->all())
                ->get()
                ->keyBy('id');
        }

        return $found;
    }

    /**
     * The Record column: Visit No., Patient ID + name, username, list item,
     * or the month. A record deleted permanently shows the ID or name saved
     * in its entry.
     *
     * @param  array<array-key, mixed>  $changes
     * @param  array<string, Collection<int, Model>>  $subjects
     */
    private static function record(AuditLog $entry, array $changes, array $subjects): string
    {
        $period = $changes['period'] ?? null;

        if (is_string($period) && preg_match('/^(\d{4})-(\d{2})$/', $period) === 1) {
            $month = Carbon::parse($period.'-01')->format('F Y');

            return $entry->action === 'report.exported' ? "{$month} report" : $month;
        }

        $saved = $changes['label'] ?? null;

        if (is_string($saved) && $saved !== '') {
            return $saved;
        }

        $subject = $subjects[$entry->subject_type][$entry->subject_id] ?? null;

        if ($subject instanceof MonthDeletion || $subject instanceof MonthClosure) {
            return Carbon::parse($subject->period.'-01')->format('F Y');
        }

        return $subject instanceof Model ? TrashRows::label($subject) : "#{$entry->subject_id}";
    }

    /**
     * Names for the IDs inside the changes of this page: one query per kind.
     *
     * @param  Collection<int, array<array-key, mixed>>  $changes
     * @return array<string, array<int, string>>
     */
    private static function names($changes): array
    {
        $ids = ['branch_id' => [], 'rank_id' => [], 'diagnosis_id' => [], 'user' => [], 'patient_id' => []];
        $kind = ['encoded_by' => 'user', 'updated_by' => 'user'];

        foreach ($changes as $entry) {
            foreach (['old', 'new'] as $side) {
                foreach (is_array($entry[$side] ?? null) ? $entry[$side] : [] as $key => $value) {
                    $bucket = $kind[$key] ?? $key;

                    if (isset($ids[$bucket]) && is_numeric($value)) {
                        $ids[$bucket][(int) $value] = true;
                    }
                }
            }
        }

        $load = fn (string $class, string $bucket, callable $name): array => $ids[$bucket] === []
            ? []
            : $class::withTrashed()->whereKey(array_keys($ids[$bucket]))->get()->mapWithKeys(fn (Model $m): array => [$m->getKey() => $name($m)])->all();

        return [
            'branch_id' => $ids['branch_id'] === [] ? [] : Branch::query()->whereKey(array_keys($ids['branch_id']))->pluck('name', 'id')->all(),
            'rank_id' => $load(Rank::class, 'rank_id', fn (Rank $m): string => $m->name),
            'diagnosis_id' => $load(Diagnosis::class, 'diagnosis_id', fn (Diagnosis $m): string => $m->name),
            'user' => $load(User::class, 'user', fn (User $m): string => $m->username),
            'patient_id' => $load(Patient::class, 'patient_id', fn (Patient $m): string => "{$m->patient_no} · {$m->full_name}"),
        ];
    }

    /**
     * The "Field: old → new" lines. A new record has no old value, and a
     * plain detail (a delete reason, a count) has only a value. A null
     * value shows as a dash.
     *
     * @param  array<array-key, mixed>  $changes
     * @param  array<string, array<int, string>>  $names
     * @return list<array{field: string, old: string|null, new: string}>
     */
    private static function details(array $changes, array $names): array
    {
        $lines = [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $new = is_array($changes['new'] ?? null) ? $changes['new'] : [];
        $hasOld = $old !== [];

        // MySQL keeps JSON keys in its own order, so sort: known fields in
        // the order of FIELDS, the rest by name.
        $keys = array_map('strval', array_unique([...array_keys($new), ...array_keys($old)]));
        $order = array_flip(array_keys(self::FIELDS));
        usort($keys, fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        foreach ($keys as $key) {
            $lines[] = [
                'field' => self::field((string) $key),
                'old' => $hasOld ? self::show((string) $key, $old[$key] ?? null, $names) : null,
                'new' => self::show((string) $key, $new[$key] ?? null, $names),
            ];
        }

        foreach ($changes as $key => $value) {
            if (in_array($key, ['old', 'new', ...self::IN_RECORD], true) || ! is_string($key)) {
                continue;
            }

            $lines[] = ['field' => self::field($key), 'old' => null, 'new' => self::show($key, $value, $names)];
        }

        return $lines;
    }

    private static function field(string $key): string
    {
        return self::FIELDS[$key] ?? Str::ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * One value in words: names for IDs, DD-Mon-YYYY for dates.
     *
     * @param  array<string, array<int, string>>  $names
     */
    private static function show(string $key, mixed $value, array $names): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_numeric($value)) {
            $lookup = ['encoded_by' => 'user', 'updated_by' => 'user'][$key] ?? $key;

            if (isset($names[$lookup][(int) $value])) {
                return $names[$lookup][(int) $value];
            }

            if (str_starts_with($key, 'is_')) {
                return (int) $value === 1 ? 'Yes' : 'No';
            }
        }

        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return Carbon::parse($value)->format('d-M-Y');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value) === 1) {
                return Carbon::parse($value)->setTimezone(config('app.timezone'))->format('d-M-Y H:i');
            }
        }

        return (string) $value;
    }
}
