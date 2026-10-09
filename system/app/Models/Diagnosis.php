<?php

namespace App\Models;

use App\Models\Concerns\MovesToTrash;
use Database\Factories\DiagnosisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_other
 * @property int $sort_order
 */
#[Fillable(['name', 'is_other', 'sort_order'])]
class Diagnosis extends Model
{
    /** @use HasFactory<DiagnosisFactory> */
    use HasFactory, MovesToTrash;

    protected $table = 'diagnoses';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_other' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Visit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** @param Builder<Diagnosis> $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
