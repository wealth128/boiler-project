<?php

namespace App\Enums;

/**
 * The manual backup safety steps Admin marks as done (planning/architecture.md,
 * section 4): the weekly USB copy and the monthly test restore.
 */
enum BackupMarkType: string
{
    case UsbCopy = 'usb_copy';
    case TestRestore = 'test_restore';

    /** The part of the URL: POST /backups/marks/{slug}. */
    public function slug(): string
    {
        return match ($this) {
            self::UsbCopy => 'usb-copy',
            self::TestRestore => 'test-restore',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UsbCopy => 'USB copy',
            self::TestRestore => 'Test restore',
        };
    }

    /** Overdue when the last mark is older than this many days (or missing). */
    public function overdueAfterDays(): int
    {
        return match ($this) {
            self::UsbCopy => 8,
            self::TestRestore => 35,
        };
    }

    public function auditAction(): string
    {
        return match ($this) {
            self::UsbCopy => 'backup.usb_copy_marked',
            self::TestRestore => 'backup.test_restore_marked',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }
}
