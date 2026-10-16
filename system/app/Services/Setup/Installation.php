<?php

namespace App\Services\Setup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Is this system already set up? Yes when APP_KEY is set and the users
 * table has rows. census:install refuses on an installed system, and
 * census:update and census:restore only work on one.
 */
class Installation
{
    public function isInstalled(): bool
    {
        if (trim((string) config('app.key')) === '') {
            return false;
        }

        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
