<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\IntakeFile;
use App\Services\ExtractionService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractDocumentDataJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for extraction
    public $tries = 3;
    public $backoff = [60, 120, 240];

    public function __construct(
        private Document $document
    ) {}

    public function handle(ExtractionService $extractionService): void
    {
        Log::info('Starting document data extraction', [
            'document_id' => $this->document->id,
            'filename' => $this->document->filename,
            'intake_id' => $this->document->intake_id
        ]);

        try {
            $this->document->refresh(); // Ensure we have the latest state

            // Find the corresponding IntakeFile
            $intakeFile = IntakeFile::where('intake_id', $this->document->intake_id)
                ->where('filename', $this->document->filename)
                ->first();

            if (!$intakeFile) {
                Log::warning('No IntakeFile found for document extraction', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename
                ]);
                return;
            }

            // Extract data from the file
            $extractedData = $extractionService->extractFromFile($intakeFile);
            
            if ($extractedData) {
                // Update document with extracted data
                $this->document->update([
                    'extraction_data' => $extractedData,
                    'extraction_confidence' => $extractedData['confidence'] ?? null,
                    'extraction_service' => $extractedData['service'] ?? 'ai',
                    'extraction_status' => 'completed',
                    'extracted_at' => now(),
                ]);

                // Update intake with contact data if available
                $intake = $this->document->intake;
                if (isset($extractedData['contact'])) {
                    $contactData = $extractedData['contact'];
                    $intake->update([
                        'customer_name' => $contactData['name'] ?? $intake->customer_name,
                        'contact_email' => $contactData['email'] ?? $intake->contact_email,
                        'contact_phone' => $contactData['phone'] ?? $intake->contact_email,
                        'extraction_data' => $extractedData,
                    ]);
                }

                Log::info('Document data extraction completed successfully', [
                    'document_id' => $this->document->id,
                    'extraction_confidence' => $extractedData['confidence'] ?? null,
                    'has_contact_data' => isset($extractedData['contact'])
                ]);
            } else {
                Log::warning('No data extracted from document', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename
                ]);
                
                $this->document->update([
                    'extraction_status' => 'failed',
                    'extracted_at' => now(),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Document data extraction failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->document->update([
                'extraction_status' => 'failed',
                'extracted_at' => now(),
            ]);
            
            throw $e;
        }
    }
}

