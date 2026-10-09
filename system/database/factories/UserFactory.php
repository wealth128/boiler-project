<?php

namespace Database\Factories;

use App\Enums\Office;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Default: an active ER encoder whose password is "password" (tests only).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Lowercase, like real usernames (login lowercases what is typed).
            'username' => Str::lower(fake()->unique()->userName()),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Encoder,
            'office' => Office::ER,
            'is_active' => true,
            'failed_attempts' => 0,
            'locked_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function encoder(Office $office = Office::ER): static
    {
        return $this->state(fn () => ['role' => Role::Encoder, 'office' => $office]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin, 'office' => Office::Admin]);
    }

    public function systemAdmin(): static
    {
        return $this->state(fn () => ['role' => Role::SystemAdmin, 'office' => Office::IT]);
    }

    public function viewer(): static
    {
        return $this->state(fn () => ['role' => Role::Viewer, 'office' => Office::Command]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['failed_attempts' => 5, 'locked_at' => now()]);
    }
}
