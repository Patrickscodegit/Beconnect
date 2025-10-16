<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IntakeFile>
 */
class IntakeFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intake_id' => \App\Models\Intake::factory(),
            'filename' => $this->faker->word() . '.eml',
            'storage_path' => $this->faker->uuid() . '.eml',
            'storage_disk' => 'documents',
            'mime_type' => 'message/rfc822',
            'file_size' => $this->faker->numberBetween(1024, 1048576), // 1KB to 1MB
        ];
    }
}
