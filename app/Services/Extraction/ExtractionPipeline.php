<?php

namespace App\Services\Extraction;

use App\Models\Document;
use App\Services\Extraction\Results\ExtractionResult;
use App\Services\Extraction\Strategies\ExtractionStrategyFactory;
use Illuminate\Support\Facades\Log;

class ExtractionPipeline
{
    public function __construct(
        private ExtractionStrategyFactory $strategyFactory
    ) {}

    /**
     * Process a document through the extraction pipeline
     */
    public function process(Document $document): ExtractionResult
    {
        $startTime = microtime(true);

        Log::info('Starting document extraction pipeline', [
            'document_id' => $document->id,
            'filename' => $document->filename,
            'mime_type' => $document->mime_type
        ]);

        try {
            // Get the best strategy for this document
            $strategy = $this->strategyFactory->getStrategy($document);

            if (!$strategy) {
                return ExtractionResult::failure(
                    'pipeline', 
                    'No extraction strategy available for this document type',
                    [
                        'document_id' => $document->id,
                        'mime_type' => $document->mime_type,
                        'filename' => $document->filename
                    ]
                );
            }

            // Execute the extraction
            $result = $strategy->extract($document);

            // Add pipeline metadata
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $result->addMetadata('pipeline', [
                'processing_time_ms' => $processingTime,
                'strategy_used' => $strategy->getName(),
                'document_id' => $document->id,
                'timestamp' => now()->toISOString()
            ]);

            Log::info('Document extraction pipeline completed', [
                'document_id' => $document->id,
                'strategy' => $strategy->getName(),
                'success' => $result->isSuccessful(),
                'confidence' => $result->getConfidence(),
                'processing_time_ms' => $processingTime
            ]);

            return $result;

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Document extraction pipeline failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
                'trace' => $e->getTraceAsString()
            ]);

            return ExtractionResult::failure(
                'pipeline',
                'Pipeline execution failed: ' . $e->getMessage(),
                [
                    'document_id' => $document->id,
                    'processing_time_ms' => $processingTime,
                    'error_type' => get_class($e)
                ]
            );
        }
    }

    /**
     * Process multiple documents
     */
    public function processMany(array $documents): array
    {
        $results = [];
        $startTime = microtime(true);

        Log::info('Starting batch document extraction', [
            'document_count' => count($documents)
        ]);

        foreach ($documents as $document) {
            $results[] = $this->process($document);
        }

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        $successfulCount = count(array_filter($results, fn($r) => $r->isSuccessful()));

        Log::info('Batch document extraction completed', [
            'total_documents' => count($documents),
            'successful_extractions' => $successfulCount,
            'failed_extractions' => count($documents) - $successfulCount,
            'total_processing_time_ms' => $totalTime,
            'average_time_per_document_ms' => round($totalTime / count($documents), 2)
        ]);

        return $results;
    }

    /**
     * Get available strategies for a document
     */
    public function getAvailableStrategies(Document $document): array
    {
        return $this->strategyFactory
            ->getSupportedStrategies($document)
            ->map(fn($strategy) => [
                'name' => $strategy->getName(),
                'priority' => $strategy->getPriority(),
                'class' => get_class($strategy)
            ])
            ->toArray();
    }

    /**
     * Get pipeline statistics
     */
    public function getStatistics(): array
    {
        return [
            'pipeline_version' => '2.0',
            'architecture' => 'Strategy Pattern with Pipeline',
            'strategies' => $this->strategyFactory->getStatistics()
        ];
    }

    /**
     * Validate document before processing
     */
    private function validateDocument(Document $document): bool
    {
        if (!$document->exists) {
            Log::warning('Document does not exist in database', [
                'document_id' => $document->id
            ]);
            return false;
        }

        if (empty($document->file_path)) {
            Log::warning('Document has no file path', [
                'document_id' => $document->id
            ]);
            return false;
        }

        return true;
    }
}
