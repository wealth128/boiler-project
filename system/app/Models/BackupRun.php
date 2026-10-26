<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Models\Concerns\ClearsAdminAlerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Result of one backup run.
 *
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property BackupStatus $status
 * @property string|null $file_name
 * @property int|null $size_bytes
 * @property string|null $zip_name
 * @property int|null $zip_size_bytes
 * @property int|null $patients_count
 * @property int|null $visits_count
 * @property int|null $users_count
 * @property int|null $closed_months_count
 * @property string|null $message
 */
#[Fillable(['started_at', 'finished_at', 'status', 'file_name', 'size_bytes', 'zip_name', 'zip_size_bytes', 'patients_count', 'visits_count', 'users_count', 'closed_months_count', 'patients_count_max', 'visits_count_max', 'users_count_max', 'closed_months_count_max', 'message'])]
class BackupRun extends Model
{
    use ClearsAdminAlerts;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => BackupStatus::class,
            'size_bytes' => 'integer',
            'zip_size_bytes' => 'integer',
        ];
    }
}
