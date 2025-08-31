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
            // 1) Ensure we have mapped data
            $mapped = $document->robaws_quotation_data ?? [];
            if (empty($mapped)) {
                // Process document to get mapped data
                $this->processDocumentFromExtraction($document);
                $mapped = $document->fresh()->robaws_quotation_data ?? [];
            }

            // If still no mapped data, fall back to extraction data
            if (empty($mapped)) {
                $extraction = $document->extractions()->first();
                $extractedData = null;
                
                if ($extraction && $extraction->raw_json) {
                    $extractedData = json_decode($extraction->raw_json, true);
                } elseif ($document->raw_json) {
                    $extractedData = json_decode($document->raw_json, true);
                } else {
                    $extractedData = $document->extraction_data;
                }
                
                if (empty($extractedData)) {
                    Log::warning('No extraction data available for document', [
                        'document_id' => $document->id
                    ]);
                    return null;
                }
                
                // Use extracted data as mapped data
                $mapped = $extractedData;
            }

            Log::info('Creating Robaws offer with mapped data', [
                'document_id' => $document->id,
                'mapped_fields' => array_keys($mapped),
                'has_customer' => !empty($mapped['customer']),
                'has_routing' => !empty($mapped['por']) || !empty($mapped['pol']) || !empty($mapped['pod'])
            ]);

            // 2) Minimal create (no custom fields)
            $clientId = $this->resolveClientId($mapped['customer'] ?? null, $mapped['client_email'] ?? null);
            $create = [
                'title' => $mapped['customer_reference'] ?? ('Offer - ' . ($mapped['customer'] ?? 'Unknown')),
                'date' => now()->toDateString(),
                'clientId' => $clientId,
                'currency' => 'EUR',
                'companyId' => config('services.robaws.company_id'),
                // DO NOT send custom fields here
            ];

            Log::info('Robaws API Request - Create Offer', ['payload' => $create]);
            $created = $this->robawsClient->createOffer($create);
            $offerId = $created['id'] ?? null;
            if (!$offerId) {
                throw new \RuntimeException('Robaws createOffer returned no id');
            }

            // 3) GET → merge → PUT full model with extraFields
            $offer = $this->robawsClient->getOffer($offerId);
            $payload = collect($offer)->except(['id', 'createdAt', 'updatedAt', 'number', 'links'])->toArray();
            $payload['extraFields'] = array_merge(
                $offer['extraFields'] ?? [], 
                $this->buildExtraFieldsFromMapped($mapped)
            );

            Log::info('Robaws API Request - Update Offer', [
                'offer_id' => $offerId,
                'extraFields_labels' => array_keys($payload['extraFields'] ?? []),
            ]);

            $this->robawsClient->updateOffer($offerId, $payload);

            // 4) Persist + mark synced
            $document->update([
                'robaws_quotation_id' => $offerId,
                'robaws_sync_status' => 'synced',
                'robaws_synced_at' => now(),
            ]);

            Log::info('Successfully created Robaws offer with extraFields', [
                'document_id' => $document->id,
                'offer_id' => $offerId
            ]);

            return ['id' => $offerId];
            
        } catch (\Exception $e) {
            Log::error('Error creating Robaws offer', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Build extraFields from the mapper output
     */
    private function buildExtraFieldsFromMapped(array $m): array
    {
        $xf = [];
        $add = function (string $label, $value, string $type = 'stringValue') use (&$xf) {
            if ($value === null || $value === '') return;
            $xf[$label] = [$type => is_bool($value) ? (bool) $value : (string) $value];
        };

        // Quotation info
        $add('Customer', $m['customer'] ?? null);
        $add('Contact', $m['contact'] ?? null);
        $add('Endcustomer', $m['endcustomer'] ?? null);
        $add('Customer reference', $m['customer_reference'] ?? null);

        // Routing
        $add('POR', $m['por'] ?? null);
        $add('POL', $m['pol'] ?? null);
        $add('POT', $m['pot'] ?? null);
        $add('POD', $m['pod'] ?? null);
        $add('FDEST', $m['fdest'] ?? null);

        // Cargo details
        $add('CARGO', $m['cargo'] ?? null);
        $add('DIM_BEF_DELIVERY', $m['dim_bef_delivery'] ?? null);

        // Keep the full extraction JSON for the JSON field in Robaws
        if (!empty($m['JSON'])) {
            $xf['JSON'] = ['stringValue' => $m['JSON']];
        }

        return $xf;
    }

    /**
     * Resolve client ID from customer data
     */
    private function resolveClientId(?string $customer, ?string $clientEmail): int
    {
        if (!$customer && !$clientEmail) {
            // Return default client ID from config or create a default client
            return config('services.robaws.default_client_id', 1);
        }

        $clientData = [
            'name' => $customer ?? 'Unknown Client',
            'email' => $clientEmail,
        ];

        try {
            $client = $this->robawsClient->findOrCreateClient($clientData);
            return $client['id'];
        } catch (\Exception $e) {
            Log::error('Failed to resolve client ID', [
                'customer' => $customer,
                'email' => $clientEmail,
                'error' => $e->getMessage()
            ]);
            
            // Return default client ID as fallback
            return config('services.robaws.default_client_id', 1);
        }
    }

    /**
     * Process document from extraction to create mapped data
     */
    private function processDocumentFromExtraction(Document $document): void
    {
        // This method should trigger the JsonFieldMapper to process the document
        // and populate robaws_quotation_data
        
        $extraction = $document->extractions()->first();
        if (!$extraction) {
            Log::warning('No extraction found for document', ['document_id' => $document->id]);
            return;
        }

        try {
            // Use the enhanced integration service to process the document
            $enhancedService = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
            $enhancedService->processDocumentFromExtraction($document);
        } catch (\Exception $e) {
            Log::error('Failed to process document from extraction', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
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
            
            // JSON field - stringValue type (use normalized data to avoid nested JSON fields)
            $cleanedData = $normalizedData;
            // Add essential metadata
            $cleanedData['extraction_metadata'] = [
                'extracted_at' => now()->toISOString(),
                'document_id' => $extractedData['document_id'] ?? null,
                'confidence_score' => $extractedData['confidence_score'] ?? null
            ];
            
            $map['json'] = [
                'label' => 'JSON',
                'type' => 'stringValue',
                'value' => json_encode($cleanedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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
        // Start with the base payload - core offer properties only
        $payload = [
            'clientId' => $clientId,
            'name' => $this->generateOfferTitle($extractedData),
            'currency' => 'EUR',
            'status' => 'DRAFT',
            'validityDays' => 30,
            'paymentTermDays' => 30,
            'notes' => '',
        ];

        // Build line items from extracted data
        $lineItems = $this->buildLineItems($extractedData);
        $payload['lineItems'] = $lineItems;

        // NOTE: Do NOT add extraFields during offer creation - they must be sent via PUT update
        // This matches the successful PDF pattern which creates the offer first, then updates with custom fields
        
        Log::info('Robaws payload for creation (no extraFields)', [
            'base_fields' => array_keys($payload),
            'has_line_items' => !empty($lineItems),
            'line_item_count' => count($lineItems)
        ]);
        
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
        
        // Handle flat structure (current image extraction format)
        if (isset($data['vehicle_make']) || isset($data['vehicle_model']) || isset($data['origin']) || isset($data['destination'])) {
            $normalized = [];
            
            // Map vehicle information from flat structure
            $normalized['vehicle'] = [
                'make' => $data['vehicle_make'] ?? null,
                'brand' => $data['vehicle_make'] ?? null,
                'model' => $data['vehicle_model'] ?? null,
                'year' => $data['vehicle_year'] ?? null,
                'condition' => $data['vehicle_condition'] ?? null,
                'engine_cc' => $data['engine_cc'] ?? null,
                'fuel_type' => $data['fuel_type'] ?? null,
                'weight' => [
                    'value' => $data['weight_numeric'] ?? null,
                    'unit' => $data['weight_unit'] ?? null
                ],
                'dimensions' => [
                    'length' => $this->extractDimension($data['length'] ?? null),
                    'width' => $this->extractDimension($data['width'] ?? null),
                    'height' => $this->extractDimension($data['height'] ?? null)
                ],
                'typical_container' => $data['typical_container'] ?? null,
                'shipping_notes' => $data['shipping_notes'] ?? null
            ];
            
            // Map shipment information from flat structure
            $normalized['shipment'] = [
                'origin' => $data['origin'] ?? null,
                'destination' => $data['destination'] ?? null,
                'type' => $data['shipment_type'] ?? null
            ];
            
            // Map contact information (may not be present in flat structure)
            $normalized['contact'] = [
                'name' => $data['contact_name'] ?? null,
                'company' => $data['contact_company'] ?? null,
                'phone' => $data['contact_phone'] ?? null,
                'email' => $data['contact_email'] ?? null
            ];
            
            return $normalized;
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
