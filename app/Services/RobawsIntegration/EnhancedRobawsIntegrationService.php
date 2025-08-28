<?php

namespace App\Services\RobawsIntegration;

use App\Models\Document;
use App\Services\RobawsIntegration\JsonFieldMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnhancedRobawsIntegrationService
{
    public function __construct(
        private JsonFieldMapper $fieldMapper
    ) {}
    
    /**
     * Process document for Robaws integration using JSON mapping
     */
    public function processDocument(Document $document, array $extractedData): bool
    {
        return DB::transaction(function () use ($document, $extractedData) {
            try {
                Log::info('Processing document with JSON field mapping', [
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                ]);
                
                // Map the extracted data using JSON configuration
                $robawsData = $this->fieldMapper->mapFields($extractedData);
                
                // Validate the mapped data
                $validationResult = $this->validateRobawsData($robawsData);
                
                // Determine sync status based on validation
                $syncStatus = $validationResult['is_valid'] ? 'ready' : 'needs_review';
                
                // Store the formatted data
                $document->update([
                    'robaws_quotation_data' => $robawsData,
                    'robaws_formatted_at' => now(),
                    'robaws_sync_status' => $syncStatus,
                ]);
                
                Log::info('Document formatted for Robaws using JSON mapping', [
                    'document_id' => $document->id,
                    'mapping_version' => $robawsData['mapping_version'] ?? 'unknown',
                    'sync_status' => $syncStatus,
                    'validation_errors' => count($validationResult['errors']),
                    'validation_warnings' => count($validationResult['warnings']),
                    'has_customer' => !empty($robawsData['customer']),
                    'has_routing' => !empty($robawsData['por']) && !empty($robawsData['pod']),
                    'has_cargo' => !empty($robawsData['cargo']),
                ]);
                
                return true;
                
            } catch (\Exception $e) {
                Log::error('Failed to process document for Robaws with JSON mapping', [
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
        
        if (!$extraction || !$extraction->extracted_data) {
            Log::warning('No extraction data found for document', [
                'document_id' => $document->id,
            ]);
            return false;
        }
        
        $extractedData = is_array($extraction->extracted_data) 
            ? $extraction->extracted_data 
            : json_decode($extraction->extracted_data, true);
            
        return $this->processDocument($document, $extractedData);
    }
    
    /**
     * Validate mapped Robaws data
     */
    private function validateRobawsData(array $data): array
    {
        $errors = [];
        $warnings = [];
        
        // Required fields validation
        $requiredFields = ['customer', 'por', 'pod', 'cargo'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Optional but recommended fields
        $recommendedFields = ['client_email', 'customer_reference', 'dim_bef_delivery'];
        foreach ($recommendedFields as $field) {
            if (empty($data[$field])) {
                $warnings[] = "Missing recommended field: {$field}";
            }
        }
        
        // Validate email format if present
        if (!empty($data['client_email']) && !filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format: {$data['client_email']}";
        }
        
        // Check for routing information
        if (empty($data['por']) && empty($data['pod'])) {
            $errors[] = "Origin and destination are required";
        }
        
        // Check for cargo information
        if (empty($data['cargo']) || $data['cargo'] === '1 x Vehicle') {
            $warnings[] = "Generic cargo description detected";
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Export document with JSON mapping metadata
     */
    public function exportDocumentForRobaws(Document $document): ?array
    {
        if (!$document->robaws_quotation_data) {
            return null;
        }
        
        return [
            'bconnect_document' => [
                'id' => $document->id,
                'filename' => $document->filename,
                'uploaded_at' => $document->created_at->toISOString(),
                'processed_at' => $document->robaws_formatted_at?->toISOString(),
                'mapping_version' => $document->robaws_quotation_data['mapping_version'] ?? '1.0'
            ],
            'robaws_quotation' => $document->robaws_quotation_data,
            'validation_status' => $document->robaws_sync_status,
            'export_timestamp' => now()->toISOString(),
        ];
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
     * Get mapping configuration info
     */
    public function getMappingInfo(): array
    {
        return $this->fieldMapper->getMappingSummary();
    }
    
    /**
     * Reload JSON mapping configuration
     */
    public function reloadMappingConfiguration(): void
    {
        $this->fieldMapper->reloadConfiguration();
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
                'generated_at' => now()->toISOString(),
                'document_count' => count($exportData),
                'export_version' => '2.0',
                'mapping_version' => $this->fieldMapper->getMappingSummary()['version'] ?? '1.0',
            ],
            'quotations' => $exportData,
        ];
    }
}
