<?php

namespace App\Models;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One test restore of the latest backup into the scratch database, started
 * by the scheduler (automatic) or by Admin (manual).
 *
 * @property int $id
 * @property string $trigger automatic|manual
 * @property BackupStatus $status
 * @property int|null $backup_run_id
 * @property int|null $started_by
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property string|null $message
 */
#[Fillable(['trigger', 'status', 'backup_run_id', 'started_by', 'started_at', 'finished_at', 'message'])]
class TestRestoreRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isAutomatic(): bool
    {
        return $this->trigger === 'automatic';
    }
}
