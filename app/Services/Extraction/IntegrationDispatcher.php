<?php

namespace App\Services\Extraction;

use App\Models\Document;
use App\Services\Extraction\Results\ExtractionResult;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use Illuminate\Support\Facades\Log;

class IntegrationDispatcher
{
    public function __construct(
        private EnhancedRobawsIntegrationService $robawsService
    ) {}

    /**
     * Dispatch extraction result to appropriate integrations
     */
    public function dispatch(Document $document, ExtractionResult $result): array
    {
        $startTime = microtime(true);
        $dispatched = [];

        Log::info('Starting integration dispatch', [
            'document_id' => $document->id,
            'extraction_success' => $result->isSuccessful(),
            'confidence' => $result->getConfidence()
        ]);

        try {
            // Only dispatch successful extractions
            if (!$result->isSuccessful()) {
                Log::warning('Skipping integration for failed extraction', [
                    'document_id' => $document->id,
                    'error' => $result->getErrorMessage()
                ]);
                return $dispatched;
            }

            // Dispatch to ROBAWS
            $robawsResult = $this->dispatchToRobaws($document, $result);
            $dispatched['robaws'] = $robawsResult;

            // Add future integrations here
            // $dispatched['other_service'] = $this->dispatchToOtherService($document, $result);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Integration dispatch completed', [
                'document_id' => $document->id,
                'dispatched_services' => array_keys($dispatched),
                'processing_time_ms' => $processingTime
            ]);

            return $dispatched;

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Integration dispatch failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ]);

            throw $e;
        }
    }

    /**
     * Dispatch to ROBAWS integration
     */
    private function dispatchToRobaws(Document $document, ExtractionResult $result): array
    {
        try {
            Log::info('Dispatching to ROBAWS', [
                'document_id' => $document->id,
                'confidence' => $result->getConfidence()
            ]);

            // Prepare data for ROBAWS - use the full result data
            $extractedData = $result->getData();
            
            // Send to ROBAWS using the existing processDocument method
            $success = $this->robawsService->processDocument($document, $extractedData);

            Log::info('ROBAWS dispatch completed', [
                'document_id' => $document->id,
                'success' => $success
            ]);

            return [
                'success' => $success,
                'service' => 'robaws',
                'method' => 'processDocument',
                'data_attribution' => [
                    'document_fields' => array_keys($result->getDocumentData()),
                    'ai_enhanced_fields' => array_keys($result->getAiEnhancedData()),
                    'confidence' => $result->getConfidence()
                ],
                'timestamp' => now()->toISOString()
            ];

        } catch (\Exception $e) {
            Log::error('ROBAWS dispatch failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'service' => 'robaws',
                'timestamp' => now()->toISOString()
            ];
        }
    }

    /**
     * Dispatch extraction result with retry logic
     */
    public function dispatchWithRetry(Document $document, ExtractionResult $result, int $maxRetries = 3): array
    {
        $attempt = 1;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                Log::info('Integration dispatch attempt', [
                    'document_id' => $document->id,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries
                ]);

                return $this->dispatch($document, $result);

            } catch (\Exception $e) {
                $lastException = $e;
                
                Log::warning('Integration dispatch attempt failed', [
                    'document_id' => $document->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $maxRetries) {
                    $delaySeconds = pow(2, $attempt - 1); // Exponential backoff
                    Log::info('Retrying integration dispatch', [
                        'document_id' => $document->id,
                        'retry_delay_seconds' => $delaySeconds
                    ]);
                    sleep($delaySeconds);
                }

                $attempt++;
            }
        }

        // All retries failed
        Log::error('Integration dispatch failed after all retries', [
            'document_id' => $document->id,
            'attempts' => $maxRetries,
            'final_error' => $lastException->getMessage()
        ]);

        throw $lastException;
    }

    /**
     * Get integration statistics
     */
    public function getStatistics(): array
    {
        return [
            'dispatcher_version' => '1.0',
            'supported_integrations' => [
                'robaws' => [
                    'service_class' => get_class($this->robawsService),
                    'active' => true
                ]
            ],
            'features' => [
                'retry_logic' => true,
                'data_attribution' => true,
                'confidence_tracking' => true
            ]
        ];
    }
}
