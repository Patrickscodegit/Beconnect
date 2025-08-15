<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Intake>
 */
class IntakeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => fake()->randomElement(['email', 'upload', 'api']),
            'status' => fake()->randomElement(['uploaded', 'processing', 'completed', 'failed']),
            'notes' => [],
            'priority' => fake()->randomElement(['normal', 'high', 'urgent']),
        ];
    }
}
