<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Who did what, when. Entries are written once and never updated or deleted.
 *
 * subject_type uses the short names from the morph map in
 * AppServiceProvider (e.g. "visits"), not PHP class names.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed>|null $changes
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit log entries cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
