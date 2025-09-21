<?php

namespace App\Services\RobawsIntegration;

use App\Models\Document;
use App\Services\RobawsIntegration\JsonFieldMapper;
use App\Services\RobawsIntegration\RobawsDataValidator;
use App\Services\RobawsClient;
use App\Services\MultiDocumentUploadService;
use App\Services\DocumentConversion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Arr;

class EnhancedRobawsIntegrationService
{
    public function __construct(
        private JsonFieldMapper $fieldMapper,
        private RobawsClient $robawsClient,
        private MultiDocumentUploadService $uploadService,
        private DocumentConversion $documentConversion
    ) {}
    
    /**
     * Public API kept for drop-in replacement of the old service.
     * Single-source offer creation method.
     */
    public function createOfferFromDocument(Document $document): array
    {
        // Protect against concurrent creators
        $document->refresh();
        
        // Idempotency: if already created, just return the id
        if ($document->robaws_quotation_id) {
            return ['id' => $document->robaws_quotation_id];
        }

        $mapped = $document->robaws_quotation_data ?? [];
        if (empty($mapped)) {
            // Ensure mapping exists
            $this->processDocumentFromExtraction($document);
            $document->refresh();
            $mapped = $document->robaws_quotation_data ?? [];
        }

        // Minimal CREATE (no extraFields here)
        $payload = [
            'title'           => null, // Leave Concerning field empty for future implementation
            'clientReference' => $mapped['customer_reference'] ?? null,
            'date'            => now()->toDateString(),
            'clientId'        => $this->resolveClientId($mapped),
            'currency'        => 'EUR',
            'companyId'       => config('services.robaws.default_company_id', config('services.robaws.company_id')),
            'status'          => 'Draft',
        ];

        Log::channel('robaws')->info('Robaws CREATE offer', ['payload' => $payload]);
        $created = $this->robawsClient->createOffer($payload);
        $offerId = $created['id'] ?? null;
        if (!$offerId) {
            throw new \RuntimeException('Robaws createOffer returned no id');
        }

        // GET → merge extraFields → PUT full model
        $remote  = $this->robawsClient->getOffer($offerId);
        $updated = $this->stripOfferReadOnly($remote);
        $updated['extraFields'] = array_merge($remote['extraFields'] ?? [], $this->buildExtraFieldsFromMapped($mapped));

        Log::channel('robaws')->info('Robaws UPDATE offer (extraFields)', [
            'offer_id' => $offerId,
            'labels'   => array_keys($updated['extraFields'] ?? []),
        ]);
        $this->robawsClient->updateOffer($offerId, $updated);
        
        // Verify the update by pulling and logging key fields
        $after = $this->robawsClient->getOffer($offerId);
        $L = config('services.robaws.labels');
        Log::channel('robaws')->info('Offer updated & verified', [
            'offer_id' => $offerId,
            'por' => $after['extraFields'][$L['por']]['stringValue'] ?? null,
            'pol' => $after['extraFields'][$L['pol']]['stringValue'] ?? null,
            'pod' => $after['extraFields'][$L['pod']]['stringValue'] ?? null,
            'cargo' => $after['extraFields'][$L['cargo']]['stringValue'] ?? null,
        ]);

        // Save IDs, status
        $document->update([
            'robaws_quotation_id' => $offerId,
            'robaws_client_id'    => $payload['clientId'],
            'robaws_sync_status'  => 'synced',
            'robaws_synced_at'    => now(),
        ]);

        // Optional: upload source doc
        try {
            $this->uploadService->uploadDocumentToQuotation($document);
        } catch (\Throwable $e) {
            Log::channel('robaws')->warning('Upload failed; offer created', [
                'document_id' => $document->id, 'error' => $e->getMessage()
            ]);
        }

        // Propagate id to extraction (to trigger observers)
        $extraction = $document->extractions()->latest()->first();
        if ($extraction) {
            $extraction->update(['robaws_quotation_id' => $offerId]);
        }

        return ['id' => $offerId];
    }

    /**
     * Keep labels identical to Robaws tenant labels
     */
    private function buildExtraFieldsFromMapped(array $m): array
    {
        $L = config('services.robaws.labels', []);
        $xf = [];
        $put = function (string $key, $value, string $type = 'stringValue') use (&$xf, $L) {
            if ($value === null || $value === '') return;
            $label = $L[$key] ?? $key;              // ← map through config
            $xf[$label] = [$type => (string) $value];
        };

        // Quotation info
        $put('customer', $m['customer'] ?? null);
        $put('contact', $m['contact'] ?? null);
        $put('endcustomer', $m['endcustomer'] ?? null);
        $put('customer_reference', $m['customer_reference'] ?? null);

        // Routing
        $put('por', $m['por'] ?? null);
        $put('pol', $m['pol'] ?? null);
        $put('pot', $m['pot'] ?? null);
        $put('pod', $m['pod'] ?? null);
        $put('fdest', $m['fdest'] ?? null);

        // Cargo
        $put('cargo', $m['cargo'] ?? null);
        $put('dim_bef_delivery', $m['dim_bef_delivery'] ?? null);

        // Raw JSON — handy in the Robaws JSON field
        if (!empty($m['JSON'])) {
            $jsonLabel = $L['json'] ?? 'JSON';
            $xf[$jsonLabel] = ['stringValue' => $m['JSON']];
        }
        return $xf;
    }

    /**
     * Strip offer read-only fields to avoid 415/422 errors
     */
    private function stripOfferReadOnly(array $offer): array
    {
        unset($offer['id'], $offer['createdAt'], $offer['updatedAt'], $offer['links'], $offer['number']);
        return $offer;
    }

    /**
     * Resolve client ID from mapped data
     */
    private function resolveClientId(array $mapped): string|int
    {
        $name  = $mapped['customer'] ?? 'Unknown Client';
        $email = $mapped['client_email'] ?? null;
        $tel   = $mapped['contact'] ?? null;

        $client = $this->robawsClient->findOrCreateClient([
            'name'  => $name,
            'email' => $email,
            'tel'   => $tel,
            'address' => ['country' => 'BE'],
        ]);

        if (!isset($client['id'])) {
            throw new \RuntimeException('Robaws client creation failed: missing id');
        }

        return $client['id'];
    }

    /**
     * Process document for Robaws integration using JSON mapping
     */
    public function processDocument(Document $document, array $extractedData): bool
    {
        // Idempotency guard: if quotation already exists, skip
        if ($document->robaws_quotation_id) {
            Log::channel('robaws')->info('Offer already exists; skipping creation', [
                'document_id' => $document->id,
                'quotation_id' => $document->robaws_quotation_id,
            ]);
            return true;
        }
        
        // Guard against reprocessing downgrades
        $existing = $document->robaws_quotation_data ?? [];
        $alreadyHasRouting = !($this->isBlank($existing['por'] ?? null)
            || $this->isBlank($existing['pod'] ?? null)); // we consider POR+POD sufficient

        if ($alreadyHasRouting && in_array($document->robaws_sync_status, ['ready','synced'], true)) {
            Log::channel('robaws')->info('Skipping reprocessing: routing already present', [
                'document_id' => $document->id,
                'por' => $existing['por'] ?? 'NULL',
                'pod' => $existing['pod'] ?? 'NULL',
                'sync_status' => $document->robaws_sync_status
            ]);
            return true; // idempotent no-op
        }

        $result = DB::transaction(function () use ($document, $extractedData, $existing) {
            try {
                Log::channel('robaws')->info('Processing document with JSON field mapping', [
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                    'has_json_field' => isset($extractedData['JSON']),
                    'field_count' => count($extractedData),
                    'data_keys' => array_slice(array_keys($extractedData), 0, 15),
                    'json_length' => isset($extractedData['JSON']) && is_string($extractedData['JSON']) ? strlen($extractedData['JSON']) : 0
                ]);

                // PHASE 1 STEP 2: Validate and handle data structure
                if (!isset($extractedData['JSON']) && isset($extractedData['data']) && isset($extractedData['data']['JSON'])) {
                    Log::channel('robaws')->info('Found JSON field in nested data structure, extracting it', [
                        'document_id' => $document->id
                    ]);
                    $extractedData = $extractedData['data'];
                }

                // Ensure JSON field exists
                if (!isset($extractedData['JSON'])) {
                    Log::channel('robaws')->warning('JSON field missing in extracted data', [
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
                    
                    Log::channel('robaws')->info('Created fallback JSON field', [
                        'document_id' => $document->id,
                        'json_length' => strlen($extractedData['JSON'])
                    ]);
                }
                
                // Map the extracted data using JSON configuration
                // Include existing robaws data as sources to preserve existing values
                $existingRobawsData = $document->robaws_quotation_data ?? [];
                $enrichedData = array_merge($extractedData, $existingRobawsData);
                
                $robawsData = $this->fieldMapper->mapFields($enrichedData);
                
                // Normalize blanks so needs_* checks fire
                $robawsData = $this->normalizeBlankValues($robawsData);
                
                // Debug: Log the data BEFORE backfill
                Log::channel('robaws')->info('Routing BEFORE mapping', [
                    'document_id' => $document->id,
                    'existing' => Arr::only($existing, ['por','pol','pod','customer_reference']),
                ]);
                
                // Backfill routing from text when missing (use enriched data for scanning)
                $robawsData = $this->backfillRoutingFromText($robawsData, $robawsData);
                
                // Debug: Log the data after backfill
                Log::channel('robaws')->info('Routing AFTER mapping', [
                    'document_id' => $document->id,
                    'customer_reference' => $robawsData['customer_reference'] ?? 'NULL',
                    'por' => $robawsData['por'] ?? 'NULL',
                    'pol' => $robawsData['pol'] ?? 'NULL',
                    'pod' => $robawsData['pod'] ?? 'NULL',
                ]);
                
                // Sanitize NOT_FOUND sentinels before saving
                $robawsData = array_map(
                    fn($v) => (is_string($v) && in_array(strtoupper(trim($v)), ['NOT_FOUND', 'N/A'])) ? null : $v,
                    $robawsData
                );
                
                // Overlay non-blank values only (preserve existing good values)
                $final = $this->overlayKeepNonBlank($existing, $robawsData);
                
                // Validate the mapped data using shared validator
                $validationResult = RobawsDataValidator::validate($final);
                
                // Determine sync status based on validation
                $syncStatus = $validationResult['is_valid'] ? 'ready' : 'needs_review';
                
                // Store the formatted data
                $document->update([
                    'robaws_quotation_data' => $final,
                    'robaws_formatted_at' => now(),
                    'robaws_sync_status' => $syncStatus,
                ]);
                
                // Debug: Log final saved values to prove they're intact
                Log::channel('robaws')->info('Routing FINAL (saved)', [
                    'document_id' => $document->id,
                    'saved_data' => Arr::only($final, ['por','pol','pod','customer_reference']),
                ]);
                
                Log::channel('robaws')->info('Document formatted for Robaws using JSON mapping', [
                    'document_id' => $document->id,
                    'mapping_version' => $final['mapping_version'] ?? 'unknown',
                    'sync_status' => $syncStatus,
                    'validation_errors' => count($validationResult['errors']),
                    'validation_warnings' => count($validationResult['warnings']),
                    'has_customer' => !empty($final['customer']),
                    'has_routing' => !empty($final['por']) && !empty($final['pod']),
                    'has_cargo' => !empty($final['cargo']),
                    'status_reason' => !$validationResult['is_valid'] ? ($validationResult['errors'][0] ?? 'validation failed') : null,
                ]);
                
                return [
                    'sync_status' => $syncStatus,
                ];
                
            } catch (\Exception $e) {
                Log::channel('robaws')->error('Failed to process document for Robaws with JSON mapping', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return [
                    'sync_status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        });

        // External API calls AFTER transaction commits
        if (($result['sync_status'] ?? null) === 'ready' && !$document->robaws_quotation_id) {
            Log::channel('robaws')->info('Document ready for Robaws - creating offer now', [
                'document_id' => $document->id,
            ]);

            try {
                $createResult = $this->createOfferFromDocument($document);
                
                Log::channel('robaws')->info('Offer created via Enhanced service', [
                    'document_id' => $document->id,
                    'robaws_offer_id' => $createResult['id'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::channel('robaws')->error('Enhanced createOffer failed', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);
                $document->update([
                    'robaws_sync_status'     => 'failed',
                    'robaws_last_sync_attempt'=> now(),
                ]);
            }
        }

        return ($result['sync_status'] ?? 'failed') !== 'failed';
    }
    
    /**
     * Process a document using its latest extraction
     */
    public function processDocumentFromExtraction(Document $document): bool
    {
        $extraction = $document->extractions()->latest()->first();
        
        if (!$extraction || !$extraction->extracted_data) {
            Log::channel('robaws')->warning('No extraction data found for document', [
                'document_id' => $document->id,
            ]);
            
            // If document needs OCR, try to extract text
            if ($this->documentConversion->needsOcr($document)) {
                return $this->processDocumentWithOcr($document);
            }
            
            return false;
        }
        
        $extractedData = is_array($extraction->extracted_data) 
            ? $extraction->extracted_data 
            : json_decode($extraction->extracted_data, true);
            
        return $this->processDocument($document, $extractedData);
    }

    /**
     * Process document with OCR when no extraction data exists
     */
    private function processDocumentWithOcr(Document $document): bool
    {
        try {
            Log::channel('robaws')->info('Processing document with OCR', [
                'document_id' => $document->id,
                'has_text_layer' => $document->has_text_layer
            ]);

            // Run OCR to extract text
            $originalPath = $this->getDocumentPath($document);
            $ocrText = $this->documentConversion->runOcr($originalPath);
            
            if (empty($ocrText)) {
                Log::channel('robaws')->warning('OCR produced no text', [
                    'document_id' => $document->id
                ]);
                return false;
            }

            // Create extraction data structure with OCR text
            $extractedData = [
                'ocr_text' => $ocrText,
                'extraction_method' => 'ocr',
                'has_text_layer' => $document->has_text_layer,
                'document_type' => $document->mime_type,
                'JSON' => json_encode([
                    'document_id' => $document->id,
                    'ocr_extracted_text' => $ocrText,
                    'extraction_metadata' => [
                        'method' => 'ocr_fallback',
                        'extracted_at' => now()->toISOString(),
                        'original_filename' => $document->filename
                    ]
                ], JSON_PRETTY_PRINT)
            ];

            // Process with OCR-extracted data
            return $this->processDocument($document, $extractedData);

        } catch (\Throwable $e) {
            Log::channel('robaws')->error('OCR processing failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get document file path
     */
    private function getDocumentPath(Document $document): string
    {
        return Storage::disk($document->storage_disk)->path($document->file_path);
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
     * Check if a value is truly blank/empty
     */
    private function isBlank(mixed $v): bool
    {
        if ($v === null) return true;
        if (is_string($v)) {
            $t = trim($v);
            return $t === '' || strtoupper($t) === 'NULL' || strtoupper($t) === 'NOT_FOUND';
        }
        if (is_array($v)) return count($v) === 0;
        return false;
    }

    /**
     * Overlay incoming values but preserve existing non-blank values
     */
    private function overlayKeepNonBlank(array $existing, array $incoming): array
    {
        foreach ($incoming as $k => $v) {
            // keep incoming if it's non-blank; otherwise keep existing
            if (!$this->isBlank($v)) {
                $existing[$k] = $v;
            }
        }
        return $existing;
    }

    /**
     * Normalize blank values to ensure template fallbacks trigger
     */
    private function normalizeBlankValues(array $data): array
    {
        // Convert empty strings and 'NULL' strings to null so "needs_*" checks fire properly
        return array_map(
            fn($v) => (is_string($v) && (trim($v) === '' || strtoupper(trim($v)) === 'NULL')) ? null : $v,
            $data
        );
    }

    /**
     * Backfill routing information from text when POR/POL/POD are missing
     */
    private function backfillRoutingFromText(array $data, array $extracted): array
    {
        $needsPor = $this->isBlank($data['por'] ?? null);
        $needsPod = $this->isBlank($data['pod'] ?? null);
        $needsPol = $this->isBlank($data['pol'] ?? null);

        if (!($needsPor || $needsPod || $needsPol)) {
            return $data; // nothing to do
        }

        Log::channel('robaws')->info('Backfilling routing from text', [
            'needs_por' => $needsPor,
            'needs_pol' => $needsPol,
            'needs_pod' => $needsPod,
            'has_customer_reference' => !empty($data['customer_reference'])
        ]);

        // Build candidate texts — include mapped customer_reference first
        $candidates = array_filter([
            $data['customer_reference'] ?? null,
            data_get($extracted, 'email_metadata.subject'),
            data_get($extracted, 'title'),
            data_get($extracted, 'description'),
            data_get($extracted, 'concerning'),
        ], fn($v) => !$this->isBlank($v));

        // As a last resort, scan the whole extracted blob (cheap and effective)
        if (!empty($extracted) && (is_array($extracted) || is_string($extracted))) {
            $blob = is_string($extracted) ? $extracted : json_encode($extracted, JSON_UNESCAPED_UNICODE);
            if ($blob) $candidates[] = $blob;
        }

        foreach ($candidates as $text) {
            $codes = $this->extractIataCodes((string)$text);

            // Filter out junk codes that don't map to a city (drops EXP, BMW…)
            $codes = array_values(array_filter($codes, fn($c) => (bool) $this->codeToCity($c)));

            if (count($codes) < 2) {
                continue;
            }

            [$o, $d] = [$codes[0], $codes[1]];
            $porCity = $this->codeToCity($o);
            $podCity = $this->codeToCity($d);

            Log::channel('robaws')->info('Found routing codes in text', [
                'text' => substr($text, 0, 120),
                'codes' => $codes,
                'origin_code' => $o,
                'dest_code' => $d,
                'por_city' => $porCity,
                'pod_city' => $podCity
            ]);

            $changed = false;

            if ($needsPor && $porCity) {
                $data['por'] = $porCity;
                $changed = true;
            }
            if ($needsPod && $podCity) {
                $data['pod'] = $podCity;
                $changed = true;
            }
            if ($needsPol && $porCity) {
                $data['pol'] = $this->cityToPort($porCity) ?? $porCity;
                $changed = true;
            }

            // Optional third code as POT
            if ($this->isBlank($data['pot'] ?? null) && isset($codes[2])) {
                $potCity = $this->codeToCity($codes[2]);
                if ($potCity) {
                    $data['pot'] = $potCity;
                    $changed = true;
                }
            }

            if ($changed) {
                Log::channel('robaws')->info('Backfilled routing data', [
                    'por' => $data['por'] ?? null,
                    'pol' => $data['pol'] ?? null,
                    'pod' => $data['pod'] ?? null,
                    'pot' => $data['pot'] ?? null
                ]);
                break; // first viable text is enough
            }
        }

        return $data;
    }

    /**
     * Extract IATA-like 3-letter codes from text
     */
    private function extractIataCodes(string $text): array
    {
        // match 3-letter uppers delimited by start/space/dash and end/space/dash
        preg_match_all('/(?<=^|[\s\-])([A-Z]{3})(?=$|[\s\-])/u', strtoupper($text), $m);
        // de-duplicate, keep order
        return array_values(array_unique($m[1] ?? []));
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
