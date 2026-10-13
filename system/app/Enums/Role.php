<?php

namespace App\Enums;

/**
 * User roles (planning/rbac.md).
 * Only ONE active `admin` and ONE active `system_admin` may exist; the app
 * enforces that rule (UserPolicy, TrashPolicy).
 */
enum Role: string
{
    case Encoder = 'encoder';
    case Admin = 'admin';
    case SystemAdmin = 'system_admin';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Encoder => 'Encoder',
            self::Admin => 'Admin',
            self::SystemAdmin => 'System Admin',
            self::Viewer => 'Viewer (CO)',
        };
    }

    /**
     * Only one active account may have this role.
     */
    public function isSingleHolder(): bool
    {
        return $this === self::Admin || $this === self::SystemAdmin;
    }

    /**
     * The office an account with this role belongs to. Encoders pick their
     * office (Office::forEncoders()); the other roles have a fixed one.
     */
    public function fixedOffice(): ?Office
    {
        return match ($this) {
            self::Encoder => null,
            self::Admin => Office::Admin,
            self::SystemAdmin => Office::IT,
            self::Viewer => Office::Command,
        };
    }

    /**
     * Route name of the first page after login, and of "/" when signed in.
     */
    public function landingRoute(): string
    {
        return match ($this) {
            self::Encoder, self::Admin => 'encode.index',
            self::Viewer => 'reports.index',
            self::SystemAdmin => 'users.index',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
