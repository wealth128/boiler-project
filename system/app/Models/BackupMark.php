<?php

namespace App\Models;

use App\Enums\BackupMarkType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Admin marked a USB copy or a test restore as done.
 *
 * @property int $id
 * @property BackupMarkType $type
 * @property int|null $marked_by null when the system did it (automatic test restore)
 * @property Carbon $marked_at
 */
#[Fillable(['type', 'marked_by', 'marked_at'])]
class BackupMark extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'type' => BackupMarkType::class,
            'marked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by')->withTrashed();
    }
}
