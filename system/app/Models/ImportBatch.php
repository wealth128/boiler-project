<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One downtime import: the file Admin imported and what came of it. Its
 * visits (and the patients only it created) point back here, so Admin can
 * move the whole batch to Trash with "Undo import".
 *
 * @property int $id
 * @property string $file_name
 * @property int $original_rows
 * @property int $imported_count
 * @property int $skipped_count
 * @property int $imported_by
 * @property Carbon $created_at
 * @property Carbon|null $undone_at
 * @property int|null $undone_by
 */
#[Fillable(['file_name', 'original_rows', 'imported_count', 'skipped_count', 'imported_by', 'created_at', 'undone_at', 'undone_by'])]
class ImportBatch extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'original_rows' => 'integer',
            'imported_count' => 'integer',
            'skipped_count' => 'integer',
            'created_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /** @return HasMany<Visit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by')->withTrashed();
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }
}
