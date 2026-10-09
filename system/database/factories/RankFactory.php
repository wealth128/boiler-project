<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Rank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rank>
 */
class RankFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->lexify('RANK-???'),
            'is_other' => false,
            'sort_order' => fake()->numberBetween(1, 50),
        ];
    }
}
