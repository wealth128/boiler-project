<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Soft delete ("move to Trash") with who deleted it and why.
 *
 * Used by every model the schema marks as soft-deletable: users, patients,
 * visits, ranks, diagnoses and age_brackets. Each of those tables has
 * deleted_at, deleted_by and delete_reason.
 *
 * Hard delete (forceDelete) is only allowed from Trash, by Admin. Those
 * rules live in the Trash feature, not here.
 *
 * @property Carbon|null $deleted_at
 * @property int|null $deleted_by
 * @property string|null $delete_reason
 */
trait MovesToTrash
{
    use SoftDeletes;

    /**
     * Columns saved together with deleted_at when the record goes to Trash,
     * and cleared when it is restored. A model may add its own.
     *
     * @return list<string>
     */
    protected function trashColumns(): array
    {
        return ['deleted_by', 'delete_reason'];
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /**
     * Move this record to Trash, recording who did it and the reason.
     */
    public function moveToTrash(User $by, string $reason): bool
    {
        $this->forceFill([
            'deleted_by' => $by->getKey(),
            'delete_reason' => $reason,
        ]);

        return (bool) $this->delete();
    }

    /**
     * Take this record out of Trash and clear the delete details.
     */
    public function restoreFromTrash(): bool
    {
        $this->forceFill(array_fill_keys($this->trashColumns(), null));

        return $this->restore();
    }

    /**
     * Same as SoftDeletes::runSoftDelete(), but also saves the changed
     * trashColumns() in the same UPDATE so they never disagree with deleted_at.
     *
     * @return void
     */
    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        foreach ($this->trashColumns() as $column) {
            if ($this->isDirty($column)) {
                $columns[$column] = $this->getAttributes()[$column];
            }
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        $this->fireModelEvent('trashed', false);
    }
}
