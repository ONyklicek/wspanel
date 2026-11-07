<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'package_id' => Package::factory(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['suspended_at' => now()]);
    }
}
