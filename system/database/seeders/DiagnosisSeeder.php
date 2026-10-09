<?php

namespace Database\Seeders;

use App\Models\Diagnosis;
use Illuminate\Database\Seeder;

/**
 * SAMPLE diagnoses (from prototype/prototype.html) until the hospital gives
 * its list. "Other (specify)" is fixed and always last.
 */
class DiagnosisSeeder extends Seeder
{
    public const OTHER = 'Other (specify)';

    /** @var list<string> */
    public const SAMPLES = [
        'Hypertension',
        'Upper Respiratory Tract Infection',
        'Acute Gastroenteritis',
        'Urinary Tract Infection',
        'Pneumonia',
        'Diabetes Mellitus Type 2',
        'Dengue Fever',
        'Musculoskeletal Strain',
        'Bronchial Asthma',
        'Laceration',
        'Acute Tonsillopharyngitis',
        'Fracture',
    ];

    public function run(): void
    {
        foreach (self::SAMPLES as $index => $name) {
            Diagnosis::withTrashed()->firstOrCreate(
                ['name' => $name],
                ['is_other' => false, 'sort_order' => $index + 1],
            );
        }

        Diagnosis::withTrashed()->firstOrCreate(
            ['name' => self::OTHER],
            ['is_other' => true, 'sort_order' => 999],
        );
    }
}
