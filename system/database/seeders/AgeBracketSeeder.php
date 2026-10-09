<?php

namespace Database\Seeders;

use App\Models\AgeBracket;
use Illuminate\Database\Seeder;

/**
 * SAMPLE age brackets (from prototype/prototype.html) until the hospital
 * gives its official ranges. null max = "and above".
 */
class AgeBracketSeeder extends Seeder
{
    /** @var list<array{0: int, 1: int|null}> */
    public const SAMPLES = [
        [0, 17],
        [18, 25],
        [26, 35],
        [36, 45],
        [46, 59],
        [60, null],
    ];

    public function run(): void
    {
        // Only seed an empty table, so Admin's own brackets are never mixed
        // with the samples (overlaps are not allowed).
        if (AgeBracket::withTrashed()->exists()) {
            return;
        }

        foreach (self::SAMPLES as $index => [$min, $max]) {
            AgeBracket::create(['min_age' => $min, 'max_age' => $max, 'sort_order' => $index + 1]);
        }
    }
}
