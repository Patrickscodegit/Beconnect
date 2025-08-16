<?php

namespace App\Jobs;

use App\Models\Extraction;
use App\Models\Intake;
use App\Services\LlmExtractor;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ExtractJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes for LLM processing
    public $tries = 3;
    public $maxExceptions = 1;
    public $backoff = [30, 120, 300]; // seconds - longer backoff for LLM rate limits

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('high'); 
    }

    public function handle(LlmExtractor $llmExtractor, PdfService $pdfService): void
    {
        $intake = Intake::findOrFail($this->intakeId);
        
        Log::info('Starting LLM extraction', [
            'intake_id' => $this->intakeId,
            'document_count' => $intake->documents->count()
        ]);

        try {
            $intake->update(['status' => 'extracting']);
            // Step 2.5: collect canonical payload for LLM
            if (method_exists($pdfService, 'collectPayloadForLlm')) {
                $payload = $pdfService->collectPayloadForLlm($intake);
            } else {
                // Fallback to previous method name (should not happen after Step 2.5)
                $payload = $pdfService->collectDocumentsForExtraction($intake);
            }
            
            if (empty($payload['documents'])) {
                throw new Exception('No documents available for extraction');
            }

            Log::info('Payload collection completed for LLM extraction', [
                'intake_id' => $this->intakeId,
                'document_count' => count($payload['documents']),
                'estimated_tokens' => $this->estimateTokens($payload)
            ]);

            // Perform LLM extraction with retry logic
            $result = $this->performLlmExtraction($llmExtractor, $payload);

            // Validate extraction results
            $this->validateExtractionResult($result);

            // Store extraction results
            Extraction::updateOrCreate(
                ['intake_id' => $intake->id],
                [
                    'raw_json' => $result['json'], 
                    'confidence' => $result['confidence'],
                    'extraction_method' => $result['json']['notes'] ?? ['llm_extraction'],
                    'processed_at' => now()
                ]
            );

            $intake->update(['status' => 'llm_extracted']);

            Log::info('LLM extraction completed successfully', [
                'intake_id' => $this->intakeId,
                'confidence' => $result['confidence'],
                'vehicles_extracted' => count($result['json']['vehicles'] ?? [])
            ]);

            // Dispatch next job in pipeline
            RulesJob::dispatch($this->intakeId)->onQueue('high');

        } catch (Exception $e) {
            Log::error('LLM extraction job failed', [
                'intake_id' => $this->intakeId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            $intake->update([
                'status' => 'extraction_failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function performLlmExtraction(LlmExtractor $llmExtractor, array $payload): array
    {
        $maxRetries = 3;
        $retryDelay = [5, 15, 30]; // seconds
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $llmExtractor->extract($payload);
            } catch (Exception $e) {
                if ($attempt === $maxRetries) {
                    throw $e;
                }
                
                Log::warning('LLM extraction attempt failed, retrying', [
                    'intake_id' => $this->intakeId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'retry_delay' => $retryDelay[$attempt - 1]
                ]);
                
                sleep($retryDelay[$attempt - 1]);
            }
        }
    }

    private function validateExtractionResult(array $result): void
    {
        if (!isset($result['json']) || !is_array($result['json'])) {
            throw new Exception('Invalid extraction result: missing or invalid JSON data');
        }

        if (!isset($result['confidence']) || !is_numeric($result['confidence'])) {
            throw new Exception('Invalid extraction result: missing or invalid confidence score');
        }

        // Check minimum confidence threshold
        $minConfidence = config('services.openai.min_confidence', 0.3);
        if ($result['confidence'] < $minConfidence) {
            Log::warning('Low confidence extraction result', [
                'intake_id' => $this->intakeId,
                'confidence' => $result['confidence'],
                'min_confidence' => $minConfidence
            ]);
        }

        // Validate required fields in extracted data
        $requiredFields = ['vehicles'];
        foreach ($requiredFields as $field) {
            if (!isset($result['json'][$field])) {
                throw new Exception("Invalid extraction result: missing required field '{$field}'");
            }
        }

        if (!is_array($result['json']['vehicles']) || empty($result['json']['vehicles'])) {
            throw new Exception('Invalid extraction result: no vehicles found in extracted data');
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('LLM extraction job failed after all retries', [
            'intake_id' => $this->intakeId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'extraction_failed',
                'error_message' => 'LLM extraction failed after ' . $this->attempts() . ' attempts: ' . $exception->getMessage()
            ]);

            // Create a failed extraction record for tracking
            Extraction::updateOrCreate(
                ['intake_id' => $intake->id],
                [
                    'raw_json' => ['error' => $exception->getMessage(), 'status' => 'failed'],
                    'confidence' => 0,
                    'extraction_method' => ['failed_extraction'],
                    'processed_at' => now()
                ]
            );
        }
    }

    private function estimateTokens(array $payload): int
    {
        $totalChars = 0;
        foreach ($payload['documents'] ?? [] as $doc) {
            $totalChars += strlen($doc['text'] ?? '');
        }
        return (int) ceil($totalChars / 4); // Rough estimate: 4 chars per token
    }
}
