<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Branch of service: Army, Navy, Air Force, Marines, Others.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_afp
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['name', 'is_afp', 'sort_order', 'is_active'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_afp' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Rank, $this> */
    public function ranks(): HasMany
    {
        return $this->hasMany(Rank::class)->orderBy('sort_order');
    }

    /** @return HasMany<Visit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** @param Builder<Branch> $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
