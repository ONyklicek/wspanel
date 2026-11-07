<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' '.fake()->randomNumber(3),
            'max_sites' => 3,
            'max_databases' => 3,
            'max_cron' => 5,
            'max_workers' => 2,
            'ssh' => 'shell',
        ];
    }
}
