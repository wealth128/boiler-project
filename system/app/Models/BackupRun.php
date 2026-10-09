<?php

namespace App\Models;

use App\Enums\BackupStatus;
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
 * @property string|null $message
 */
#[Fillable(['started_at', 'finished_at', 'status', 'file_name', 'size_bytes', 'message'])]
class BackupRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => BackupStatus::class,
            'size_bytes' => 'integer',
        ];
    }
}
