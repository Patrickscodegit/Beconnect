<?php

namespace App\Services\RobawsIntegration;

use App\Models\Document;
use App\Services\RobawsIntegration\JsonFieldMapper;
use App\Services\RobawsIntegration\RobawsDataValidator;
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
                    'has_json_field' => isset($extractedData['JSON']),
                    'field_count' => count($extractedData),
                    'data_keys' => array_slice(array_keys($extractedData), 0, 15),
                    'json_length' => isset($extractedData['JSON']) ? strlen($extractedData['JSON']) : 0
                ]);

                // PHASE 1 STEP 2: Validate and handle data structure
                if (!isset($extractedData['JSON']) && isset($extractedData['data']) && isset($extractedData['data']['JSON'])) {
                    Log::info('Found JSON field in nested data structure, extracting it', [
                        'document_id' => $document->id
                    ]);
                    $extractedData = $extractedData['data'];
                }

                // Ensure JSON field exists
                if (!isset($extractedData['JSON'])) {
                    Log::warning('JSON field missing in extracted data', [
                        'document_id' => $document->id,
                        'available_fields' => array_keys($extractedData)
                    ]);
                    
                    // Create JSON field if missing (fallback)
                    $extractedData['JSON'] = json_encode([
                        'document_id' => $document->id,
                        'extraction_data' => $extractedData,
                        'created_at' => now()->toIso8601String(),
                        'warning' => 'JSON field was missing and created as fallback'
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    
                    Log::info('Created fallback JSON field', [
                        'document_id' => $document->id,
                        'json_length' => strlen($extractedData['JSON'])
                    ]);
                }
                
                // Map the extracted data using JSON configuration
                $robawsData = $this->fieldMapper->mapFields($extractedData);
                
                // Validate the mapped data using shared validator
                $validationResult = RobawsDataValidator::validate($robawsData);
                
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

                // ENHANCED: Now actually create the offer in Robaws using the main service
                if ($syncStatus === 'ready') {
                    Log::info('Document ready for Robaws - creating offer now', [
                        'document_id' => $document->id,
                    ]);

                    try {
                        $mainService = app(\App\Services\RobawsIntegrationService::class);
                        $result = $mainService->createOfferFromDocument($document);

                        if ($result && isset($result['id'])) {
                            $quotationId = $result['id'];
                            
                            $document->update([
                                'robaws_sync_status' => 'synced',
                                'robaws_synced_at' => now(),
                                'robaws_quotation_id' => $quotationId,
                            ]);

                            // IMPORTANT: Update the extraction with the quotation ID
                            // This will trigger the ExtractionObserver to upload the document
                            $extraction = $document->extractions()->latest()->first();
                            if ($extraction) {
                                $extraction->update([
                                    'robaws_quotation_id' => $quotationId
                                ]);
                                
                                Log::info('Updated extraction with quotation ID - will trigger document upload', [
                                    'document_id' => $document->id,
                                    'extraction_id' => $extraction->id,
                                    'quotation_id' => $quotationId
                                ]);
                            }

                            Log::info('Enhanced Integration: Offer created in Robaws successfully', [
                                'document_id' => $document->id,
                                'robaws_offer_id' => $quotationId,
                                'extraction_updated' => !!$extraction
                            ]);
                        } else {
                            Log::warning('Enhanced Integration: Failed to create offer in Robaws', [
                                'document_id' => $document->id,
                                'result' => $result,
                            ]);

                            $document->update([
                                'robaws_sync_status' => 'failed',
                                'robaws_last_sync_attempt' => now(),
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Enhanced Integration: Error creating offer in Robaws', [
                            'document_id' => $document->id,
                            'error' => $e->getMessage(),
                        ]);

                        $document->update([
                            'robaws_sync_status' => 'failed',
                            'robaws_last_sync_attempt' => now(),
                        ]);
                    }
                }
                
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
     * Legacy wrapper for shared validator (for backward compatibility)
     */
    private function validateRobawsData(array $data): array
    {
        return RobawsDataValidator::validate($data);
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
