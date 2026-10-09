<?php

namespace Database\Factories;

use App\Models\Diagnosis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Diagnosis>
 */
class DiagnosisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lexify('Diagnosis ??????'),
            'is_other' => false,
            'sort_order' => fake()->numberBetween(1, 50),
        ];
    }
}
