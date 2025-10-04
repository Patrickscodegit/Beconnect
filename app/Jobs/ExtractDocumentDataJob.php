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
                Log::error('No IntakeFile found for document extraction', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename,
                    'intake_id' => $this->document->intake_id,
                    'available_intake_files' => IntakeFile::where('intake_id', $this->document->intake_id)->pluck('filename')->toArray()
                ]);
                
                $this->document->update([
                    'extraction_status' => 'failed',
                    'extracted_at' => now(),
                ]);
                return;
            }

            Log::info('Found IntakeFile for extraction', [
                'document_id' => $this->document->id,
                'intake_file_id' => $intakeFile->id,
                'storage_path' => $intakeFile->storage_path,
                'storage_disk' => $intakeFile->storage_disk,
                'file_size' => $intakeFile->file_size
            ]);

            // Extract data from the file
            Log::info('Calling extraction service', [
                'document_id' => $this->document->id,
                'intake_file_id' => $intakeFile->id,
                'extraction_service_class' => get_class($extractionService)
            ]);
            
            $extractedData = $extractionService->extractFromFile($intakeFile);
            
            Log::info('Extraction service result', [
                'document_id' => $this->document->id,
                'extraction_successful' => !empty($extractedData),
                'extracted_data_keys' => $extractedData ? array_keys($extractedData) : [],
                'extracted_data_size' => $extractedData ? strlen(json_encode($extractedData)) : 0
            ]);
            
            if ($extractedData) {
                // Update document with extracted data
                $this->document->update([
                    'extraction_data' => $extractedData,
                    'extraction_confidence' => $extractedData['confidence'] ?? null,
                    'extraction_service' => $extractedData['service'] ?? 'ai',
                    'extraction_status' => 'completed',
                    'extracted_at' => now(),
                ]);

                // Map the extracted data to Robaws fields with textarea formatting
                $rawData = $extractedData['raw_data'] ?? [];
                if (!empty($rawData)) {
                    $mapper = app('App\Services\RobawsIntegration\JsonFieldMapper');
                    $mapped = $mapper->mapFields($rawData);
                    
                    // Update document with mapped data
                    $this->document->update([
                        'robaws_quotation_data' => $mapped,
                        'robaws_formatted_at' => now(),
                        'robaws_sync_status' => 'ready'
                    ]);
                    
                    Log::info('Document mapped to Robaws fields', [
                        'document_id' => $this->document->id,
                        'mapped_fields_count' => count($mapped),
                        'has_consignee' => isset($mapped['consignee']),
                        'has_notify' => isset($mapped['notify']),
                        'consignee_has_newlines' => isset($mapped['consignee']) && is_string($mapped['consignee']) && strpos($mapped['consignee'], "\n") !== false,
                        'notify_has_newlines' => isset($mapped['notify']) && is_string($mapped['notify']) && strpos($mapped['notify'], "\n") !== false
                    ]);
                }

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
                    'has_contact_data' => isset($extractedData['contact']),
                    'has_mapped_data' => !empty($rawData)
                ]);
            } else {
                Log::error('No data extracted from document', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename,
                    'intake_file_id' => $intakeFile->id,
                    'storage_path' => $intakeFile->storage_path,
                    'storage_disk' => $intakeFile->storage_disk,
                    'file_size' => $intakeFile->file_size,
                    'extraction_service_class' => get_class($extractionService)
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

