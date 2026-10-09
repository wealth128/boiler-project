<?php

namespace App\Enums;

/**
 * User roles (planning/rbac.md).
 * Only ONE active `admin` may exist; the app enforces that rule.
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

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
