<?php

namespace App\Models;

use App\Enums\Category;
use App\Models\Concerns\MovesToTrash;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One visit: what gets counted in reports.
 *
 * Related lookups (patient, rank, diagnosis, users) are loaded even when
 * they are in Trash, so old visits always show their names.
 *
 * @property int $id
 * @property string|null $visit_no
 * @property int $patient_id
 * @property Carbon $visited_at
 * @property int $age
 * @property int $branch_id
 * @property int $rank_id
 * @property string|null $rank_other
 * @property int $diagnosis_id
 * @property string|null $diagnosis_other
 * @property Category $category
 * @property string|null $remarks
 * @property int $encoded_by
 * @property int|null $updated_by
 * @property int|null $month_deletion_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $period
 */
#[Fillable([
    'patient_id', 'visited_at', 'age', 'branch_id', 'rank_id', 'rank_other',
    'diagnosis_id', 'diagnosis_other', 'category', 'remarks', 'encoded_by', 'updated_by',
])]
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory, MovesToTrash;

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'age' => 'integer',
            'category' => Category::class,
        ];
    }

    protected static function booted(): void
    {
        // Visit number comes from the auto-increment id: V-000001.
        static::created(function (Visit $visit): void {
            if ($visit->visit_no === null) {
                // Write only this column (not a full re-save) and fire no events.
                $visit->visit_no = static::formatNumber($visit->id);
                static::query()->whereKey($visit->id)->update(['visit_no' => $visit->visit_no]);
            }
        });
    }

    public static function formatNumber(int $id): string
    {
        return 'V-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * A visit sent to Trash by "Delete month report" also records which
     * month entry it belongs to; restoring clears that link.
     *
     * @return list<string>
     */
    protected function trashColumns(): array
    {
        return ['deleted_by', 'delete_reason', 'month_deletion_id'];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Rank, $this> */
    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class)->withTrashed();
    }

    /** @return BelongsTo<Diagnosis, $this> */
    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    /**
     * The "Delete month report" Trash entry this visit belongs to, if any.
     *
     * @return BelongsTo<MonthDeletion, $this>
     */
    public function monthDeletion(): BelongsTo
    {
        return $this->belongsTo(MonthDeletion::class);
    }

    /**
     * The month this visit counts in, e.g. "2026-09".
     */
    public function getPeriodAttribute(): string
    {
        return $this->visited_at->format('Y-m');
    }
}
