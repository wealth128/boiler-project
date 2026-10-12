<?php

namespace App\Observers;

use App\Models\MonthClosure;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes an audit_logs entry for every create, update, move to Trash,
 * restore and permanent delete of: visits, patients, users, diagnoses,
 * ranks, age brackets and month closures (registered in AppServiceProvider).
 *
 * Action names: "<subject>.<verb>", e.g. "visit.created", "patient.updated",
 * "rank.deleted" (moved to Trash), "user.restored", "diagnosis.purged"
 * (deleted permanently). Month closures log "month.closed" and
 * "month.reopened" instead.
 *
 * Rules:
 * - Password values are never written, not even the hash: "[hidden]".
 * - Edits store only the changed fields, as {"old": {...}, "new": {...}}.
 * - Changes made with plain queries (the wrong-password counter, Patient
 *   ID / Visit No. numbering, bulk Trash moves) fire no events, so they are
 *   not logged here. The code doing them logs one summary entry if needed.
 */
class AuditObserver
{
    /** Never written to the log. */
    private const SECRET = ['password'];

    /** Not worth an entry on their own (Laravel bookkeeping). */
    private const IGNORED = ['created_at', 'updated_at', 'remember_token'];

    /** Set by move to Trash / restore, which have their own entries. */
    private const TRASH_COLUMNS = ['deleted_at', 'deleted_by', 'delete_reason', 'month_deletion_id'];

    private static bool $muted = false;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Run $callback without these automatic entries. For seeding, and for
     * actions that write one summary entry themselves (e.g. a month report
     * with hundreds of visits).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function muted(Closure $callback): mixed
    {
        $before = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $before;
        }
    }

    public function created(Model $model): void
    {
        if ($model instanceof MonthClosure) {
            $this->log('month.closed', $model, ['period' => $model->period]);

            return;
        }

        $this->log($this->subject($model).'.created', $model, [
            'new' => $this->clean($model->getAttributes(), except: ['id']),
        ]);
    }

    public function updated(Model $model): void
    {
        $changed = array_values(array_diff(array_keys($model->getChanges()), self::IGNORED, self::TRASH_COLUMNS));

        if ($changed === []) {
            return; // only bookkeeping or Trash columns changed (restore logs its own entry)
        }

        if ($model instanceof MonthClosure) {
            if ($model->wasChanged('is_closed')) {
                $this->log($model->is_closed ? 'month.closed' : 'month.reopened', $model, ['period' => $model->period]);
            }

            return;
        }

        $old = [];
        $new = [];

        foreach ($changed as $key) {
            $old[$key] = $model->getRawOriginal($key);
            $new[$key] = $model->getAttributes()[$key] ?? null;
        }

        $this->log($this->subject($model).'.updated', $model, [
            'old' => $this->clean($old),
            'new' => $this->clean($new),
        ]);
    }

    /**
     * Moved to Trash (soft delete). Permanent delete is forceDeleted().
     */
    public function deleted(Model $model): void
    {
        if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
            return;
        }

        $this->log($this->subject($model).'.deleted', $model, [
            'reason' => $model->getAttribute('delete_reason'),
        ]);
    }

    public function restored(Model $model): void
    {
        $this->log($this->subject($model).'.restored', $model);
    }

    /**
     * Deleted permanently. Only the record's number or name is kept, so the
     * entry stays readable without keeping the deleted data.
     */
    public function forceDeleted(Model $model): void
    {
        $this->log($this->subject($model).'.purged', $model, ['label' => self::label($model)]);
    }

    /**
     * Short name of a record for log entries: "V-000123", "P-000045",
     * "er.cruz", "Hypertension", "18–25".
     */
    public static function label(Model $model): ?string
    {
        foreach (['visit_no', 'patient_no', 'username', 'name', 'period'] as $column) {
            $value = $model->getAttribute($column);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return method_exists($model, 'getLabelAttribute') ? $model->getLabelAttribute() : null;
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function log(string $action, Model $model, ?array $changes = null): void
    {
        if (self::$muted) {
            return;
        }

        $this->audit->log($action, $model, $changes);
    }

    /**
     * "visits" => "visit", "age_brackets" => "age_bracket".
     */
    private function subject(Model $model): string
    {
        return Str::singular($model->getMorphClass());
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $except
     * @return array<string, mixed>
     */
    private function clean(array $values, array $except = []): array
    {
        $values = array_diff_key($values, array_flip([...self::IGNORED, ...self::TRASH_COLUMNS, ...$except]));

        foreach (self::SECRET as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[hidden]';
            }
        }

        return $values;
    }
}
