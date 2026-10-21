<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\MonthClosure;
use App\Models\Rank;
use Illuminate\Support\Carbon;

/**
 * The lists the import tests run against, and the moment they run at
 * (2026-10-09 14:00, Asia/Manila).
 */
final class ImportScenario
{
    /**
     * @return array<string, mixed>
     */
    public static function lists(): array
    {
        $army = Branch::factory()->create(['name' => 'Army', 'is_afp' => true, 'sort_order' => 1]);
        $navy = Branch::factory()->create(['name' => 'Navy', 'is_afp' => true, 'sort_order' => 2]);
        $others = Branch::factory()->create(['name' => 'Others', 'is_afp' => false, 'sort_order' => 5]);

        return [
            'army' => $army,
            'navy' => $navy,
            'others' => $others,
            'sgt' => Rank::factory()->for($army)->create(['name' => 'SGT', 'sort_order' => 2]),
            'cpl' => Rank::factory()->for($army)->create(['name' => 'CPL', 'sort_order' => 1]),
            'ens' => Rank::factory()->for($navy)->create(['name' => 'ENS', 'sort_order' => 1]),
            'dependent' => Rank::factory()->for($others)->create(['name' => 'Dependent', 'sort_order' => 1]),
            'otherType' => Rank::factory()->for($others)->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 99]),
            'hypertension' => Diagnosis::factory()->create(['name' => 'Hypertension']),
            'dengue' => Diagnosis::factory()->create(['name' => 'Dengue Fever']),
            'otherDiagnosis' => Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true, 'sort_order' => 999]),
        ];
    }

    /** Close a month ("2026-09") the way Admin does. */
    public static function closeMonth(string $period, int $by): void
    {
        MonthClosure::create([
            'period' => $period,
            'is_closed' => true,
            'closed_by' => $by,
            'closed_at' => Carbon::now(),
        ]);
    }
}
