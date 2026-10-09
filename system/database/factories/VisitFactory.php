<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates its own patient, rank (with branch), diagnosis and encoder unless
 * you pass them in.
 *
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'visited_at' => now(),
            'age' => fake()->numberBetween(1, 90),
            'rank_id' => Rank::factory(),
            // Keep the visit's branch equal to its rank's branch.
            'branch_id' => fn (array $attributes) => Rank::withTrashed()->whereKey($attributes['rank_id'])->value('branch_id'),
            'rank_other' => null,
            'diagnosis_id' => Diagnosis::factory(),
            'diagnosis_other' => null,
            'category' => fake()->randomElement(Category::cases()),
            'remarks' => null,
            'encoded_by' => User::factory(),
            'updated_by' => null,
        ];
    }
}
