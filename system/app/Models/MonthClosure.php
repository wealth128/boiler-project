<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A closed (locked) month. No edits or deletes of its visits until reopened.
 *
 * @property int $id
 * @property string $period
 * @property bool $is_closed
 * @property int $closed_by
 * @property Carbon $closed_at
 * @property int|null $reopened_by
 * @property Carbon|null $reopened_at
 */
#[Fillable(['period', 'is_closed', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at'])]
class MonthClosure extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /**
     * Is the given month ("2026-09") closed?
     */
    public static function isClosed(string $period): bool
    {
        return static::query()->where('period', $period)->where('is_closed', true)->exists();
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by')->withTrashed();
    }
}
