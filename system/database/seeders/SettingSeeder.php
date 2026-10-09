<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Report header and signatories. Placeholders until the hospital confirms
 * them. Existing values are never overwritten.
 */
class SettingSeeder extends Seeder
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'hospital_name' => '[Hospital Name]',
        'hospital_subtitle' => 'Armed Forces of the Philippines Medical Facility',
        'logo_path' => '',
        'report_office' => 'Admission / Administrative Office',
        'prepared_by' => '',
        'prepared_by_title' => 'Admission / Admin Office',
        'noted_by' => '',
        'noted_by_title' => 'Commanding Officer',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
