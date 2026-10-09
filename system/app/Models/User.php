<?php

namespace App\Models;

use App\Enums\Office;
use App\Enums\Role;
use App\Models\Concerns\MovesToTrash;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A system account. Accounts are created by Admin or System Admin only.
 * Login is by username; there is no email. No password reset by email, no
 * two-factor and no passkeys: Admin / System Admin reset passwords
 * (planning/rbac.md). The login check and lock: Actions/Fortify/AuthenticateUser.
 *
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $password
 * @property Role $role
 * @property Office $office
 * @property bool $is_active
 * @property int $failed_attempts
 * @property Carbon|null $locked_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'password', 'role', 'office', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MovesToTrash, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'office' => Office::class,
            'is_active' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * Visits this user encoded.
     *
     * @return HasMany<Visit, $this>
     */
    public function encodedVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'encoded_by');
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Is there an active Admin account (not in Trash), other than $except?
     * Only one may exist (planning/rbac.md).
     */
    public static function activeAdminExists(?User $except = null): bool
    {
        return static::query()
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }
}
