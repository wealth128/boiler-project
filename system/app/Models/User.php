<?php

namespace App\Models;

use App\Enums\Category;
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
use Illuminate\Support\Str;

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
 * @property bool $must_change_password
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
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Set a password chosen by Admin, System Admin or IT (new account or
     * reset) and save. It is temporary: after logging in with it, the user
     * must choose their own before using the system (planning/rbac.md).
     * must_change_password is not fillable, so no form can switch it off.
     */
    public function setTemporaryPassword(string $password): bool
    {
        return $this->forceFill([
            'password' => $password, // hashed by the cast
            'must_change_password' => true,
            // Any old "remember me" cookie stops working with the old password.
            'remember_token' => Str::random(60),
        ])->save();
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
     * Visit category this user encodes by default: their office's category
     * (OPD / ER / Admission). Admin's office has none, so ER, like the
     * prototype.
     */
    public function defaultCategory(): Category
    {
        return $this->office->defaultCategory() ?? Category::ER;
    }

    /**
     * May this user pick any visit category? ER encoders and Admin can
     * (planning/mvp-scope.md section 2). Other encoders are locked to their
     * office's category.
     */
    public function canChooseCategory(): bool
    {
        return $this->hasRole(Role::Admin)
            || ($this->hasRole(Role::Encoder) && $this->office === Office::ER);
    }

    /**
     * Is there an active Admin account (not in Trash), other than $except?
     * Only one may exist (planning/rbac.md).
     */
    public static function activeAdminExists(?User $except = null): bool
    {
        return static::activeHolderExists(Role::Admin, $except);
    }

    /**
     * Is there an active account (not in Trash) with this role, other than
     * $except? Used for the roles only one account may hold: Admin and
     * System Admin (Role::isSingleHolder()).
     */
    public static function activeHolderExists(Role $role, ?User $except = null): bool
    {
        return static::query()
            ->where('role', $role)
            ->where('is_active', true)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }
}
