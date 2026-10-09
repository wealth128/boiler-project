<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Saved copy of a closed month's report. Closed months always show this
 * copy, so submitted numbers never change.
 *
 * @property int $id
 * @property string $period
 * @property array<string, mixed> $data
 * @property int $created_by
 * @property Carbon $created_at
 */
#[Fillable(['period', 'data', 'created_by'])]
class ReportSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
