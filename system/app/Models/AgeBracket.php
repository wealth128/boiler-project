<?php

namespace App\Models;

use App\Models\Concerns\MovesToTrash;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An age range used by reports. Not linked to visits: reports place each
 * visit's age into a bracket when the report runs.
 *
 * @property int $id
 * @property int $min_age
 * @property int|null $max_age
 * @property int $sort_order
 * @property-read string $label
 */
#[Fillable(['min_age', 'max_age', 'sort_order'])]
class AgeBracket extends Model
{
    use MovesToTrash;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'min_age' => 'integer',
            'max_age' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * "18–25", or "60 & above" when there is no upper limit.
     */
    public function getLabelAttribute(): string
    {
        return $this->max_age === null
            ? "{$this->min_age} & above"
            : "{$this->min_age}–{$this->max_age}";
    }

    public function contains(int $age): bool
    {
        return $age >= $this->min_age && ($this->max_age === null || $age <= $this->max_age);
    }

    /** @param Builder<AgeBracket> $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('min_age');
    }
}
