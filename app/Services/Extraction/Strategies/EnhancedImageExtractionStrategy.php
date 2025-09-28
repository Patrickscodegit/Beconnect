<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\Results\ExtractionResult;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * ENHANCED IMAGE EXTRACTION STRATEGY
 * 
 * This strategy is isolated from email processing and can be enhanced
 * without affecting the email pipeline. It uses its own dedicated
 * processing methods and won't interfere with other strategies.
 */
class EnhancedImageExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private AiRouter $aiRouter,
        private VehicleDatabaseService $vehicleDb
    ) {}

    public function getName(): string
    {
        return 'enhanced_image_extraction';
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
            'image/webp',
            'image/bmp',
            'image/tiff'
        ];

        return in_array($document->mime_type, $supportedMimeTypes) ||
               preg_match('/\.(jpe?g|png|gif|webp|bmp|tiff)$/i', $document->filename ?? '');
    }

    public function extract(Document $document): ExtractionResult
    {
        $startTime = microtime(true);
        
        Log::info('Starting ENHANCED image extraction', [
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

            Log::info('Image file loaded successfully (ENHANCED)', [
                'document_id' => $document->id,
                'file_size' => strlen($imageContent),
                'mime_type' => $document->mime_type
            ]);

            // Use dedicated image extraction pipeline (NOT shared HybridExtractionPipeline)
            $extractedData = $this->extractImageData($imageContent, $document);

            // Transform the enhanced data to Robaws-compatible format
            $transformedData = $this->transformForRobaws($extractedData);
            
            $confidence = $this->calculateConfidence($extractedData);

            Log::info('ENHANCED image extraction completed', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'original_fields' => array_keys($extractedData),
                'transformed_fields' => array_keys($transformedData),
                'vehicle_found' => !empty($extractedData['vehicle']),
                'contact_found' => !empty($extractedData['contact']),
                'shipment_found' => !empty($extractedData['shipment']),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'enhancement_status' => 'active'
            ]);

            // Add image-specific metadata
            $metadata = [
                'extraction_strategy' => $this->getName(),
                'image_file_size' => strlen($imageContent),
                'document_type' => 'image',
                'filename' => $document->filename,
                'source' => 'enhanced_image_extraction',
                'processing_time' => microtime(true) - $startTime,
                'vision_model' => config('ai.vision_model', 'gpt-4o'),
                'original_structured_data' => $extractedData,
                'transformation_applied' => 'robaws_compatibility',
                'enhancement_level' => 'advanced',
                'isolation_status' => 'protected'
            ];

            return ExtractionResult::success(
                $transformedData,
                $confidence,
                $this->getName(),
                $metadata
            );

        } catch (\Exception $e) {
            $processingTime = microtime(true) - $startTime;
            
            Log::error('ENHANCED image extraction failed', [
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
                    'source' => 'enhanced_image_extraction_failed'
                ]
            );
        }
    }

    /**
     * DEDICATED IMAGE DATA EXTRACTION
     * This method is completely isolated and can be enhanced without affecting email processing
     */
    private function extractImageData(string $imageContent, Document $document): array
    {
        $extractedData = [
            'contact' => [],
            'vehicle' => [],
            'shipment' => [],
            'pricing' => [],
            'dates' => [],
            'cargo' => []
        ];

        // Use AiRouter for vision-based extraction (isolated call)
        $extractionInput = [
            'bytes' => base64_encode($imageContent),
            'mime' => $document->mime_type,
            'filename' => $document->filename
        ];

        $aiResult = $this->aiRouter->extractAdvanced($extractionInput, 'comprehensive', 'enhanced_image_extraction');

        // Process the AI result
        if (!empty($aiResult['extracted_data'])) {
            $extractedData = array_merge($extractedData, $aiResult['extracted_data']);
        } else {
            // NEW: OCR fallback for text-heavy images when AI vision fails
            Log::info('AI vision extraction failed, trying OCR fallback', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'strategy' => 'enhanced_image_extraction'
            ]);
            
            $ocrData = $this->extractWithOCRFallback($imageContent, $document);
            if (!empty($ocrData)) {
                $extractedData = array_merge($extractedData, $ocrData);
                
                Log::info('OCR fallback extraction successful', [
                    'document_id' => $document->id,
                    'extracted_fields' => array_keys($ocrData),
                    'strategy' => 'enhanced_image_extraction'
                ]);
            }
        }

        // Enhanced post-processing for images
        $this->enhanceImageExtractionResults($extractedData, $document);

        return $extractedData;
    }

    /**
     * Enhanced post-processing for image extraction results
     */
    private function enhanceImageExtractionResults(array &$extractedData, Document $document): void
    {
        // Enhanced vehicle data processing
        if (!empty($extractedData['vehicle'])) {
            $this->enhanceVehicleData($extractedData['vehicle']);
        }

        // Enhanced contact data processing
        if (!empty($extractedData['contact']) && !empty($extractedData['contact']['name'])) {
            $this->enhanceContactData($extractedData['contact']);
        } else {
            // NEW: Fallback contact extraction from filename patterns
            $extractedData['contact'] = $this->extractContactFromFilename($document->filename);
            
            Log::info('Contact extraction fallback', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'extracted_contact' => $extractedData['contact'],
                'strategy' => 'enhanced_image_extraction'
            ]);
            
            // If still no contact, try to get from intake
            if (empty($extractedData['contact']) || empty($extractedData['contact']['name'])) {
                $intake = $document->intake;
                if ($intake && $intake->customer_name) {
                    $extractedData['contact'] = [
                        'name' => $intake->customer_name,
                        'email' => $intake->contact_email,
                        'phone' => $intake->contact_phone
                    ];
                    
                    Log::info('Contact extraction from intake fallback', [
                        'document_id' => $document->id,
                        'intake_id' => $intake->id,
                        'customer_name' => $intake->customer_name,
                        'contact_email' => $intake->contact_email,
                        'final_contact' => $extractedData['contact'],
                        'strategy' => 'enhanced_image_extraction'
                    ]);
                }
            }
        }

        // Enhanced shipment data processing
        if (!empty($extractedData['shipment'])) {
            $this->enhanceShipmentData($extractedData['shipment']);
        }

        // Add image-specific metadata
        $extractedData['_image_metadata'] = [
            'filename' => $document->filename,
            'mime_type' => $document->mime_type,
            'extraction_method' => 'enhanced_vision',
            'enhancement_applied' => true
        ];
    }

    /**
     * Enhance vehicle data extracted from images
     */
    private function enhanceVehicleData(array &$vehicleData): void
    {
        // Normalize make names
        if (!empty($vehicleData['make'])) {
            $vehicleData['make'] = $this->normalizeMakeName($vehicleData['make']);
        }

        // Validate and format VIN
        if (!empty($vehicleData['vin'])) {
            $vehicleData['vin'] = strtoupper(trim($vehicleData['vin']));
        }

        // Enhance dimensions if present
        if (!empty($vehicleData['dimensions'])) {
            $this->enhanceDimensions($vehicleData['dimensions']);
        }

        // NEW: Database enhancement for missing vehicle specs
        $this->enhanceWithVehicleDatabase($vehicleData);
    }

    /**
     * Enhance vehicle data with database lookup
     */
    private function enhanceWithVehicleDatabase(array &$vehicleData): void
    {
        try {
            // Use the enrichVehicleData method which handles all lookup strategies
            $enrichedData = $this->vehicleDb->enrichVehicleData($vehicleData);
            
            // Only merge if we got meaningful enhancement
            if ($enrichedData !== $vehicleData && !empty($enrichedData['database_match'])) {
                $vehicleData = $enrichedData;
                
                Log::info('Vehicle data enhanced with database lookup', [
                    'enhanced_fields' => array_keys($enrichedData),
                    'database_id' => $enrichedData['database_id'] ?? null,
                    'strategy' => 'enhanced_image_extraction'
                ]);
            }

        } catch (\Exception $e) {
            Log::warning('Vehicle database enhancement failed for image extraction', [
                'error' => $e->getMessage(),
                'strategy' => 'enhanced_image_extraction'
            ]);
        }
    }

    /**
     * Enhance contact data extracted from images
     */
    private function enhanceContactData(array &$contactData): void
    {
        // Normalize email addresses
        if (!empty($contactData['email'])) {
            $contactData['email'] = strtolower(trim($contactData['email']));
        }

        // Normalize phone numbers
        if (!empty($contactData['phone'])) {
            $contactData['phone'] = $this->normalizePhoneNumber($contactData['phone']);
        }

        // Clean up names
        if (!empty($contactData['name'])) {
            $contactData['name'] = $this->cleanName($contactData['name']);
        }
    }

    /**
     * Enhance shipment data extracted from images
     */
    private function enhanceShipmentData(array &$shipmentData): void
    {
        // Normalize port names
        if (!empty($shipmentData['origin'])) {
            $shipmentData['origin'] = $this->normalizePortName($shipmentData['origin']);
        }
        if (!empty($shipmentData['destination'])) {
            $shipmentData['destination'] = $this->normalizePortName($shipmentData['destination']);
        }

        // Normalize container types
        if (!empty($shipmentData['container_type'])) {
            $shipmentData['container_type'] = $this->normalizeContainerType($shipmentData['container_type']);
        }
    }

    /**
     * Normalize vehicle make names
     */
    private function normalizeMakeName(string $make): string
    {
        $normalizations = [
            'bmw' => 'BMW',
            'mercedes' => 'Mercedes-Benz',
            'mercedes-benz' => 'Mercedes-Benz',
            'audi' => 'Audi',
            'volkswagen' => 'Volkswagen',
            'vw' => 'Volkswagen',
            'toyota' => 'Toyota',
            'honda' => 'Honda',
            'ford' => 'Ford',
            'chevrolet' => 'Chevrolet',
            'chevy' => 'Chevrolet'
        ];

        $lowerMake = strtolower($make);
        return $normalizations[$lowerMake] ?? ucfirst($make);
    }

    /**
     * Normalize phone numbers
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-digit characters except +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Add country code if missing
        if (!str_starts_with($phone, '+') && !str_starts_with($phone, '00')) {
            $phone = '+32' . $phone; // Default to Belgium
        }
        
        return $phone;
    }

    /**
     * Clean up names
     */
    private function cleanName(string $name): string
    {
        // Remove extra whitespace and normalize
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        // Capitalize properly
        return ucwords(strtolower($name));
    }

    /**
     * Normalize port names
     */
    private function normalizePortName(string $port): string
    {
        $normalizations = [
            'antwerp' => 'Antwerp',
            'rotterdam' => 'Rotterdam',
            'hamburg' => 'Hamburg',
            'mombasa' => 'Mombasa',
            'dar es salaam' => 'Dar es Salaam',
            'lagos' => 'Lagos',
            'durban' => 'Durban'
        ];

        $lowerPort = strtolower($port);
        return $normalizations[$lowerPort] ?? ucwords($port);
    }

    /**
     * Normalize container types
     */
    private function normalizeContainerType(string $container): string
    {
        $normalizations = [
            '20ft' => '20ft',
            '20\'' => '20ft',
            '40ft' => '40ft',
            '40\'' => '40ft',
            'teu' => 'TEU',
            'feu' => 'FEU'
        ];

        $lowerContainer = strtolower($container);
        return $normalizations[$lowerContainer] ?? strtoupper($container);
    }

    /**
     * Enhance dimensions data
     */
    private function enhanceDimensions(array &$dimensions): void
    {
        // Ensure consistent units
        if (!empty($dimensions['unit'])) {
            $dimensions['unit'] = strtolower($dimensions['unit']);
        }

        // Convert to meters if needed
        if (!empty($dimensions['length']) && !empty($dimensions['unit'])) {
            if ($dimensions['unit'] === 'cm') {
                $dimensions['length'] = $dimensions['length'] / 100;
                $dimensions['unit'] = 'm';
            }
        }
    }

    /**
     * Transform structured AI extraction data to Robaws-compatible flat format
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
        $transformed['extraction_method'] = 'enhanced_image_vision';
        $transformed['extraction_timestamp'] = now()->toIso8601String();
        
        // CRITICAL: Add raw JSON field for Robaws JSON tab
        $transformed['JSON'] = json_encode([
            'extraction_info' => [
                'extracted_at' => now()->toIso8601String(),
                'extraction_method' => $this->getName(),
                'confidence_score' => $this->calculateConfidence($extractedData),
                'document_type' => 'image',
                'vision_model' => config('ai.vision_model', 'gpt-4o'),
                'enhancement_level' => 'advanced'
            ],
            'vehicle_specifications' => $extractedData['vehicle'] ?? [],
            'shipment_details' => $extractedData['shipment'] ?? [],
            'contact_information' => $extractedData['contact'] ?? [],
            'pricing_information' => $extractedData['pricing'] ?? [],
            'dates' => $extractedData['dates'] ?? [],
            'cargo_details' => $extractedData['cargo'] ?? [],
            'enhancement_details' => [
                'enhancement_applied' => true,
                'isolation_level' => 'complete',
                'processing_method' => 'enhanced_vision'
            ],
            'original_extraction' => $extractedData,
            'transformation_applied' => 'robaws_compatibility'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Also add alternative raw JSON fields that Robaws might look for
        $transformed['raw_json'] = $transformed['JSON'];
        $transformed['extraction_json'] = $transformed['JSON'];

        return $transformed;
    }

    /**
     * Calculate confidence based on extracted data quality
     */
    private function calculateConfidence(array $extractedData): float
    {
        $score = 0.6; // Base score for successful extraction
        
        // Increase score based on extracted fields
        if (!empty($extractedData['contact'])) $score += 0.1;
        if (!empty($extractedData['vehicle'])) $score += 0.1;
        if (!empty($extractedData['shipment'])) $score += 0.1;
        if (!empty($extractedData['pricing'])) $score += 0.1;
        
        return min(1.0, $score);
    }

    /**
     * OCR fallback extraction for text-heavy images
     */
    private function extractWithOCRFallback(string $imageContent, Document $document): array
    {
        $extractedData = [];
        
        try {
            // Use AiRouter with OCR-specific extraction mode
            $extractionInput = [
                'bytes' => base64_encode($imageContent),
                'mime' => $document->mime_type,
                'filename' => $document->filename
            ];

            // Try OCR-focused extraction
            $ocrResult = $this->aiRouter->extractAdvanced($extractionInput, 'ocr_fallback', 'ocr_text_heavy');

            if (!empty($ocrResult['extracted_data'])) {
                $extractedData = $ocrResult['extracted_data'];
                
                // Add OCR-specific metadata
                $extractedData['_ocr_metadata'] = [
                    'extraction_method' => 'ocr_fallback',
                    'fallback_used' => true,
                    'confidence' => $ocrResult['metadata']['confidence_score'] ?? 0.6
                ];
            }

        } catch (\Exception $e) {
            Log::warning('OCR fallback extraction failed', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'error' => $e->getMessage(),
                'strategy' => 'enhanced_image_extraction'
            ]);
        }
        
        return $extractedData;
    }

    /**
     * Extract contact information from filename patterns
     */
    private function extractContactFromFilename(string $filename): array
    {
        $contact = [];
        
        try {
            // Common WhatsApp screenshot patterns
            $patterns = [
                // "IMG_20241227_143022_John_Doe.jpg"
                '/IMG_\d{8}_\d{6}_(.+?)\./i',
                // "Screenshot_20241227_143022_John_Doe.jpg"
                '/Screenshot_\d{8}_\d{6}_(.+?)\./i',
                // "WhatsApp Image 2024-12-27 at 14.30.22_John_Doe.jpg"
                '/WhatsApp Image \d{4}-\d{2}-\d{2} at \d{2}\.\d{2}\.\d{2}_(.+?)\./i',
                // "John_Doe_vehicle_request.jpg"
                '/(.+?)_vehicle_request\./i',
                // "John_Doe_transport_quote.jpg"
                '/(.+?)_transport_quote\./i',
                // "John_Doe_shipping_inquiry.jpg"
                '/(.+?)_shipping_inquiry\./i',
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $filename, $matches)) {
                    $name = trim($matches[1]);
                    
                    // Clean up the name
                    $name = str_replace(['_', '-'], ' ', $name);
                    $name = preg_replace('/\s+/', ' ', $name);
                    $name = trim($name);
                    
                    if (!empty($name) && strlen($name) > 2) {
                        $contact['name'] = $name;
                        
                        Log::info('Contact extracted from filename pattern', [
                            'filename' => $filename,
                            'pattern' => $pattern,
                            'extracted_name' => $name,
                            'strategy' => 'enhanced_image_extraction'
                        ]);
                        break;
                    }
                }
            }
            
            // If no pattern matched, try to extract name from filename without extension
            if (empty($contact['name'])) {
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $name = str_replace(['_', '-'], ' ', $name);
                $name = preg_replace('/\s+/', ' ', $name);
                $name = trim($name);
                
                // Only use if it looks like a person's name (not too long, no numbers)
                if (!empty($name) && strlen($name) > 2 && strlen($name) < 50 && !preg_match('/\d/', $name)) {
                    $contact['name'] = $name;
                    
                    Log::info('Contact extracted from filename (fallback)', [
                        'filename' => $filename,
                        'extracted_name' => $name,
                        'strategy' => 'enhanced_image_extraction'
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            Log::warning('Contact extraction from filename failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'strategy' => 'enhanced_image_extraction'
            ]);
        }
        
        return $contact;
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
                'image/webp',
                'image/bmp',
                'image/tiff'
            ],
            'extensions' => [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp',
                'bmp',
                'tiff'
            ]
        ];
    }

    /**
     * Get information about this strategy
     */
    public function getInfo(): array
    {
        return [
            'name' => $this->getName(),
            'priority' => $this->getPriority(),
            'supported_types' => $this->getSupportedFormats()['mime_types'],
            'supported_extensions' => $this->getSupportedFormats()['extensions'],
            'description' => 'Enhanced image extraction with isolated processing',
            'isolation_level' => 'complete',
            'enhancement_features' => [
                'advanced_vision_extraction',
                'intelligent_field_detection',
                'context_aware_parsing',
                'enhanced_data_normalization',
                'robaws_compatibility_transform'
            ]
        ];
    }
}
