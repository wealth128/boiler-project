<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Deletes expired rows from the cache table and the cache locks table.
 *
 * Laravel's database cache never removes a row after it expires (it only
 * ignores it), so remembered reports would pile up for years. The scheduler
 * runs this every night. Rows that are still valid, such as a lock held by a
 * running backup, are never touched.
 */
class CacheCleaner
{
    /** @return int how many rows were deleted */
    public function purgeExpired(): int
    {
        $now = now()->getTimestamp();
        $deleted = 0;

        foreach ([config('cache.stores.database.table') ?: 'cache', config('cache.stores.database.lock_table') ?: 'cache_locks'] as $table) {
            $deleted += DB::table((string) $table)->where('expiration', '<=', $now)->delete();
        }

        return $deleted;
    }
}
