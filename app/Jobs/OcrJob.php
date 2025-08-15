<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Intake;
use App\Services\OcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class OcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $maxExceptions = 1;
    public $backoff = [10, 30, 60]; // seconds

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('default'); 
    }

    public function handle(OcrService $ocrService): void
    {
        $intake = Intake::findOrFail($this->intakeId);
        
        Log::info('Starting OCR processing', [
            'intake_id' => $this->intakeId,
            'document_count' => $intake->documents->count()
        ]);

        try {
            $intake->update(['status' => 'ocr_processing']);
            $processedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            foreach ($intake->documents as $document) {
                try {
                    // Skip if document already has text layer or OCR already processed
                    if ($document->has_text_layer) {
                        Log::info('Skipping OCR - document already has text layer', [
                            'document_id' => $document->id,
                            'filename' => $document->original_filename
                        ]);
                        $skippedCount++;
                        continue;
                    }

                    // Skip non-supported file types
                    if (!in_array($document->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'])) {
                        Log::info('Skipping OCR - unsupported file type', [
                            'document_id' => $document->id,
                            'mime_type' => $document->mime_type
                        ]);
                        $skippedCount++;
                        continue;
                    }

                    $ocrService->run($document);
                    $processedCount++;

                } catch (Exception $e) {
                    Log::error('OCR processing failed for individual document', [
                        'document_id' => $document->id,
                        'filename' => $document->original_filename,
                        'error' => $e->getMessage()
                    ]);
                    $errorCount++;

                    // Update document status to indicate OCR failure
                    $document->update([
                        'status' => 'ocr_failed',
                        'error_message' => $e->getMessage()
                    ]);
                }
            }

            // Update intake status based on results
            if ($errorCount === 0) {
                $intake->update(['status' => 'ocr_done']);
            } elseif ($processedCount > 0) {
                $intake->update(['status' => 'ocr_partial']);
            } else {
                $intake->update(['status' => 'ocr_failed']);
                throw new Exception("OCR processing failed for all documents in intake {$this->intakeId}");
            }

            Log::info('OCR processing completed', [
                'intake_id' => $this->intakeId,
                'processed' => $processedCount,
                'skipped' => $skippedCount,
                'errors' => $errorCount
            ]);

            // Dispatch next job in pipeline
            if ($processedCount > 0 || $skippedCount > 0) {
                ClassifyJob::dispatch($this->intakeId)->onQueue('default');
            }

        } catch (Exception $e) {
            Log::error('OCR job failed completely', [
                'intake_id' => $this->intakeId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            $intake->update([
                'status' => 'ocr_failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('OCR job failed after all retries', [
            'intake_id' => $this->intakeId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'ocr_failed',
                'error_message' => 'OCR processing failed after ' . $this->attempts() . ' attempts: ' . $exception->getMessage()
            ]);
        }
    }
}
