<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Rank;
use Illuminate\Database\Seeder;

/**
 * Branches of service and their ranks.
 *
 * DRAFT lists copied from prototype/prototype.html, highest rank first.
 * The hospital must verify them. Admin can edit them later in Lists.
 */
class BranchAndRankSeeder extends Seeder
{
    /** Shown as "Other (specify)"; the encoder types the actual value. */
    public const OTHER = 'Other (specify)';

    /**
     * name => [is_afp, ranks]
     *
     * @var array<string, array{0: bool, 1: list<string>}>
     */
    public const BRANCHES = [
        'Army' => [true, ['GEN', 'LTGEN', 'MGEN', 'BGEN', 'COL', 'LTC', 'MAJ', 'CPT', '1LT', '2LT', 'CMS', 'SMS', 'MSG', 'TSG', 'SSG', 'SGT', 'CPL', 'PFC', 'PVT']],
        'Navy' => [true, ['ADM', 'VADM', 'RADM', 'COMMO', 'CAPT', 'CDR', 'LCDR', 'LT', 'LTJG', 'ENS', 'CMCPO', 'MCPO', 'SCPO', 'CPO', 'PO1', 'PO2', 'PO3', 'SN1', 'SN2', 'ASN']],
        'Air Force' => [true, ['GEN', 'LTGEN', 'MGEN', 'BGEN', 'COL', 'LTC', 'MAJ', 'CPT', '1LT', '2LT', 'CMS', 'SMS', 'MSG', 'TSG', 'SSG', 'SGT', 'A1C', 'A2C', 'AM']],
        'Marines' => [true, ['MGEN', 'BGEN', 'COL', 'LTC', 'MAJ', 'CPT', '1LT', '2LT', 'CMS', 'SMS', 'MSG', 'TSG', 'SSG', 'SGT', 'CPL', 'PFC', 'PVT']],
        'Others' => [false, ['Dependent', 'Retiree', 'Civilian Employee', 'Civilian', self::OTHER]],
    ];

    public function run(): void
    {
        $branchOrder = 0;

        foreach (self::BRANCHES as $branchName => [$isAfp, $ranks]) {
            $branch = Branch::firstOrCreate(
                ['name' => $branchName],
                ['is_afp' => $isAfp, 'sort_order' => ++$branchOrder, 'is_active' => true],
            );

            foreach ($ranks as $index => $rankName) {
                Rank::withTrashed()->firstOrCreate(
                    ['branch_id' => $branch->id, 'name' => $rankName],
                    ['is_other' => $rankName === self::OTHER, 'sort_order' => $index + 1],
                );
            }
        }
    }
}
