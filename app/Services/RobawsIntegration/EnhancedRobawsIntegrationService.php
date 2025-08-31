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
                
                // Backfill routing from text when missing
                $robawsData = $this->backfillRoutingFromText($robawsData, $extractedData);
                
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

    /**
     * Backfill routing information from text when POR/POL/POD are missing
     */
    private function backfillRoutingFromText(array $data, array $extracted): array
    {
        $needsPor = empty($data['por']);
        $needsPod = empty($data['pod']);
        $needsPol = empty($data['pol']);

        if (!($needsPor || $needsPod || $needsPol)) {
            return $data; // nothing to do
        }

        Log::info('Backfilling routing from text', [
            'needs_por' => $needsPor,
            'needs_pol' => $needsPol,
            'needs_pod' => $needsPod,
            'has_customer_reference' => !empty($data['customer_reference'])
        ]);

        $candidates = array_filter([
            $data['customer_reference'] ?? null,
            data_get($extracted, 'email_metadata.subject'),
            data_get($extracted, 'title'),
            data_get($extracted, 'description'),
            data_get($extracted, 'concerning'),
        ]);

        foreach ($candidates as $text) {
            if (empty($text)) continue;
            
            $codes = $this->extractIataCodes((string)$text);
            if (count($codes) < 2) {
                continue;
            }

            [$o, $d] = [$codes[0], $codes[1]];
            $porCity = $this->codeToCity($o);
            $podCity = $this->codeToCity($d);

            Log::info('Found routing codes in text', [
                'text' => substr($text, 0, 100),
                'codes' => $codes,
                'origin_code' => $o,
                'dest_code' => $d,
                'por_city' => $porCity,
                'pod_city' => $podCity
            ]);

            if ($needsPor && $porCity) {
                $data['por'] = $porCity;
            }
            if ($needsPod && $podCity) {
                $data['pod'] = $podCity;
            }
            if ($needsPol && $porCity) {
                $data['pol'] = $this->cityToPort($porCity) ?? $porCity;
            }

            // optional third code as POT
            if (empty($data['pot']) && isset($codes[2])) {
                $potCity = $this->codeToCity($codes[2]);
                if ($potCity) {
                    $data['pot'] = $potCity;
                }
            }

            Log::info('Backfilled routing data', [
                'por' => $data['por'] ?? null,
                'pol' => $data['pol'] ?? null,
                'pod' => $data['pod'] ?? null,
                'pot' => $data['pot'] ?? null
            ]);

            break; // first usable text is enough
        }

        return $data;
    }

    /**
     * Extract IATA-like 3-letter codes from text
     */
    private function extractIataCodes(string $text): array
    {
        preg_match_all('/\b[A-Z]{3}\b/', strtoupper($text), $m);
        return $m[0] ?? [];
    }

    /**
     * Convert IATA code to city name
     */
    private function codeToCity(string $code): ?string
    {
        $map = [
            // extend as needed for your traffic
            'BRU' => 'Brussels',
            'ANR' => 'Antwerp',
            'JED' => 'Jeddah',
            'DXB' => 'Dubai',
            'RTM' => 'Rotterdam',
            'HAM' => 'Hamburg',
            'LEH' => 'Le Havre',
            'POR' => 'Portsmouth',
            'DOV' => 'Dover',
            'LON' => 'London',
            'PAR' => 'Paris',
            'FRA' => 'Frankfurt',
            'MUN' => 'Munich',
            'MIL' => 'Milano',
            'MAD' => 'Madrid',
            'BAR' => 'Barcelona',
            'AMS' => 'Amsterdam',
            'GEN' => 'Genoa',
            'VAL' => 'Valencia',
        ];
        return $map[strtoupper($code)] ?? null;
    }

    /**
     * Convert city name to port name
     */
    private function cityToPort(string $city): ?string
    {
        $map = [
            'Brussels'   => 'Antwerp',
            'Bruxelles'  => 'Antwerp',
            'Paris'      => 'Le Havre',
            'Frankfurt'  => 'Hamburg',
            'Munich'     => 'Hamburg',
            'Milano'     => 'Genoa',
            'Madrid'     => 'Valencia',
            'Barcelona'  => 'Barcelona',
            'Amsterdam'  => 'Rotterdam',
            'London'     => 'Portsmouth',
            'Dover'      => 'Dover',
            'Jeddah'     => 'Jeddah',
            'Dubai'      => 'Dubai',
            'Hamburg'    => 'Hamburg',
            'Rotterdam'  => 'Rotterdam',
            'Antwerp'    => 'Antwerp',
        ];
        return $map[$city] ?? null;
    }
}
