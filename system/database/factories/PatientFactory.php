<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_initial' => strtoupper(fake()->randomLetter()),
            'sex' => fake()->randomElement(Sex::cases()),
            'birthdate' => fake()->dateTimeBetween('-70 years', '-1 year')->format('Y-m-d'),
        ];
    }
}
