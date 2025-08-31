<?php

namespace App\Services\Extraction\Strategies;

use App\Services\AiRouter;
use Illuminate\Support\Facades\Log;

class AiExtractor
{
    public function __construct(
        private AiRouter $aiRouter
    ) {}

    /**
     * Extract data using AI with a given prompt and schema
     */
    public function extract(string $prompt, array $schema = []): array
    {
        try {
            Log::info('Starting AI extraction', [
                'prompt_length' => strlen($prompt),
                'schema_provided' => !empty($schema)
            ]);

            $result = $this->aiRouter->extract($prompt, $schema);

            Log::info('AI extraction completed', [
                'result_keys' => array_keys($result),
                'has_vehicle_data' => isset($result['vehicle']),
                'has_contact_data' => isset($result['contact']),
                'has_shipment_data' => isset($result['shipment'])
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('AI extraction failed', [
                'error' => $e->getMessage(),
                'prompt_length' => strlen($prompt)
            ]);

            // Return empty result structure on failure
            return [
                'vehicle' => [],
                'shipment' => [],
                'contact' => [],
                'dates' => [],
                'pricing' => [],
                'metadata' => [
                    'extraction_error' => $e->getMessage(),
                    'extraction_failed' => true
                ]
            ];
        }
    }

    /**
     * Get the name of this extractor
     */
    public function getName(): string
    {
        return 'ai_extractor';
    }
}
