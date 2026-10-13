<?php

namespace App\Enums;

/**
 * The office a user account belongs to. For encoders it decides the
 * default visit category (an ER account may choose any category).
 */
enum Office: string
{
    case ER = 'ER';
    case OPD = 'OPD';
    case Admission = 'Admission';
    case Admin = 'Admin';
    case IT = 'IT';
    case Command = 'Command';

    public function label(): string
    {
        return match ($this) {
            self::ER => 'Emergency Room',
            self::OPD => 'Out-Patient Department',
            self::Admission => 'Admission',
            self::Admin => 'Admin Office',
            self::IT => 'IT',
            self::Command => 'Command',
        };
    }

    /**
     * Default visit category for encoders of this office, or null when the
     * office does not encode.
     */
    public function defaultCategory(): ?Category
    {
        return match ($this) {
            self::ER => Category::ER,
            self::OPD => Category::OPD,
            self::Admission => Category::Admission,
            default => null,
        };
    }

    /**
     * Offices an encoder account can belong to.
     *
     * @return list<self>
     */
    public static function forEncoders(): array
    {
        return [self::ER, self::OPD, self::Admission];
    }

    /**
     * Can new encoder accounts be made for this office now? OPD and
     * Admission encoders are built in but switched off in v1.0
     * (planning/rbac.md): all encoding is done by ER. To switch them on,
     * return true for them here.
     */
    public function encodingOn(): bool
    {
        return $this === self::ER;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
