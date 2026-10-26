<?php

namespace App\Models;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Result of one run of the automatic audit log clean-up.
 *
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property BackupStatus $status
 * @property int $rows_count
 * @property string|null $archive_name
 * @property string|null $message
 */
#[Fillable(['started_at', 'finished_at', 'status', 'rows_count', 'archive_name', 'message'])]
class AuditPruneRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => BackupStatus::class,
            'rows_count' => 'integer',
        ];
    }
}
