<?php

namespace Database\Factories;

use App\Models\Flight;
use App\Models\TrackPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackPoint>
 */
class TrackPointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flight_id' => Flight::factory(),
            'sequence' => fake()->unique()->numberBetween(0, 1000000),
            'recorded_at' => '2026-02-02 22:00:00',
            'latitude' => fake()->randomFloat(6, 53.09, 53.14),
            'longitude' => fake()->randomFloat(6, 49.93, 50.01),
            'altitude_hae' => fake()->randomFloat(1, 950, 1050),
            'jam_indicator' => 10.6,
            'hdop' => 1.1,
            'satellites_used' => 9,
            'noise' => 56,
            'has_fix' => true,
            'is_suspect' => false,
        ];
    }

    /**
     * A sample where the receiver reported no position.
     */
    public function withoutFix(): static
    {
        return $this->state(fn (): array => [
            'latitude' => null,
            'longitude' => null,
            'altitude_hae' => null,
            'hdop' => 100,
            'satellites_used' => 0,
            'has_fix' => false,
        ]);
    }
}
