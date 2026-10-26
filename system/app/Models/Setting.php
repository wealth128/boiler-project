<?php

namespace App\Models;

use App\Services\HospitalSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings: hospital name, logo, report signatories.
 *
 * @property string $key
 * @property string $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected static function booted(): void
    {
        // The top bar's name, subtitle and logo are remembered for a minute: a change shows at once.
        static::saved(fn () => HospitalSettings::forgetShared());
        static::deleted(fn () => HospitalSettings::forgetShared());
    }

    /**
     * Read one setting, e.g. Setting::valueOf('hospital_name').
     */
    public static function valueOf(string $key, ?string $default = null): ?string
    {
        return static::query()->whereKey($key)->value('value') ?? $default;
    }
}
