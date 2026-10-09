<?php

namespace App\Models;

use App\Models\Concerns\MovesToTrash;
use Database\Factories\RankFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rank within a branch, or an "Others" type (Dependent, Retiree, ...).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property bool $is_other
 * @property int $sort_order
 */
#[Fillable(['branch_id', 'name', 'is_other', 'sort_order'])]
class Rank extends Model
{
    /** @use HasFactory<RankFactory> */
    use HasFactory, MovesToTrash;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_other' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<Visit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** @param Builder<Rank> $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
