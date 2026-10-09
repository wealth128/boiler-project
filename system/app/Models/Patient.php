<?php

namespace App\Models;

use App\Enums\Sex;
use App\Models\Concerns\MovesToTrash;
use Carbon\CarbonInterface;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One row per person. A returning patient reuses the same row.
 *
 * @property int $id
 * @property string|null $patient_no
 * @property string $last_name
 * @property string $first_name
 * @property string|null $middle_initial
 * @property Sex $sex
 * @property Carbon $birthdate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $full_name
 */
#[Fillable(['last_name', 'first_name', 'middle_initial', 'sex', 'birthdate'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, MovesToTrash;

    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'birthdate' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Patient ID comes from the auto-increment id: P-000001.
        static::created(function (Patient $patient): void {
            if ($patient->patient_no === null) {
                // Write only this column (not a full re-save) and fire no events.
                $patient->patient_no = static::formatNumber($patient->id);
                static::query()->whereKey($patient->id)->update(['patient_no' => $patient->patient_no]);
            }
        });
    }

    public static function formatNumber(int $id): string
    {
        return 'P-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /** @return HasMany<Visit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * Age in whole years on the given date (defaults to today).
     */
    public function ageAt(?CarbonInterface $date = null): int
    {
        return (int) $this->birthdate->diffInYears($date ?? now());
    }

    /**
     * "Dela Cruz, Juan M."
     */
    public function getFullNameAttribute(): string
    {
        $mi = $this->middle_initial ? ' '.rtrim($this->middle_initial, '.').'.' : '';

        return "{$this->last_name}, {$this->first_name}{$mi}";
    }
}
