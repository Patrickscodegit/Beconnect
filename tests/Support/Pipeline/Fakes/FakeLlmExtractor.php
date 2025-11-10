<?php

namespace Tests\Support\Pipeline\Fakes;

use App\Services\LlmExtractor;

class FakeLlmExtractor extends LlmExtractor
{
    public function extract(array $payload): array
    {
        return [
            'json' => [
                'intake_id' => $payload['intake_id'] ?? null,
                'documents' => $payload['documents'] ?? [],
                'extraction' => [
                    'vehicle' => [
                        'brand' => 'BMW',
                        'model' => 'Série 7',
                    ],
                ],
            ],
            'confidence' => 0.9,
        ];
    }

    public function extractVehicleData(string $text): array
    {
        return [
            'make' => 'Test',
            'model' => 'Vehicle',
            'vin' => 'FAKEVIN1234567890',
            'year' => 2024,
        ];
    }
}

