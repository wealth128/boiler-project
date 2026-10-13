<?php

namespace App\Enums\Concerns;

use App\Enums\DeleteReason;

/**
 * Shared code for the delete reason enums (see App\Enums\DeleteReason).
 * Each enum names its "Other (specify)" case "Other".
 */
trait ListsDeleteReasons
{
    public function isOther(): bool
    {
        return $this->value === DeleteReason::OTHER;
    }

    /**
     * @return list<array{value: string, other: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'other' => $reason->isOther()],
            self::cases(),
        );
    }
}
