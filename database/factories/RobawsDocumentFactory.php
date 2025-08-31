<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\RobawsDocument;
use App\Models\Document;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RobawsDocument>
 */
class RobawsDocumentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RobawsDocument::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'robaws_offer_id' => $this->faker->uuid(),
            'robaws_document_id' => $this->faker->uuid(),
            'sha256' => hash('sha256', $this->faker->text(100)),
            'filename' => $this->faker->word() . '.pdf',
            'filesize' => $this->faker->numberBetween(1000, 1000000),
        ];
    }
}
