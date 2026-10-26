<?php

namespace App\Models\Concerns;

use App\Services\AdminAlerts;

/**
 * For the records the Admin warning bars are made from (backup runs, test
 * restores, USB copy and test restore marks, audit clean-up runs): any new,
 * changed or deleted row forgets the remembered bars, so the next page shows
 * the new state at once (App\Services\AdminAlerts).
 */
trait ClearsAdminAlerts
{
    protected static function bootClearsAdminAlerts(): void
    {
        foreach (['saved', 'deleted'] as $event) {
            static::registerModelEvent($event, fn () => AdminAlerts::forget());
        }
    }
}
