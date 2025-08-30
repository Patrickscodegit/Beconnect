<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Quotation;
use Illuminate\Support\Facades\Log;

class RobawsIntegrationService
{
    public function __construct(
        private RobawsClient $robawsClient
    ) {}

    /**
     * Create a Robaws offer from extracted document data
     */
    public function createOfferFromDocument(Document $document): ?array
    {
        try {
            // Get the extraction record to access raw_json
            $extraction = $document->extractions()->first();
            
            Log::info('RobawsIntegrationService: Looking for extraction data', [
                'document_id' => $document->id,
                'extraction_found' => $extraction ? 'YES' : 'NO',
                'extraction_id' => $extraction ? $extraction->id : null,
                'has_raw_json' => $extraction && $extraction->raw_json ? 'YES' : 'NO',
                'raw_json_length' => $extraction && $extraction->raw_json ? strlen($extraction->raw_json) : 0,
                'has_document_raw_json' => $document->raw_json ? 'YES' : 'NO',
                'has_document_extraction_data' => $document->extraction_data ? 'YES' : 'NO',
            ]);
            
            // Prefer raw_json from extraction, then document raw_json, then extraction_data
            $extractedData = null;
            $dataSource = 'unknown';
            
            if ($extraction && $extraction->raw_json) {
                $extractedData = $extraction->raw_json;
                $dataSource = 'extraction.raw_json';
            } elseif ($document->raw_json) {
                $extractedData = $document->raw_json;
                $dataSource = 'document.raw_json';
            } else {
                $extractedData = $document->extraction_data;
                $dataSource = 'document.extraction_data';
            }
            
            if (empty($extractedData)) {
                Log::warning('No extraction data available for document', [
                    'document_id' => $document->id,
                    'checked_sources' => [
                        'extraction.raw_json' => $extraction && $extraction->raw_json ? 'available' : 'empty',
                        'document.raw_json' => $document->raw_json ? 'available' : 'empty',
                        'document.extraction_data' => $document->extraction_data ? 'available' : 'empty'
                    ]
                ]);
                return null;
            }

            // Ensure data is array format
            if (is_string($extractedData)) {
                $extractedData = json_decode($extractedData, true);
            }

            Log::info('Creating Robaws offer with enhanced data', [
                'document_id' => $document->id,
                'data_source' => $dataSource,
                'has_json_field' => isset($extractedData['JSON']),
                'json_field_length' => strlen($extractedData['JSON'] ?? ''),
                'field_count' => count($extractedData ?? [])
            ]);

            // Get the extraction record if available
            $extraction = $document->extractions()->first();

            // First, find or create the client in Robaws
            $client = $this->findOrCreateClientFromExtraction($extractedData);
            
            if (!$client) {
                Log::error('Failed to create/find client in Robaws');
                return null;
            }

            // Prepare the offer payload with extraction record
            $offerPayload = $this->buildOfferPayload($extractedData, $client['id'], $extraction);
            
            // Create the offer in Robaws
            $offer = $this->robawsClient->createOffer($offerPayload);
            
            // Save the Robaws offer ID back to our database
            $this->saveRobawsOffer($document, $offer);
            
            Log::info('Successfully created Robaws offer', [
                'document_id' => $document->id,
                'robaws_offer_id' => $offer['id'] ?? null
            ]);
            
            return $offer;
            
        } catch (\Exception $e) {
            Log::error('Error creating Robaws offer', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Build the offer payload for Robaws API
     */
    private function buildOfferPayload(array $extractedData, int $clientId, $extraction = null): array
    {
        // CRITICAL FIX: Use the actual JSON field from extracted data if available
        $jsonFieldContent = '';
        
        if (isset($extractedData['JSON']) && !empty($extractedData['JSON'])) {
            // Use the actual 6535-character JSON field from extraction
            $jsonFieldContent = $extractedData['JSON'];
            Log::info('Using original JSON field for Robaws export', [
                'json_length' => strlen($jsonFieldContent),
                'preview' => substr($jsonFieldContent, 0, 100) . '...'
            ]);
        } else {
            // Fallback: Build enhanced JSON structure if no original JSON field
            $enhancedJsonData = $this->buildEnhancedExtractionJson($extractedData, $extraction);
            $jsonFieldContent = json_encode($enhancedJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            Log::warning('Using fallback enhanced JSON for Robaws export', [
                'json_length' => strlen($jsonFieldContent)
            ]);
        }
        
        // Build line items from extracted data
        $lineItems = $this->buildLineItems($extractedData);
        
        return [
            'clientId' => $clientId,
            'name' => $this->generateOfferTitle($extractedData),
            'currency' => $extractedData['invoice']['currency'] ?? $extractedData['currency'] ?? 'EUR',
            'status' => 'DRAFT',
            
            // Push the actual JSON field content into the custom "JSON" field
            'extraFields' => [
                'JSON' => ['stringValue' => $jsonFieldContent],
            ],
            
            // Include line items if any
            'lineItems' => $lineItems,
            
            // Additional fields from extraction
            'validityDays' => 30,
            'paymentTermDays' => 30,
            'notes' => $this->extractNotes($extractedData),
        ];
    }

    /**
     * Build enhanced extraction JSON structure for Robaws custom field
     */
    private function buildEnhancedExtractionJson(array $extractedData, $extraction = null): array
    {
        // Use ContactFieldExtractor to get advanced contact data
        $contactResult = null;
        $contactInfo = null;
        $contactMetadata = [];
        
        try {
            $contactExtractor = new \App\Services\Extraction\Strategies\Fields\ContactFieldExtractor();
            $contactResult = $contactExtractor->extract($extractedData, '');
            
            // ContactFieldExtractor returns ContactInfo object directly
            if ($contactResult instanceof \App\Services\Extraction\ValueObjects\ContactInfo) {
                $contactInfo = $contactResult->toArray();
                $contactMetadata = []; // No metadata from direct ContactInfo
            } else {
                $contactInfo = null;
                $contactMetadata = [];
            }
        } catch (\Exception $e) {
            Log::warning('Contact extraction failed for Robaws export', ['error' => $e->getMessage()]);
            $contactInfo = null;
        }

        return [
            'extraction_metadata' => [
                'version' => '2.0',
                'extracted_at' => now()->toISOString(),
                'extraction_source' => 'bconnect_ai_pipeline',
                'confidence_score' => $extraction?->confidence ?? 
                                    $extractedData['metadata']['confidence_score'] ?? 
                                    $extractedData['confidence_score'] ?? 0,
                'extraction_id' => $extraction?->id,
                'data_attribution' => [
                    'document_fields' => isset($contactMetadata['sources']) ? 
                        array_filter($contactMetadata['sources'], fn($source) => $source !== 'messages') : [],
                    'ai_enhanced_fields' => isset($contactMetadata['sources']) ? 
                        array_filter($contactMetadata['sources'], fn($source) => $source === 'messages') : []
                ]
            ],
            
            'extraction_data' => [
                'raw_extracted_data' => $extractedData,
                
                'processed_data' => [
                    'contact_information' => [
                        'name' => $contactInfo['name'] ?? $extractedData['contact']['name'] ?? null,
                        'email' => $contactInfo['email'] ?? $extractedData['contact']['email'] ?? null,
                        'phone' => $contactInfo['phone'] ?? $extractedData['contact']['phone'] ?? null,
                        'company' => $contactInfo['company'] ?? $extractedData['contact']['company'] ?? null,
                        'extraction_confidence' => $contactInfo['_confidence'] ?? 0,
                        'validation_status' => $contactMetadata['validation'] ?? ['valid' => false]
                    ],
                    
                    'vehicle_information' => array_merge($extractedData['vehicle'] ?? [], [
                        'specifications_verified' => !empty($extractedData['vehicle']['database_match']),
                        'spec_confidence' => $extractedData['vehicle']['spec_confidence'] ?? 0
                    ]),
                    
                    'shipping_information' => array_merge(
                        $extractedData['shipment'] ?? [],
                        $extractedData['shipping'] ?? [],
                        [
                            'route_extracted' => !empty($extractedData['shipment']['origin']) && 
                                               !empty($extractedData['shipment']['destination'])
                        ]
                    ),
                    
                    'dates_and_pricing' => [
                        'timeline' => $extractedData['dates'] ?? [],
                        'pricing' => $extractedData['pricing'] ?? [],
                        'incoterms' => $extractedData['incoterms'] ?? null
                    ]
                ]
            ],
            
            'quality_metrics' => [
                'overall_confidence' => $extraction?->confidence ?? 
                                      $extractedData['metadata']['confidence_score'] ?? 
                                      $extractedData['confidence_score'] ?? 0,
                'overall_quality_score' => $this->calculateFieldCompleteness($extractedData),
                'field_completeness' => $this->calculateFieldCompleteness($extractedData),
                'validation_results' => [
                    'contact_valid' => $contactMetadata['complete'] ?? false,
                    'vehicle_complete' => !empty($extractedData['vehicle']['make']) && 
                                        !empty($extractedData['vehicle']['model']),
                    'shipping_complete' => !empty($extractedData['shipment']['origin']) && 
                                         !empty($extractedData['shipment']['destination'])
                ],
                'extraction_strategy' => $extractedData['metadata']['strategy_used'] ?? 'standard'
            ],
            
            'robaws_integration' => [
                'export_timestamp' => now()->toISOString(),
                'mapping_version' => '1.0',
                'field_mappings' => $this->getFieldMappings(),
                'processed_for_quotation' => true
            ]
        ];
    }

    /**
     * Calculate field completeness percentage
     */
    private function calculateFieldCompleteness(array $data): float
    {
        $requiredFields = [
            'contact.name', 'contact.email', 'vehicle.make', 'vehicle.model',
            'shipment.origin', 'shipment.destination'
        ];
        
        $completedFields = 0;
        
        foreach ($requiredFields as $field) {
            $keys = explode('.', $field);
            $value = $data;
            
            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    $value = null;
                    break;
                }
            }
            
            if (!empty($value)) {
                $completedFields++;
            }
        }
        
        return round(($completedFields / count($requiredFields)) * 100, 2);
    }

    /**
     * Get field mappings for reference
     */
    private function getFieldMappings(): array
    {
        return [
            'contact_name' => 'contact.name',
            'contact_email' => 'contact.email', 
            'contact_phone' => 'contact.phone',
            'vehicle_make' => 'vehicle.make',
            'vehicle_model' => 'vehicle.model',
            'vehicle_year' => 'vehicle.year',
            'origin_port' => 'shipment.origin',
            'destination_port' => 'shipment.destination',
            'shipping_method' => 'shipment.method'
        ];
    }

    /**
     * Generate a descriptive title for the offer
     */
    private function generateOfferTitle(array $data): string
    {
        $parts = [];
        
        // Add freight type
        if (!empty($data['shipment_type'])) {
            $parts[] = $data['shipment_type'];
        } else {
            $parts[] = 'Freight';
        }
        
        // Add route
        if (!empty($data['ports']['origin']) && !empty($data['ports']['destination'])) {
            $parts[] = "{$data['ports']['origin']} → {$data['ports']['destination']}";
        } elseif (!empty($data['port_of_loading']) && !empty($data['port_of_discharge'])) {
            $parts[] = "{$data['port_of_loading']} → {$data['port_of_discharge']}";
        }
        
        // Add container info
        if (!empty($data['container']['number'])) {
            $parts[] = "Container: {$data['container']['number']}";
        } elseif (!empty($data['container']['type'])) {
            $parts[] = "Container: {$data['container']['type']}";
        }
        
        // Add reference
        if (!empty($data['invoice']['number'])) {
            $parts[] = "Ref: {$data['invoice']['number']}";
        }
        
        return !empty($parts) ? implode(' | ', $parts) : 'Freight Quotation ' . date('Y-m-d');
    }

    /**
     * Build line items from extracted data
     */
    private function buildLineItems(array $data): array
    {
        $lineItems = [];
        
        // Add freight charges if available
        if (!empty($data['charges'])) {
            foreach ($data['charges'] as $charge) {
                $lineItems[] = [
                    'type' => 'LINE',
                    'description' => $charge['description'] ?? 'Freight Charge',
                    'quantity' => $charge['quantity'] ?? 1,
                    'unitPrice' => $charge['amount'] ?? 0,
                    'taxRate' => $charge['tax_rate'] ?? 0,
                ];
            }
        }
        
        // Add basic freight line if no specific charges
        if (empty($lineItems)) {
            $description = 'Freight Transport';
            
            // Enhance description with container info
            if (!empty($data['container']['type'])) {
                $description .= " - {$data['container']['type']}";
            }
            
            // Add route to description
            if (!empty($data['ports']['origin']) && !empty($data['ports']['destination'])) {
                $description .= " ({$data['ports']['origin']} - {$data['ports']['destination']})";
            }
            
            $lineItems[] = [
                'type' => 'LINE',
                'description' => $description,
                'quantity' => 1,
                'unitPrice' => 0, // To be filled by user
                'taxRate' => 21, // Default VAT rate
            ];
        }
        
        return $lineItems;
    }

    /**
     * Extract notes/comments from the data
     */
    private function extractNotes(array $data): string
    {
        $notes = [];
        
        // Add consignee information
        if (!empty($data['consignee']['name'])) {
            $notes[] = "Consignee: {$data['consignee']['name']}";
            
            if (!empty($data['consignee']['address'])) {
                $notes[] = "Address: {$data['consignee']['address']}";
            }
            
            if (!empty($data['consignee']['contact'])) {
                $notes[] = "Contact: {$data['consignee']['contact']}";
            }
        }
        
        // Add cargo information
        if (!empty($data['cargo_description'])) {
            $notes[] = "Cargo: {$data['cargo_description']}";
        }
        
        // Add weight and volume
        if (!empty($data['weight'])) {
            $notes[] = "Weight: {$data['weight']} kg";
        }
        
        if (!empty($data['volume'])) {
            $notes[] = "Volume: {$data['volume']} m³";
        }
        
        // Add container details
        if (!empty($data['container']['number'])) {
            $notes[] = "Container: {$data['container']['number']}";
        }
        
        // Add invoice information
        if (!empty($data['invoice']['number'])) {
            $notes[] = "Invoice: {$data['invoice']['number']}";
        }
        
        if (!empty($data['invoice']['date'])) {
            $notes[] = "Invoice Date: {$data['invoice']['date']}";
        }
        
        // Add special instructions
        if (!empty($data['special_instructions'])) {
            $notes[] = "Special Instructions: {$data['special_instructions']}";
        }
        
        return implode("\n", $notes);
    }

    /**
     * Find or create client from extraction data
     */
    private function findOrCreateClientFromExtraction(array $data): ?array
    {
        $consignee = $data['consignee'] ?? [];
        
        $clientData = [
            'name' => $consignee['name'] ?? $data['client_name'] ?? 'Unknown Client',
            'email' => $consignee['email'] ?? $data['client_email'] ?? null,
            'phone' => $consignee['contact'] ?? $consignee['phone'] ?? $data['client_phone'] ?? null,
            'address' => $consignee['address'] ?? $data['client_address'] ?? null,
            'type' => 'COMPANY', // or 'PERSON' based on your logic
            'country' => 'BE', // Default to Belgium, adjust as needed
        ];
        
        // Skip if no name
        if (empty($clientData['name']) || $clientData['name'] === 'Unknown Client') {
            // Try to create a name from available data
            if (!empty($data['invoice']['number'])) {
                $clientData['name'] = "Client - Invoice {$data['invoice']['number']}";
            } else {
                $clientData['name'] = "Client - " . date('Y-m-d H:i:s');
            }
        }
        
        try {
            return $this->robawsClient->findOrCreateClient($clientData);
        } catch (\Exception $e) {
            Log::error('Failed to create/find client in Robaws', [
                'client_data' => $clientData,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Save Robaws offer reference in our database
     */
    private function saveRobawsOffer(Document $document, array $offer): void
    {
        // Update document with Robaws reference
        $document->update([
            'robaws_quotation_id' => $offer['id'] ?? null,
            'robaws_quotation_data' => $offer,
        ]);
        
        // Create or update local quotation record
        Quotation::updateOrCreate(
            ['robaws_id' => $offer['id']],
            [
                'user_id' => $document->user_id ?? 1, // Default to user ID 1 if no user associated
                'document_id' => $document->id,
                'quotation_number' => $offer['number'] ?? $offer['id'],
                'status' => strtolower($offer['status'] ?? 'draft'),
                'client_name' => $offer['client']['name'] ?? null,
                'client_email' => $offer['client']['email'] ?? null,
                'robaws_data' => $offer,
                'auto_created' => true,
                'created_from_document' => true,
            ]
        );
    }
}
