<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lexify('Branch ????'),
            'is_afp' => true,
            'sort_order' => fake()->numberBetween(1, 50),
            'is_active' => true,
        ];
    }
}
