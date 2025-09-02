<?php

namespace App\Services;

use App\Models\IntakeFile;
use App\Models\Document;
use App\Services\Extraction\ExtractionPipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractionService
{
    public function __construct(
        private ExtractionPipeline $extractionPipeline
    ) {}

    /**
     * Extract data from an IntakeFile
     */
    public function extractFromFile(IntakeFile $file): ?array
    {
        Log::info('Extracting data from intake file', [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type
        ]);

        try {
            // Create a temporary Document model for the extraction pipeline
            // The existing pipeline expects Document models
            $tempDocument = new Document([
                'filename' => $file->filename,
                'file_path' => $file->storage_path,  // Map storage_path to file_path for compatibility
                'storage_disk' => $file->storage_disk,
                'mime_type' => $file->mime_type,
                'file_size' => $file->file_size,
            ]);

            // Set the ID so the extraction can find the file
            $tempDocument->id = 'temp_' . $file->id;

            // Ensure the file exists
            if (!Storage::disk($file->storage_disk)->exists($file->storage_path)) {
                Log::error('File not found for extraction', [
                    'file_id' => $file->id,
                    'storage_path' => $file->storage_path,
                    'storage_disk' => $file->storage_disk
                ]);
                return null;
            }

            // Run extraction
            $result = $this->extractionPipeline->process($tempDocument);

            if ($result->isSuccessful()) {
                Log::info('Extraction successful', [
                    'file_id' => $file->id,
                    'data_extracted' => !empty($result->getData())
                ]);
                
                return $this->formatExtractionData($result->getData(), $file);
            } else {
                Log::warning('Extraction failed', [
                    'file_id' => $file->id,
                    'error' => $result->getErrorMessage()
                ]);
                return null;
            }

        } catch (\Exception $e) {
            Log::error('Exception during file extraction', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Format extraction data for consistent structure
     */
    private function formatExtractionData(array $data, IntakeFile $file): array
    {
        // Extract contact data from nested structure (supports both flat and nested formats)
        $contactEmail = $data['contact_email'] ?? $data['contact']['email'] ?? null;
        $contactPhone = $data['contact_phone'] ?? $data['contact']['phone'] ?? null;
        $customerName = $data['customer_name'] ?? $data['contact']['name'] ?? null;
        
        return [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'customer_name' => $customerName,
            'contact' => $data['contact'] ?? [], // Preserve nested contact data for ProcessIntake
            'raw_data' => $data,
            'extracted_at' => now()->toISOString(),
        ];
    }
}
