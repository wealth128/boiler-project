<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Trash entry for "Delete month report": all visits of a month moved to
 * Trash together. The row exists while the entry is in Trash.
 *
 * @property int $id
 * @property string $period
 * @property int $visit_count
 * @property int $deleted_by
 * @property string $delete_reason
 * @property Carbon $deleted_at
 */
#[Fillable(['period', 'visit_count', 'deleted_by', 'delete_reason', 'deleted_at'])]
class MonthDeletion extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'visit_count' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The visits moved to Trash by this action (they are all soft deleted).
     *
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }
}
