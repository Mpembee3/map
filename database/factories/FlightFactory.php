<?php

namespace Database\Factories;

use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flight>
 */
class FlightFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'source_file' => fake()->unique()->slug(2).'.csv',
            'started_at' => '2026-02-02 21:56:28',
            'ended_at' => '2026-02-03 02:37:00',
        ];
    }
}
