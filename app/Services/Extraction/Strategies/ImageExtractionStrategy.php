<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImageExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private AiRouter $aiRouter
    ) {}

    public function getName(): string
    {
        return 'image_vision';
    }

    public function getPriority(): int
    {
        return 80; // Priority between PDF (90) and other strategies
    }

    public function supports(Document $document): bool
    {
        // Support common image formats
        $supportedMimeTypes = [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/gif',
            'image/webp'
        ];

        return in_array($document->mime_type, $supportedMimeTypes) ||
               preg_match('/\.(jpe?g|png|gif|webp)$/i', $document->filename ?? '');
    }

    /**
     * Transform structured AI extraction data to Robaws-compatible flat format
     * 
     * This method converts our structured JSON format to the flat format
     * expected by the Robaws integration layer.
     * 
     * @param array $extractedData The structured data from AI extraction
     * @return array Flattened data compatible with Robaws
     */
    protected function transformForRobaws(array $extractedData): array
    {
        $transformed = [];
        
        // Transform vehicle data
        if (isset($extractedData['vehicle']) && is_array($extractedData['vehicle'])) {
            $vehicle = $extractedData['vehicle'];
            
            // Basic vehicle information
            if (isset($vehicle['make'])) $transformed['vehicle_make'] = $vehicle['make'];
            if (isset($vehicle['model'])) $transformed['vehicle_model'] = $vehicle['model'];
            if (isset($vehicle['year'])) $transformed['vehicle_year'] = $vehicle['year'];
            if (isset($vehicle['condition'])) $transformed['vehicle_condition'] = $vehicle['condition'];
            if (isset($vehicle['vin'])) $transformed['vin'] = $vehicle['vin'];
            if (isset($vehicle['engine_cc'])) $transformed['engine_size'] = $vehicle['engine_cc'];
            if (isset($vehicle['fuel_type'])) $transformed['fuel_type'] = $vehicle['fuel_type'];
            if (isset($vehicle['color'])) $transformed['color'] = $vehicle['color'];
            
            // Transform weight (handle nested structure)
            if (isset($vehicle['weight']) && is_array($vehicle['weight'])) {
                $weight = $vehicle['weight'];
                if (isset($weight['value']) && $weight['value'] !== null) {
                    $unit = $weight['unit'] ?? 'kg';
                    $transformed['weight'] = $weight['value'] . ' ' . $unit;
                    $transformed['weight_numeric'] = $weight['value'];
                    $transformed['weight_unit'] = $unit;
                }
            }
            
            // Transform dimensions (handle nested structure)
            if (isset($vehicle['dimensions']) && is_array($vehicle['dimensions'])) {
                $dims = $vehicle['dimensions'];
                $unit = $dims['unit'] ?? 'm';
                
                if (isset($dims['length']) && $dims['length'] !== null) {
                    $transformed['length'] = $dims['length'] . ' ' . $unit;
                }
                if (isset($dims['width']) && $dims['width'] !== null) {
                    $transformed['width'] = $dims['width'] . ' ' . $unit;
                }
                if (isset($dims['height']) && $dims['height'] !== null) {
                    $transformed['height'] = $dims['height'] . ' ' . $unit;
                }
                
                // Create combined dimensions string
                if (isset($dims['length']) || isset($dims['width']) || isset($dims['height'])) {
                    $dimensions = [];
                    if (isset($dims['length'])) $dimensions[] = $dims['length'];
                    if (isset($dims['width'])) $dimensions[] = $dims['width'];
                    if (isset($dims['height'])) $dimensions[] = $dims['height'];
                    $transformed['dimensions'] = implode('x', $dimensions) . ' ' . $unit;
                }
            }
        }
        
        // Transform shipment data
        if (isset($extractedData['shipment']) && is_array($extractedData['shipment'])) {
            $shipment = $extractedData['shipment'];
            
            if (isset($shipment['origin'])) $transformed['origin'] = $shipment['origin'];
            if (isset($shipment['destination'])) $transformed['destination'] = $shipment['destination'];
            if (isset($shipment['type'])) $transformed['shipment_type'] = $shipment['type'];
            if (isset($shipment['service'])) $transformed['service'] = $shipment['service'];
            if (isset($shipment['incoterms'])) $transformed['incoterms'] = $shipment['incoterms'];
        }
        
        // Transform contact data
        if (isset($extractedData['contact']) && is_array($extractedData['contact'])) {
            $contact = $extractedData['contact'];
            
            if (isset($contact['name'])) $transformed['contact_name'] = $contact['name'];
            if (isset($contact['company'])) $transformed['company'] = $contact['company'];
            if (isset($contact['phone'])) $transformed['phone'] = $contact['phone'];
            if (isset($contact['email'])) $transformed['email'] = $contact['email'];
            if (isset($contact['address'])) $transformed['address'] = $contact['address'];
        }
        
        // Transform pricing data
        if (isset($extractedData['pricing']) && is_array($extractedData['pricing'])) {
            $pricing = $extractedData['pricing'];
            
            if (isset($pricing['amount']) && $pricing['amount'] !== null) {
                $transformed['price'] = $pricing['amount'];
                $transformed['amount'] = $pricing['amount'];
                
                // Create formatted price with currency
                if (isset($pricing['currency'])) {
                    $transformed['currency'] = $pricing['currency'];
                    $transformed['price_formatted'] = $pricing['currency'] . ' ' . $pricing['amount'];
                }
            }
            
            if (isset($pricing['payment_terms'])) $transformed['payment_terms'] = $pricing['payment_terms'];
            if (isset($pricing['validity'])) $transformed['validity'] = $pricing['validity'];
        }
        
        // Transform dates data
        if (isset($extractedData['dates']) && is_array($extractedData['dates'])) {
            $dates = $extractedData['dates'];
            
            if (isset($dates['pickup'])) $transformed['pickup_date'] = $dates['pickup'];
            if (isset($dates['delivery'])) $transformed['delivery_date'] = $dates['delivery'];
            if (isset($dates['quote_date'])) $transformed['quote_date'] = $dates['quote_date'];
        }
        
        // Transform cargo data
        if (isset($extractedData['cargo']) && is_array($extractedData['cargo'])) {
            $cargo = $extractedData['cargo'];
            
            if (isset($cargo['description'])) $transformed['cargo_description'] = $cargo['description'];
            if (isset($cargo['quantity'])) $transformed['quantity'] = $cargo['quantity'];
            if (isset($cargo['packaging'])) $transformed['packaging'] = $cargo['packaging'];
            if (isset($cargo['dangerous_goods'])) $transformed['dangerous_goods'] = $cargo['dangerous_goods'] ? 'yes' : 'no';
            if (isset($cargo['special_handling'])) $transformed['special_handling'] = $cargo['special_handling'];
        }
        
        // Add additional info if present
        if (isset($extractedData['additional_info']) && $extractedData['additional_info'] !== null) {
            $transformed['notes'] = $extractedData['additional_info'];
            $transformed['additional_info'] = $extractedData['additional_info'];
        }
        
        // Add extraction metadata
        $transformed['extraction_method'] = 'ai_vision';
        $transformed['extraction_timestamp'] = now()->toIso8601String();
        
        // Handle enhanced vehicle fields from database and AI
        if (isset($extractedData['vehicle']) && is_array($extractedData['vehicle'])) {
            $vehicle = $extractedData['vehicle'];
            
            // Enhanced specifications from database/AI
            if (isset($vehicle['wheelbase_m'])) $transformed['wheelbase'] = $vehicle['wheelbase_m'] . ' m';
            if (isset($vehicle['cargo_volume_m3'])) $transformed['cargo_volume'] = $vehicle['cargo_volume_m3'] . ' m³';
            if (isset($vehicle['calculated_volume_m3'])) $transformed['calculated_volume'] = $vehicle['calculated_volume_m3'] . ' m³';
            if (isset($vehicle['shipping_weight_class'])) $transformed['shipping_class'] = $vehicle['shipping_weight_class'];
            if (isset($vehicle['typical_container'])) $transformed['typical_container'] = $vehicle['typical_container'];
            if (isset($vehicle['shipping_notes'])) $transformed['shipping_notes'] = $vehicle['shipping_notes'];
            if (isset($vehicle['recommended_container'])) $transformed['recommended_container'] = $vehicle['recommended_container'];
            
            // Transform enhanced engine data
            if (isset($vehicle['engine_cc']) && $vehicle['engine_cc'] !== null) {
                $transformed['engine_size'] = (string)$vehicle['engine_cc'];
                $transformed['engine_cc'] = (string)$vehicle['engine_cc'];
            }
        }
        
        // Add data source attribution
        if (isset($extractedData['data_sources'])) {
            $transformed['data_attribution'] = json_encode($extractedData['data_sources']);
            $transformed['fields_from_document'] = count($extractedData['data_sources']['document_extracted'] ?? []);
            $transformed['fields_from_database'] = count($extractedData['data_sources']['database_enhanced'] ?? []);
            $transformed['fields_from_ai'] = count($extractedData['data_sources']['ai_enhanced'] ?? []);
            $transformed['fields_calculated'] = count($extractedData['data_sources']['calculated'] ?? []);
        }

        if (isset($extractedData['enhancement_metadata'])) {
            $transformed['enhancement_confidence'] = $extractedData['enhancement_metadata']['confidence'];
            $transformed['enhanced_at'] = $extractedData['enhancement_metadata']['enhanced_at'];
            $transformed['enhancement_time_ms'] = $extractedData['enhancement_metadata']['enhancement_time_ms'];
        }

        // CRITICAL: Add raw JSON field for Robaws JSON tab
        // This is what Robaws expects to display in the JSON tab
        $transformed['JSON'] = json_encode([
            'extraction_info' => [
                'extracted_at' => now()->toIso8601String(),
                'extraction_method' => $this->getName(),
                'confidence_score' => $transformed['enhancement_confidence'] ?? 0.8,
                'document_type' => 'image',
                'vision_model' => config('ai.vision_model', 'gpt-4o')
            ],
            'vehicle_specifications' => $extractedData['vehicle'] ?? [],
            'shipment_details' => $extractedData['shipment'] ?? [],
            'contact_information' => $extractedData['contact'] ?? [],
            'pricing_information' => $extractedData['pricing'] ?? [],
            'dates' => $extractedData['dates'] ?? [],
            'cargo_details' => $extractedData['cargo'] ?? [],
            'enhancement_details' => [
                'data_sources' => $extractedData['data_sources'] ?? [],
                'enhancement_metadata' => $extractedData['enhancement_metadata'] ?? [],
                'calculated_fields' => [
                    'volume' => $extractedData['vehicle']['calculated_volume_m3'] ?? null,
                    'weight_class' => $extractedData['vehicle']['shipping_weight_class'] ?? null,
                    'container_recommendation' => $extractedData['vehicle']['recommended_container'] ?? null
                ]
            ],
            'original_extraction' => $extractedData,
            'transformation_applied' => 'robaws_compatibility'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Also add alternative raw JSON fields that Robaws might look for
        $transformed['raw_json'] = $transformed['JSON'];
        $transformed['extraction_json'] = $transformed['JSON'];

        return $transformed;
    }

    public function extract(Document $document): ExtractionResult
    {
        $startTime = microtime(true);
        
        Log::info('Starting image extraction', [
            'document_id' => $document->id,
            'filename' => $document->filename,
            'mime_type' => $document->mime_type,
            'strategy' => $this->getName()
        ]);

        try {
            // Get image content from storage
            $imageContent = Storage::disk($document->storage_disk)->get($document->file_path);
            
            if (!$imageContent) {
                throw new \RuntimeException('Could not read image file: ' . $document->file_path);
            }

            Log::info('Image file loaded successfully', [
                'document_id' => $document->id,
                'file_size' => strlen($imageContent),
                'mime_type' => $document->mime_type
            ]);

            // Use AiRouter to extract data from image using vision AI
            $extractionInput = [
                'bytes' => base64_encode($imageContent),
                'mime' => $document->mime_type,
                'filename' => $document->filename
            ];

            $aiResult = $this->aiRouter->extractAdvanced($extractionInput, 'comprehensive');

            // Process the AI result - check for extracted_data key
            if (empty($aiResult['extracted_data'])) {
                throw new \RuntimeException('No data extracted from image by AI vision');
            }

            // Get the extracted data
            $extractedData = $aiResult['extracted_data'];
            
            // NEW: Enhance with hybrid data (database + AI)
            if (config('extraction.enable_vehicle_enhancement', true)) {
                try {
                    $enhancer = app(\App\Services\Extraction\VehicleDataEnhancer::class);
                    $extractedData = $enhancer->enhance($extractedData, [
                        'document_id' => $document->id,
                        'extraction_strategy' => $this->getName(),
                        'mime_type' => $document->mime_type,
                        'file_size' => strlen($imageContent)
                    ]);
                    
                    Log::info('Vehicle data enhanced successfully', [
                        'document_id' => $document->id,
                        'sources_used' => $extractedData['enhancement_metadata']['sources_used'] ?? []
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Vehicle enhancement failed, continuing with original data', [
                        'error' => $e->getMessage(),
                        'document_id' => $document->id
                    ]);
                    // Continue with original data if enhancement fails
                }
            }
            
            // Transform the enhanced data to Robaws-compatible format
            $transformedData = $this->transformForRobaws($extractedData);
            
            $confidence = $aiResult['confidence'] ?? 0;

            Log::info('Image extraction completed', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'original_fields' => array_keys($extractedData),
                'transformed_fields' => array_keys($transformedData),
                'vehicle_found' => !empty($extractedData['vehicle']),
                'contact_found' => !empty($extractedData['contact']),
                'shipment_found' => !empty($extractedData['shipment']),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

            // Add image-specific metadata
            $metadata = $aiResult['metadata'] ?? [];
            $metadata['extraction_strategy'] = $this->getName();
            $metadata['image_file_size'] = strlen($imageContent);
            $metadata['document_type'] = 'image';
            $metadata['filename'] = $document->filename;
            $metadata['source'] = 'ai_vision_extraction';
            $metadata['processing_time'] = microtime(true) - $startTime;
            $metadata['vision_model'] = config('ai.vision_model', 'gpt-4o');
            $metadata['original_structured_data'] = $extractedData;  // Keep original structure in metadata
            $metadata['transformation_applied'] = 'robaws_compatibility';

            return ExtractionResult::success(
                $transformedData,  // Use transformed data for Robaws compatibility
                $confidence,
                $this->getName(),
                $metadata
            );

        } catch (\Exception $e) {
            $processingTime = microtime(true) - $startTime;
            
            Log::error('Image extraction failed', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'error' => $e->getMessage(),
                'processing_time_ms' => round($processingTime * 1000, 2),
                'strategy' => $this->getName()
            ]);

            return ExtractionResult::failure(
                $e->getMessage(),
                $this->getName(),
                [
                    'extraction_strategy' => $this->getName(),
                    'document_type' => 'image',
                    'filename' => $document->filename,
                    'error_details' => $e->getMessage(),
                    'processing_time' => $processingTime,
                    'source' => 'ai_vision_extraction_failed'
                ]
            );
        }
    }

    /**
     * Check if the image file is accessible and readable
     */
    private function validateImageFile(Document $document): bool
    {
        try {
            return Storage::disk($document->storage_disk)->exists($document->file_path) &&
                   Storage::disk($document->storage_disk)->size($document->file_path) > 0;
        } catch (\Exception $e) {
            Log::warning('Image file validation failed', [
                'document_id' => $document->id,
                'file_path' => $document->file_path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get supported image formats for this strategy
     */
    public function getSupportedFormats(): array
    {
        return [
            'mime_types' => [
                'image/jpeg',
                'image/jpg',
                'image/png', 
                'image/gif',
                'image/webp'
            ],
            'extensions' => [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp'
            ]
        ];
    }

    /**
     * Estimate processing complexity for the image
     */
    private function estimateComplexity(string $imageContent): string
    {
        $fileSize = strlen($imageContent);
        
        if ($fileSize > 5 * 1024 * 1024) { // > 5MB
            return 'high';
        } elseif ($fileSize > 1 * 1024 * 1024) { // > 1MB
            return 'medium';
        } else {
            return 'low';
        }
    }
}
