<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Quotation;
use App\Services\RobawsClient;
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
            
            // CRITICAL FIX: Update the offer with custom fields using PUT method
            if ($offer && isset($offer['id'])) {
                Log::info('Updating offer with custom fields', [
                    'offer_id' => $offer['id'],
                    'document_id' => $document->id
                ]);
                
                try {
                    $updatedOffer = $this->updateOfferWithCustomFields($offer['id'], $extractedData, $extraction);
                    if ($updatedOffer) {
                        $offer = $updatedOffer; // Use the updated offer data
                        Log::info('Successfully updated offer with custom fields', [
                            'offer_id' => $offer['id']
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to update offer with custom fields', [
                        'offer_id' => $offer['id'],
                        'error' => $e->getMessage()
                    ]);
                    // Continue with the original offer even if update fails
                }
            }
            
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
     * Update an existing offer with custom field values using the correct extraFields format
     * This is required because custom fields cannot be set during offer creation
     */
    private function updateOfferWithCustomFields(string $offerId, array $extractedData, $extraction = null): ?array
    {
        try {
            // First, get the current offer structure
            $currentOffer = $this->robawsClient->getOffer($offerId);
            
            if (!$currentOffer) {
                Log::error('Failed to get current offer for update', ['offer_id' => $offerId]);
                return null;
            }
            
            // Build custom field values using the correct extraFields format
            $customFieldsMap = $this->buildCustomFieldsMap($extractedData, $extraction);
            
            // Build extraFields with proper structure
            $extraFields = $currentOffer['extraFields'] ?? [];
            foreach ($customFieldsMap as $sourceKey => $config) {
                $label = $config['label'];
                $type = $config['type'];
                $value = $config['value'];
                
                if ($value !== null) {
                    $extraFields[$label] = [$type => $value];
                }
            }
            
            // Remove read-only/system fields to avoid 415/422 errors
            $payload = array_diff_key($currentOffer, array_flip([
                'id', 'createdAt', 'updatedAt', 'links', 'number', 'calculated',
                'totalInclVat', 'totalExclVat', 'totalCostPrice', 'totalIndirectCosts', 
                'totalMargin', 'statusSuccessPercentage', 'addressDistance'
            ]));
            
            // Inject extraFields and update
            $payload['extraFields'] = $extraFields;
            
            Log::info('Updating offer with extraFields', [
                'offer_id' => $offerId,
                'extra_fields_count' => count($extraFields),
                'custom_fields' => array_keys($customFieldsMap),
                'sample_extra_fields' => array_slice($extraFields, 0, 3, true)
            ]);
            
            // Update the offer using PUT method with full payload
            $updatedOffer = $this->robawsClient->updateOfferWithExtraFields($offerId, $payload);
            
            return $updatedOffer;
            
        } catch (\Exception $e) {
            Log::error('Error updating offer with custom fields', [
                'offer_id' => $offerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Build custom fields mapping from extracted data with correct Robaws format
     */
    private function buildCustomFieldsMap(array $extractedData, $extraction = null): array
    {
        $map = [];
        
        try {
            // Normalize data structure
            $normalizedData = $this->normalizeDataStructure($extractedData);
            
            $vehicle = $normalizedData['vehicle'] ?? [];
            $shipment = $normalizedData['shipment'] ?? [];
            $contact = $normalizedData['contact'] ?? [];
            
            // JSON field - stringValue type
            $map['json'] = [
                'label' => 'JSON',
                'type' => 'stringValue',
                'value' => json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ];
            
            // CARGO field - stringValue type
            if (!empty($vehicle['brand']) || !empty($vehicle['model'])) {
                $brand = $vehicle['brand'] ?? '';
                $model = $vehicle['model'] ?? '';
                $year = $vehicle['year'] ?? '';
                $condition = $vehicle['condition'] ?? 'used';
                $map['cargo'] = [
                    'label' => 'CARGO',
                    'type' => 'stringValue',
                    'value' => "1 x {$condition} {$brand} {$model}" . ($year ? " ({$year})" : "")
                ];
            }
            
            // Customer field - stringValue type
            if (!empty($contact['company'])) {
                $map['customer'] = [
                    'label' => 'Customer',
                    'type' => 'stringValue',
                    'value' => $contact['company']
                ];
            } else if (!empty($vehicle['brand'])) {
                $map['customer'] = [
                    'label' => 'Customer',
                    'type' => 'stringValue',
                    'value' => "Customer - {$vehicle['brand']} Owner"
                ];
            }
            
            // Customer reference field - stringValue type
            if (!empty($vehicle['brand']) && !empty($vehicle['model'])) {
                $map['customer_reference'] = [
                    'label' => 'Customer reference',
                    'type' => 'stringValue',
                    'value' => "EXP RORO - {$vehicle['brand']} {$vehicle['model']}" . 
                        (!empty($vehicle['year']) ? " ({$vehicle['year']})" : "")
                ];
            }
            
            // Contact field - stringValue type
            if (!empty($contact['phone'])) {
                $map['contact'] = [
                    'label' => 'Contact',
                    'type' => 'stringValue',
                    'value' => $contact['phone']
                ];
            } else if (!empty($contact['name'])) {
                $map['contact'] = [
                    'label' => 'Contact',
                    'type' => 'stringValue',
                    'value' => $contact['name']
                ];
            }
            
            // POR field - stringValue type
            if (!empty($shipment['origin'])) {
                $map['por'] = [
                    'label' => 'POR',
                    'type' => 'stringValue',
                    'value' => $this->buildPortValue($shipment['origin'])
                ];
            }
            
            // POL field - stringValue type
            if (!empty($shipment['origin'])) {
                $map['pol'] = [
                    'label' => 'POL',
                    'type' => 'stringValue',
                    'value' => $this->buildPortValue($shipment['origin'])
                ];
            }
            
            // POD field - stringValue type
            if (!empty($shipment['destination'])) {
                $map['pod'] = [
                    'label' => 'POD',
                    'type' => 'stringValue',
                    'value' => $this->buildPortValue($shipment['destination'])
                ];
            }
            
            // DIM_BEF_DELIVERY field - stringValue type
            $dims = $this->formatDimensions($vehicle);
            if ($dims) {
                $map['dim_bef_delivery'] = [
                    'label' => 'DIM_BEF_DELIVERY',
                    'type' => 'stringValue',
                    'value' => $dims
                ];
            }
            
            Log::info('Built custom fields map for Robaws', [
                'field_count' => count($map),
                'fields' => array_column($map, 'label')
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error building custom fields map', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $map;
    }
    private function buildOfferPayload(array $extractedData, int $clientId, $extraction = null): array
    {
        // Start with the base payload - using EXACT same pattern as working JSON
        $payload = [
            'clientId' => $clientId,
            'name' => $this->generateOfferTitle($extractedData),
            'currency' => 'EUR',
            'status' => 'DRAFT',
        ];

        // Add the JSON field EXACTLY as it was working before
        $payload['JSON'] = json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        // Now add other fields using EXACTLY the same pattern as JSON
        // All fields are configured the same way in Robaws
        try {
            // Get the normalized data
            $normalizedData = $this->normalizeDataStructure($extractedData);
            
            $vehicle = $normalizedData['vehicle'] ?? [];
            $shipment = $normalizedData['shipment'] ?? [];
            $contact = $normalizedData['contact'] ?? [];
            
            // Add fields EXACTLY like JSON - direct assignment at root level
            
            // CARGO field
            if (!empty($vehicle['brand']) || !empty($vehicle['model'])) {
                $brand = $vehicle['brand'] ?? '';
                $model = $vehicle['model'] ?? '';
                $year = $vehicle['year'] ?? '';
                $condition = $vehicle['condition'] ?? 'used';
                $payload['CARGO'] = "1 x {$condition} {$brand} {$model}" . ($year ? " ({$year})" : "");
            }
            
            // Customer field
            if (!empty($contact['company'])) {
                $payload['Customer'] = $contact['company'];
            } else if (!empty($vehicle['brand'])) {
                $payload['Customer'] = "Customer - {$vehicle['brand']} Owner";
            }
            
            // Customer reference field
            if (!empty($vehicle['brand']) && !empty($vehicle['model'])) {
                $payload['Customer reference'] = "EXP RORO - {$vehicle['brand']} {$vehicle['model']}" . 
                    (!empty($vehicle['year']) ? " ({$vehicle['year']})" : "");
            }
            
            // Contact field (phone number)
            if (!empty($contact['phone'])) {
                $payload['Contact'] = $contact['phone'];
            } else if (!empty($contact['name'])) {
                $payload['Contact'] = $contact['name'];
            }
            
            // POR field
            if (!empty($shipment['origin'])) {
                $payload['POR'] = $this->buildPortValue($shipment['origin']);
            }
            
            // POL field (same as POR)
            if (!empty($shipment['origin'])) {
                $payload['POL'] = $this->buildPortValue($shipment['origin']);
            }
            
            // POD field
            if (!empty($shipment['destination'])) {
                $payload['POD'] = $this->buildPortValue($shipment['destination']);
            }
            
            // DIM_BEF_DELIVERY field
            $dims = $this->formatDimensions($vehicle);
            if ($dims) {
                $payload['DIM_BEF_DELIVERY'] = $dims;
            }
            
        } catch (\Exception $e) {
            // If there's any error, at least keep the JSON field
            Log::error('Error building field values', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Build line items from extracted data
        $lineItems = $this->buildLineItems($extractedData);
        $payload['lineItems'] = $lineItems;
        
        $payload['validityDays'] = 30;
        $payload['paymentTermDays'] = 30;
        $payload['notes'] = '';
        
        // Log exactly what we're sending
        Log::info('Robaws payload FINAL - using same pattern as working JSON', [
            'fields' => array_keys($payload),
            'has_JSON' => isset($payload['JSON']),
            'JSON_length' => isset($payload['JSON']) ? strlen($payload['JSON']) : 0,
            'has_CARGO' => isset($payload['CARGO']),
            'has_Customer' => isset($payload['Customer']),
            'has_POR' => isset($payload['POR']),
            'has_POD' => isset($payload['POD']),
            'sample_values' => [
                'CARGO' => $payload['CARGO'] ?? 'NOT SET',
                'Customer' => $payload['Customer'] ?? 'NOT SET',
                'POR' => $payload['POR'] ?? 'NOT SET',
                'POD' => $payload['POD'] ?? 'NOT SET'
            ]
        ]);
        
        // Return the payload AS IS - no filtering, no modifications
        // This is exactly how JSON was working
        return $payload;
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
        
        // NOTE: Do NOT dispatch upload job here - the ExtractionObserver will handle it
        // This ensures we only have one quotation with both data and document
        
        Log::info('Robaws quotation created and saved to document', [
            'document_id' => $document->id,
            'robaws_quotation_id' => $offer['id'],
            'filename' => $document->filename
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

    /**
     * Normalize data structure to match field mapping expectations
     */
    private function normalizeDataStructure(array $data): array
    {
        // Handle the nested structure from your screenshots
        if (isset($data['extraction_data']['raw_extracted_data'])) {
            return $data['extraction_data']['raw_extracted_data'];
        }
        
        // Handle direct structure
        if (isset($data['vehicle']) || isset($data['shipment']) || isset($data['contact'])) {
            return $data;
        }
        
        // Handle other possible nesting
        if (isset($data['extraction_data'])) {
            return $data['extraction_data'];
        }
        
        // Handle the main document data structure (legacy)
        if (isset($data['document_data'])) {
            $docData = $data['document_data'];
            
            // Map vehicle information
            $normalized = [];
            $normalized['vehicle'] = [
                'make' => $docData['vehicle_make'] ?? null,
                'brand' => $docData['vehicle_make'] ?? null,
                'model' => $docData['vehicle_model'] ?? null,
                'year' => $docData['vehicle_year'] ?? null,
                'condition' => $docData['vehicle_condition'] ?? null,
                'engine_cc' => $docData['engine_cc'] ?? null,
                'fuel_type' => $docData['fuel_type'] ?? null,
                'weight' => [
                    'value' => $docData['weight_numeric'] ?? null,
                    'unit' => $docData['weight_unit'] ?? null
                ],
                'dimensions' => [
                    'length' => $this->extractDimension($docData['length'] ?? null),
                    'width' => $this->extractDimension($docData['width'] ?? null),
                    'height' => $this->extractDimension($docData['height'] ?? null)
                ]
            ];
            
            // Map shipment information
            $normalized['shipment'] = [
                'origin' => $docData['origin'] ?? null,
                'destination' => $docData['destination'] ?? null,
                'type' => $docData['shipment_type'] ?? null
            ];
            
            return $normalized;
        }
        
        return $data;
    }

    /**
     * Extract numeric dimension from string
     */
    private function extractDimension($dimension): ?float
    {
        if (is_numeric($dimension)) {
            return (float) $dimension;
        }
        
        if (is_string($dimension)) {
            // Extract number from strings like "4 m", "1.6 m"
            if (preg_match('/(\d+(?:\.\d+)?)/', $dimension, $matches)) {
                return (float) $matches[1];
            }
        }
        
        return null;
    }

    /**
     * Map fields using the field mapping configuration
     */
    private function mapFieldsUsingConfig(array $extractedData, array $mappingConfig): array
    {
        $mappedFields = [];
        
        // Get extraction data from nested structure
        $processedData = $extractedData['extraction_data']['processed_data'] ?? [];
        $rawData = $extractedData['extraction_data']['raw_extracted_data'] ?? [];
        
        // Flatten the data for easier access
        $flatData = array_merge(
            $extractedData,
            $processedData,
            $rawData,
            $processedData['contact_information'] ?? [],
            $processedData['vehicle_information'] ?? [],
            $processedData['shipping_information'] ?? [],
            $rawData['contact'] ?? [],
            $rawData['vehicle'] ?? [],
            $rawData['shipment'] ?? []
        );

        $fieldMappings = $mappingConfig['field_mappings'] ?? [];

        // Map quotation info fields
        if (isset($fieldMappings['quotation_info'])) {
            foreach ($fieldMappings['quotation_info'] as $robawsField => $mapping) {
                $value = $this->extractFieldValue($flatData, $mapping);
                if ($value !== null) {
                    $mappedFields[$robawsField] = $value;
                }
            }
        }

        // Map routing fields
        if (isset($fieldMappings['routing'])) {
            foreach ($fieldMappings['routing'] as $robawsField => $mapping) {
                $value = $this->extractFieldValue($flatData, $mapping);
                if ($value !== null) {
                    $mappedFields[$robawsField] = $value;
                }
            }
        }

        // Map cargo details
        if (isset($fieldMappings['cargo_details'])) {
            foreach ($fieldMappings['cargo_details'] as $robawsField => $mapping) {
                $value = $this->extractFieldValue($flatData, $mapping);
                if ($value !== null) {
                    $mappedFields[$robawsField] = $value;
                }
            }
        }

        return $mappedFields;
    }

    /**
     * Extract field value based on mapping configuration
     */
    private function extractFieldValue(array $data, $mapping)
    {
        // Debug: Check the mapping structure
        if (!is_array($mapping)) {
            return null;
        }

        // Handle simple array of sources
        if (isset($mapping['sources']) && is_array($mapping['sources'])) {
            foreach ($mapping['sources'] as $source) {
                if (!is_string($source)) {
                    continue; // Skip non-string sources
                }
                $value = data_get($data, $source);
                if ($value !== null && $value !== '') {
                    return $this->transformValue($value, $mapping);
                }
            }
        }

        // Handle template-based fields
        if (isset($mapping['template'])) {
            return $this->buildFromTemplate($data, $mapping);
        }

        // Return default if specified
        return $mapping['default'] ?? null;
    }

    /**
     * Transform value based on mapping configuration
     */
    private function transformValue($value, array $mapping)
    {
        if (!isset($mapping['transform'])) {
            return $value;
        }

        switch ($mapping['transform']) {
            case 'city_to_code':
                return $this->cityToCode($value);
            case 'city_to_port':
                return $this->cityToPort($value);
            case 'extract_name':
                return $this->extractName($value);
            case 'to_integer':
                return is_numeric($value) ? (int) $value : null;
            case 'to_meters':
                return $this->convertToMeters($value);
            case 'extract_weight_numeric':
                return $this->extractNumericWeight($value);
            default:
                return $value;
        }
    }

    /**
     * Build value from template
     */
    private function buildFromTemplate(array $data, array $mapping): ?string
    {
        $template = $mapping['template'];
        
        if (isset($mapping['components'])) {
            // Handle complex template with components
            foreach ($mapping['components'] as $placeholder => $componentMapping) {
                $value = $this->extractFieldValue($data, $componentMapping);
                $template = str_replace('{' . $placeholder . '}', $value ?? '', $template);
            }
        } elseif (isset($mapping['sources'])) {
            // Handle simple template with direct sources
            foreach ($mapping['sources'] as $placeholder => $sources) {
                if (is_string($placeholder) && is_array($sources)) {
                    // Handle placeholder-based sources
                    foreach ($sources as $source) {
                        $value = data_get($data, $source);
                        if ($value !== null && $value !== '') {
                            $template = str_replace('{' . $placeholder . '}', $value, $template);
                            break;
                        }
                    }
                } elseif (is_numeric($placeholder) && is_string($sources)) {
                    // Handle direct source list (no placeholders)
                    $value = data_get($data, $sources);
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
            }
        }

        return trim($template) !== $mapping['template'] ? trim($template) : null;
    }

    /**
     * Transform city to port code
     */
    private function cityToCode(string $city): ?string
    {
        $mapping = [
            'Brussels' => 'BRU',
            'Bruxelles' => 'BRU',
            'Jeddah' => 'JED',
            'Djeddah' => 'JED',
            'Antwerp' => 'ANR',
            'Hamburg' => 'HAM',
            'Rotterdam' => 'RTM',
            'Dubai' => 'DXB',
        ];

        // Try exact match first
        if (isset($mapping[$city])) {
            return $mapping[$city];
        }

        // Try partial match
        foreach ($mapping as $cityName => $code) {
            if (stripos($city, $cityName) !== false) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Transform city to port
     */
    private function cityToPort(string $city): ?string
    {
        $mapping = [
            'Brussels' => 'Antwerp',
            'Bruxelles' => 'Antwerp',
            'Paris' => 'Le Havre',
            'Frankfurt' => 'Hamburg',
            'Munich' => 'Hamburg',
        ];

        return $mapping[$city] ?? $city;
    }

    /**
     * Extract name from email or text
     */
    private function extractName(string $value): ?string
    {
        // If it looks like an email, extract the part before @
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $value);
            return ucfirst(str_replace(['.', '_'], ' ', $parts[0]));
        }

        return $value;
    }

    /**
     * Convert various units to meters
     */
    private function convertToMeters($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        // Assume the value is already in meters if no unit specified
        return (float) $value;
    }

    /**
     * Extract numeric weight from string
     */
    private function extractNumericWeight($value): ?int
    {
        if (is_array($value)) {
            return null; // Can't extract weight from array
        }
        
        if (is_numeric($value)) {
            $weight = (int) $value;
            // Return null for unrealistic weights (likely parsing errors)
            return ($weight < 50 || $weight > 10000) ? null : $weight;
        }

        // Try to extract number from string
        if (is_string($value) && preg_match('/(\d+)/', $value, $matches)) {
            $weight = (int) $matches[1];
            return ($weight < 50 || $weight > 10000) ? null : $weight;
        }

        return null;
    }

    /**
     * Build customer name from available data
     */
    private function buildCustomerName(array $contact, array $vehicle): string
    {
        // Try company name first
        if (!empty($contact['company'])) {
            return $contact['company'];
        }
        
        // Try contact name
        if (!empty($contact['name'])) {
            return $contact['name'];
        }
        
        // Build from vehicle info if available
        if (!empty($vehicle['brand'])) {
            return "Customer - {$vehicle['brand']} Owner";
        }
        
        return "Transport Customer";
    }

    /**
     * Build customer reference from vehicle data
     */
    private function buildCustomerReference(array $vehicle): ?string
    {
        if (empty($vehicle['brand']) && empty($vehicle['model'])) {
            return null;
        }
        
        $reference = "EXP RORO";
        
        if (!empty($vehicle['brand'])) {
            $reference .= " - {$vehicle['brand']}";
        }
        
        if (!empty($vehicle['model'])) {
            $reference .= " {$vehicle['model']}";
        }
        
        if (!empty($vehicle['year'])) {
            $reference .= " ({$vehicle['year']})";
        }
        
        return $reference;
    }

    /**
     * Build cargo description
     */
    private function buildCargoDescription(array $vehicle): ?string
    {
        if (empty($vehicle['brand']) && empty($vehicle['model'])) {
            return null;
        }
        
        $quantity = 1; // Default to 1 vehicle
        $condition = $vehicle['condition'] ?? 'used';
        $brand = $vehicle['brand'] ?? 'Vehicle';
        $model = $vehicle['model'] ?? '';
        
        $cargo = "{$quantity} x {$condition} {$brand}";
        if (!empty($model)) {
            $cargo .= " {$model}";
        }
        
        if (!empty($vehicle['year'])) {
            $cargo .= " ({$vehicle['year']})";
        }
        
        return $cargo;
    }

    /**
     * Build port value (expand short names)
     */
    private function buildPortValue(?string $location): ?string
    {
        if (empty($location)) {
            return null;
        }
        
        // Expand common abbreviations
        $expansions = [
            'Av.' => 'Antwerp',
            'Jean' => 'Jebel Ali (Dubai)',
            'Rot.' => 'Rotterdam',
            'Ham.' => 'Hamburg',
            'Bre.' => 'Bremerhaven',
        ];
        
        return $expansions[$location] ?? $location;
    }

    /**
     * Format dimensions for Robaws
     */
    private function formatDimensions(array $vehicle): ?string
    {
        if (!isset($vehicle['dimensions_m'])) {
            return null;
        }
        
        $dims = $vehicle['dimensions_m'];
        $length = $dims['length_m'] ?? null;
        $width = $dims['width_m'] ?? null;
        $height = $dims['height_m'] ?? null;
        
        if ($length && $width && $height) {
            return sprintf("%.2f x %.2f x %.2f m", $length, $width, $height);
        }
        
        return null;
    }
}
