<?php

namespace App\Services\RobawsIntegration;

use App\Models\Document;
use App\Services\RobawsIntegration\RobawsFieldMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsIntegrationService
{
    public function __construct(
        private RobawsFieldMapper $fieldMapper
    ) {}
    
    /**
     * Process document for Robaws integration
     */
    public function processDocument(Document $document, array $extractedData): bool
    {
        return DB::transaction(function () use ($document, $extractedData) {
            try {
                Log::info('Processing document for Robaws integration', [
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                ]);
                
                // Map the extracted data to Robaws format
                $robawsData = $this->fieldMapper->mapToRobawsFormat($extractedData);
                
                // Validate the mapped data
                $validation = $this->fieldMapper->validateMappedData($robawsData);
                
                if (!$validation['valid']) {
                    Log::warning('Robaws data validation failed', [
                        'document_id' => $document->id,
                        'errors' => $validation['errors'],
                        'warnings' => $validation['warnings'],
                    ]);
                    
                    // Still save it but mark as needing review
                    $robawsData['validation_errors'] = $validation['errors'];
                    $robawsData['validation_warnings'] = $validation['warnings'];
                }
                
                // Store the formatted data
                $document->update([
                    'robaws_quotation_data' => $robawsData,
                    'robaws_formatted_at' => now(),
                    'robaws_sync_status' => $validation['valid'] ? 'ready' : 'needs_review'
                ]);
                
                Log::info('Document processed for Robaws successfully', [
                    'document_id' => $document->id,
                    'sync_status' => $validation['valid'] ? 'ready' : 'needs_review',
                    'has_customer' => !empty($robawsData['customer']),
                    'has_routing' => !empty($robawsData['por']) && !empty($robawsData['pod']),
                    'has_cargo' => !empty($robawsData['cargo']),
                    'validation_errors' => count($validation['errors']),
                    'validation_warnings' => count($validation['warnings']),
                ]);
                
                return true;
                
            } catch (\Exception $e) {
                Log::error('Failed to process document for Robaws', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return false;
            }
        });
    }
    
    /**
     * Process a document using its latest extraction
     */
    public function processDocumentFromExtraction(Document $document): bool
    {
        $extraction = $document->extractions()->latest()->first();
        
        if (!$extraction || (!$extraction->extracted_data && !$extraction->raw_json)) {
            Log::warning('No extraction data found for document', [
                'document_id' => $document->id,
                'extraction_id' => $extraction->id ?? null
            ]);
            return ['success' => false, 'message' => 'No extraction data available'];
        }

        // Get extraction data - prefer raw_json (transformed data) over extracted_data
        $extractedData = null;
        if ($extraction->raw_json) {
            $extractedData = is_array($extraction->raw_json) 
                ? $extraction->raw_json 
                : json_decode($extraction->raw_json, true);
        } elseif ($extraction->extracted_data) {
            $extractedData = is_array($extraction->extracted_data) 
                ? $extraction->extracted_data 
                : json_decode($extraction->extracted_data, true);
        }
        
        if (!$extractedData) {
            Log::warning('Failed to decode extraction data', [
                'document_id' => $document->id,
                'extraction_id' => $extraction->id
            ]);
            return ['success' => false, 'message' => 'Invalid extraction data format'];
        }
        
        Log::info('Processing extraction data for Robaws', [
            'document_id' => $document->id,
            'extraction_id' => $extraction->id,
            'has_json_field' => isset($extractedData['JSON']),
            'field_count' => count($extractedData),
            'sample_fields' => array_slice(array_keys($extractedData), 0, 10)
        ]);
            
        return $this->processDocument($document, $extractedData);
    }
    
    /**
     * Get all documents ready for Robaws export
     */
    public function getDocumentsReadyForExport(): \Illuminate\Database\Eloquent\Collection
    {
        return Document::where('robaws_sync_status', 'ready')
            ->whereNotNull('robaws_quotation_data')
            ->get();
    }
    
    /**
     * Get documents that need review
     */
    public function getDocumentsNeedingReview(): \Illuminate\Database\Eloquent\Collection
    {
        return Document::where('robaws_sync_status', 'needs_review')
            ->whereNotNull('robaws_quotation_data')
            ->get();
    }
    
    /**
     * Export document data in Robaws-compatible JSON format
     */
    public function exportDocumentForRobaws(Document $document): ?array
    {
        if (!$document->robaws_quotation_data) {
            return null;
        }

        $data = is_array($document->robaws_quotation_data) 
            ? $document->robaws_quotation_data 
            : json_decode($document->robaws_quotation_data, true);

        return [
            'quotation_data' => $data,
            'document_info' => [
                'id' => $document->id,
                'filename' => $document->filename,
                'processed_at' => $document->robaws_formatted_at?->toIso8601String(),
            ],
            'export_timestamp' => now()->toIso8601String(),
        ];
    }
    
    /**
     * Mark document as manually synced to Robaws
     */
    public function markAsManuallySynced(Document $document, ?string $robawsQuotationId = null): bool
    {
        try {
            $updateData = [
                'robaws_sync_status' => 'synced',
                'robaws_synced_at' => now(),
            ];
            
            if ($robawsQuotationId) {
                $updateData['robaws_quotation_id'] = $robawsQuotationId;
            }
            
            $document->update($updateData);
            
            Log::info('Document marked as manually synced to Robaws', [
                'document_id' => $document->id,
                'robaws_quotation_id' => $robawsQuotationId,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to mark document as synced', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Get summary of Robaws integration status
     */
    public function getIntegrationSummary(): array
    {
        $totalDocuments = Document::whereHas('extractions', function ($q) {
            $q->where('status', 'completed');
        })->count();
        
        $readyForSync = Document::where('robaws_sync_status', 'ready')->count();
        $needsReview = Document::where('robaws_sync_status', 'needs_review')->count();
        $synced = Document::where('robaws_sync_status', 'synced')->count();
        
        return [
            'total_documents' => $totalDocuments,
            'ready_for_sync' => $readyForSync,
            'needs_review' => $needsReview,
            'synced' => $synced,
            'pending' => $totalDocuments - $readyForSync - $needsReview - $synced,
            'success_rate' => $totalDocuments > 0 ? round(($readyForSync + $synced) / $totalDocuments * 100, 1) : 0,
        ];
    }
    
    /**
     * Generate a downloadable JSON file for manual Robaws import
     */
    public function generateExportFile(): array
    {
        $documents = $this->getDocumentsReadyForExport();
        $exportData = [];

        foreach ($documents as $document) {
            $exportData[] = $this->exportDocumentForRobaws($document);
        }

        return [
            'export_metadata' => [
                'generated_at' => now()->toIso8601String(),
                'document_count' => count($exportData),
                'export_version' => '2.0',
            ],
            'quotations' => $exportData,
        ];
    }
}
