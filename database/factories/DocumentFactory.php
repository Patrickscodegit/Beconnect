<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Intake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intake_id' => Intake::factory(),
            'filename' => fake()->word() . '.pdf',
            'file_path' => 'documents/' . fake()->uuid() . '.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(10000, 5000000),
            'document_type' => fake()->randomElement(['invoice', 'vehicle_registration', 'manifest', 'unknown']),
            'has_text_layer' => fake()->boolean(),
            'page_count' => fake()->numberBetween(1, 10),
        ];
    }
}
