<?php

namespace App\Models\Concerns;

use App\Services\ReportDataVersion;
use Illuminate\Database\Eloquent\Model;

/**
 * Goes on every model a report is counted from. Any create, update, move to
 * Trash, restore or permanent delete raises the "report data version"
 * (ReportDataVersion), so a remembered report is never used after a change.
 */
trait BumpsReportVersion
{
    protected static function bootBumpsReportVersion(): void
    {
        static::registerModelEvent('saved', function (Model $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged()) {
                ReportDataVersion::bump();
            }
        });

        foreach (['deleted', 'restored', 'forceDeleted'] as $event) {
            static::registerModelEvent($event, fn () => ReportDataVersion::bump());
        }
    }
}
