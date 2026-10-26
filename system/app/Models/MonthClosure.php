<?php

namespace App\Models;

use App\Models\Concerns\BumpsReportVersion;
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
    use BumpsReportVersion;

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
        if (self::$remembered !== null) {
            return isset(self::$remembered[$period]);
        }

        return static::query()->where('period', $period)->where('is_closed', true)->exists();
    }

    /** @var array<string, int>|null The closed months while remembering() runs: period => index. */
    private static ?array $remembered = null;

    /**
     * Runs $callback with the closed months read once, so isClosed() answers
     * from memory instead of asking the database for every row of a list
     * ("can this visit be edited?" on Encode's today list, Trash). Only for
     * building a page: never around a close or reopen.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remembering(callable $callback): mixed
    {
        $outer = self::$remembered;
        self::$remembered ??= static::query()->where('is_closed', true)->pluck('period')->flip()->all();

        try {
            return $callback();
        } finally {
            self::$remembered = $outer;
        }
    }

    /**
     * What a user is told when a closed month blocks an action.
     */
    public static function closedMessage(string $period): string
    {
        $month = Carbon::parse($period.'-01')->format('F Y');

        return "{$month} is closed. The Admin must reopen it first.";
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
