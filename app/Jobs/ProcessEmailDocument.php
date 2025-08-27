<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Extraction;
use App\Services\EmailParserService;
use App\Services\AiRouter;
use App\Services\VehicleLookupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessEmailDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    protected Document $document;

    /**
     * Create a new job instance.
     */
    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Processing email document', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename,
                'mime_type' => $this->document->mime_type,
            ]);

            // Parse the email
            $emailParser = app(EmailParserService::class);
            $emailData = $emailParser->parseEmlFile($this->document);
            
            // Extract shipping content for AI analysis
            $shippingContent = $emailParser->extractShippingContent($emailData);
            
            // Create extraction record
            $extraction = Extraction::create([
                'document_id' => $this->document->id,
                'intake_id' => $this->document->intake_id,
                'analysis_type' => 'email_shipping',
                'service_used' => 'email_ai_router',
                'status' => 'processing',
                'confidence' => 0.0,
                'raw_json' => '{}',
                'extracted_data' => [],
                'metadata' => [
                    'email_parsed' => true,
                    'has_attachments' => !empty($emailData['attachments']),
                    'attachments_count' => count($emailData['attachments'] ?? []),
                    'subject' => $emailData['subject'] ?? '',
                    'from' => $emailData['from'] ?? '',
                    'date' => $emailData['date'] ?? '',
                ],
            ]);

            Log::info('Email parsing completed, starting AI extraction', [
                'document_id' => $this->document->id,
                'extraction_id' => $extraction->id,
                'content_length' => strlen($shippingContent),
            ]);

            // Use AiRouter to extract shipping information from email content
            $aiRouter = app(AiRouter::class);
            
            // Define shipping extraction schema with enhanced vehicle detection
            $schema = [
                'contact_info' => [
                    'type' => 'object',
                    'description' => 'Contact information from email',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Contact person name'],
                        'email' => ['type' => 'string', 'description' => 'Email address'],
                        'phone_number' => ['type' => 'string', 'description' => 'Phone number'],
                        'company' => ['type' => 'string', 'description' => 'Company name']
                    ]
                ],
                'shipping_details' => [
                    'type' => 'object',
                    'description' => 'Shipping route and logistics information',
                    'properties' => [
                        'origin' => ['type' => 'string', 'description' => 'Origin location or port (e.g., Bruxelles, Brussels)'],
                        'destination' => ['type' => 'string', 'description' => 'Destination location or port (e.g., Djeddah, Jeddah)'],
                        'route' => ['type' => 'string', 'description' => 'Complete shipping route'],
                        'service_type' => ['type' => 'string', 'description' => 'Type of shipping service (e.g., RoRo, maritime transport)']
                    ]
                ],
                'vehicle_info' => [
                    'type' => 'object',
                    'description' => 'Vehicle or cargo information - CRITICAL: Look for car brands and models',
                    'properties' => [
                        'brand' => ['type' => 'string', 'description' => 'Vehicle brand/make (e.g., BMW, Mercedes, Toyota)'],
                        'model' => ['type' => 'string', 'description' => 'Vehicle model (e.g., Série 7, E-Class, Camry)'],
                        'full_name' => ['type' => 'string', 'description' => 'Complete vehicle name (e.g., BMW Série 7)'],
                        'type' => ['type' => 'string', 'description' => 'Vehicle type (car, truck, van, etc.)'],
                        'year' => ['type' => 'string', 'description' => 'Vehicle year if mentioned'],
                        'color' => ['type' => 'string', 'description' => 'Vehicle color if mentioned'],
                        'specifications' => ['type' => 'string', 'description' => 'Vehicle specifications or details'],
                        'price' => ['type' => 'string', 'description' => 'Price or cost information']
                    ]
                ],
                'dates' => [
                    'type' => 'object',
                    'description' => 'Important dates and timing',
                    'properties' => [
                        'pickup_date' => ['type' => 'string', 'description' => 'Pickup or departure date'],
                        'delivery_date' => ['type' => 'string', 'description' => 'Delivery or arrival date'],
                        'requested_date' => ['type' => 'string', 'description' => 'Requested or preferred date']
                    ]
                ],
                'services_requested' => [
                    'type' => 'array',
                    'description' => 'List of services requested in the email',
                    'items' => ['type' => 'string']
                ],
                'messages' => [
                    'type' => 'array',
                    'description' => 'Email messages or conversation text',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'description' => 'Message text content'],
                            'sender' => ['type' => 'string', 'description' => 'Message sender']
                        ]
                    ]
                ]
            ];
            
            // Extract data using AiRouter with email-specific prompt
            $prompt = $this->buildEmailExtractionPrompt($emailData);
            $extractedData = $aiRouter->extract($prompt . "\n\n" . $shippingContent, $schema);

            // Structure the data for shipping emails
            $structuredData = $this->structureEmailExtractedData($extractedData, $emailData);

            // Calculate confidence
            $confidence = $this->calculateConfidence($structuredData);

            // Update extraction with results
            $extraction->update([
                'status' => 'completed',
                'extracted_data' => $structuredData,
                'confidence' => $confidence,
                'raw_json' => json_encode($extractedData),
                'processed_at' => now(),
            ]);

            // Update document
            $this->document->update([
                'extraction_data' => $structuredData,
                'extraction_confidence' => $confidence,
                'extraction_service' => 'email_ai_router',
                'extraction_status' => 'completed',
                'extracted_at' => now(),
            ]);

            Log::info('Email document processing completed successfully', [
                'document_id' => $this->document->id,
                'extraction_id' => $extraction->id,
                'confidence' => $confidence,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to process email document', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update extraction status if it exists
            if (isset($extraction)) {
                $extraction->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Build AI extraction prompt specifically for email content
     */
    private function buildEmailExtractionPrompt(array $emailData): string
    {
        $prompt = "You are analyzing an EMAIL that contains shipping/freight information. ";
        $prompt .= "The email may contain shipping quotes, transportation requests, or logistics communications.\n\n";
        
        $prompt .= "EMAIL CONTEXT:\n";
        if (!empty($emailData['subject'])) {
            $prompt .= "Subject: " . $emailData['subject'] . "\n";
        }
        if (!empty($emailData['from'])) {
            $prompt .= "From: " . $emailData['from'] . "\n";
        }
        if (!empty($emailData['date'])) {
            $prompt .= "Date: " . $emailData['date'] . "\n";
        }
        
        if (!empty($emailData['attachments'])) {
            $prompt .= "Attachments: ";
            $attachmentNames = array_map(function($att) {
                return $att['filename'];
            }, $emailData['attachments']);
            $prompt .= implode(', ', $attachmentNames) . "\n";
        }
        
        $prompt .= "\nPlease extract shipping/freight information from this email content. ";
        $prompt .= "Pay special attention to:\n";
        $prompt .= "- VEHICLE DETAILS: Look carefully for car brands like BMW, Mercedes, Toyota, Audi, etc. and their models (e.g., 'BMW Série 7', 'Mercedes E-Class')\n";
        $prompt .= "- ORIGIN and DESTINATION locations (cities, ports, countries)\n";
        $prompt .= "- CONTACT information (names, emails, phone numbers)\n";
        $prompt .= "- SHIPPING TYPE (RoRo, container, maritime transport, etc.)\n";
        $prompt .= "- Any pricing or quotes mentioned\n";
        $prompt .= "- Dates for pickup, delivery, or shipping\n\n";
        $prompt .= "The email may contain forwarded messages or quoted text. Extract all relevant information.\n\n";
        
        return $prompt;
    }

    /**
     * Structure extracted data specifically for email documents
     */
    private function structureEmailExtractedData(array $extractedData, array $emailData): array
    {
        return [
            'document_type' => 'Email Shipping Document',
            'status' => 'processed',
            'analysis_type' => 'email_shipping',
            'email_metadata' => [
                'subject' => $emailData['subject'] ?? '',
                'from' => $emailData['from'] ?? '',
                'to' => $emailData['to'] ?? '',
                'date' => $emailData['date'] ?? '',
                'has_attachments' => !empty($emailData['attachments']),
                'attachments' => array_map(function($att) {
                    return [
                        'filename' => $att['filename'],
                        'content_type' => $att['content_type'],
                        'size' => $att['size'] ?? 0,
                    ];
                }, $emailData['attachments'] ?? [])
            ],
            'contact' => [
                'name' => $this->extractValue($extractedData, ['contact_info.name', 'name', 'sender']),
                'email' => $this->extractValue($extractedData, ['contact_info.email', 'email']) ?: $emailData['from'],
                'phone' => $this->extractValue($extractedData, ['contact_info.phone_number', 'phone', 'contact_phone']),
                'company' => $this->extractValue($extractedData, ['contact_info.company', 'company', 'organization'])
            ],
            'shipment' => [
                'origin' => $this->extractValue($extractedData, ['shipping_details.origin', 'origin', 'from', 'pickup']),
                'destination' => $this->extractValue($extractedData, ['shipping_details.destination', 'destination', 'to', 'delivery']),
                'route' => $this->extractValue($extractedData, ['shipping_details.route', 'route', 'shipping_route']),
                'service_type' => $this->extractValue($extractedData, ['shipping_details.service_type', 'service', 'type'])
            ],
            'vehicle' => $this->enrichVehicleWithSpecs($extractedData),
            'dates' => [
                'pickup_date' => $this->extractValue($extractedData, ['dates.pickup_date', 'pickup', 'departure']),
                'delivery_date' => $this->extractValue($extractedData, ['dates.delivery_date', 'delivery', 'arrival']),
                'requested_date' => $this->extractValue($extractedData, ['dates.requested_date', 'requested', 'preferred'])
            ],
            'services_requested' => $extractedData['services_requested'] ?? [],
            'messages' => $extractedData['messages'] ?? [],
            'extracted_text' => $this->buildConversationText($extractedData),
            'metadata' => [
                'source' => 'Email AI Extraction',
                'confidence' => $this->calculateConfidence($extractedData),
                'processed_at' => now()->toIso8601String(),
                'service_used' => 'email_ai_router'
            ]
        ];
    }

    /**
     * Enrich vehicle data with database specifications
     */
    private function enrichVehicleWithSpecs(array $extractedData): array
    {
        // Build basic vehicle data first
        $vehicleData = [
            'brand' => $this->extractValue($extractedData, ['vehicle_info.brand', 'brand', 'make']),
            'model' => $this->extractValue($extractedData, ['vehicle_info.model', 'model']),
            'full_name' => $this->buildFullVehicleName($extractedData),
            'type' => $this->extractValue($extractedData, ['vehicle_info.type', 'vehicle_type', 'type']),
            'year' => $this->extractValue($extractedData, ['vehicle_info.year', 'year']),
            'color' => $this->extractValue($extractedData, ['vehicle_info.color', 'color']),
            'specifications' => $this->extractValue($extractedData, ['vehicle_info.specifications', 'specs', 'details']),
            'price' => $this->extractValue($extractedData, ['vehicle_info.price', 'price', 'cost', 'amount'])
        ];
        
        // Try to enrich with database specs
        try {
            $vehicleLookupService = app(VehicleLookupService::class);
            $enrichedData = $vehicleLookupService->enrichVehicleData($vehicleData);
            
            Log::info('Vehicle data enriched with database specs', [
                'original' => $vehicleData,
                'enriched' => $enrichedData,
                'has_database_match' => $enrichedData['database_match'] ?? false
            ]);
            
            return $enrichedData;
        } catch (\Exception $e) {
            Log::warning('Failed to enrich vehicle data with database specs', [
                'error' => $e->getMessage(),
                'vehicle_data' => $vehicleData
            ]);
            
            return $vehicleData;
        }
    }

    /**
     * Build full vehicle name from extracted data
     */
    private function buildFullVehicleName(array $extractedData): string
    {
        // Try to get from full_name field first
        $fullName = $this->extractValue($extractedData, ['vehicle_info.full_name', 'make_model', 'vehicle']);
        if (!empty($fullName)) {
            return $fullName;
        }
        
        // Build from brand and model
        $brand = $this->extractValue($extractedData, ['vehicle_info.brand', 'brand', 'make']);
        $model = $this->extractValue($extractedData, ['vehicle_info.model', 'model']);
        
        if (!empty($brand) && !empty($model)) {
            return $brand . ' ' . $model;
        }
        
        // Return individual components if available
        if (!empty($brand)) {
            return $brand;
        }
        
        if (!empty($model)) {
            return $model;
        }
        
        return '';
    }

    /**
     * Extract value from nested array using multiple possible keys
     */
    private function extractValue(array $data, array $possibleKeys): string
    {
        foreach ($possibleKeys as $key) {
            if (str_contains($key, '.')) {
                $value = $this->getNestedValue($data, $key);
            } else {
                $value = $this->findValueRecursive($data, $key);
            }
            
            if (!empty($value)) {
                if (is_array($value)) {
                    // Handle array values by extracting meaningful content
                    if (count($value) === 1 && isset($value[0]) && is_string($value[0])) {
                        return $value[0];
                    }
                    // For associative arrays, try to find meaningful text
                    if (isset($value['name'])) return (string) $value['name'];
                    if (isset($value['text'])) return (string) $value['text'];
                    if (isset($value['value'])) return (string) $value['value'];
                    
                    // Convert simple arrays to comma-separated string
                    $stringValues = array_filter(array_map(function($item) {
                        return is_string($item) ? $item : (is_scalar($item) ? (string) $item : '');
                    }, $value));
                    
                    return implode(', ', $stringValues);
                }
                
                return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            }
        }
        
        return '';
    }

    /**
     * Get nested value using dot notation
     */
    private function getNestedValue(array $array, string $key)
    {
        $keys = explode('.', $key);
        $current = $array;
        
        foreach ($keys as $k) {
            if (is_array($current) && isset($current[$k])) {
                $current = $current[$k];
            } else {
                return null;
            }
        }
        
        return $current;
    }

    /**
     * Find value recursively in nested array
     */
    private function findValueRecursive(array $array, string $key)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }
        
        foreach ($array as $k => $v) {
            if (is_string($k) && strtolower($k) === strtolower($key)) {
                return $v;
            }
            
            if (is_array($v)) {
                $result = $this->findValueRecursive($v, $key);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        
        return null;
    }

    /**
     * Calculate confidence score
     */
    private function calculateConfidence(array $extractedData): float
    {
        if (empty($extractedData)) {
            return 0.0;
        }

        $totalFields = 0;
        $filledFields = 0;

        $this->countFields($extractedData, $totalFields, $filledFields);

        if ($totalFields === 0) {
            return 0.0;
        }

        return round(($filledFields / $totalFields), 2);
    }

    /**
     * Recursively count fields for confidence calculation
     */
    private function countFields(array $data, int &$totalFields, int &$filledFields): void
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->countFields($value, $totalFields, $filledFields);
            } else {
                $totalFields++;
                if (!empty($value) && $value !== null && $value !== '') {
                    $filledFields++;
                }
            }
        }
    }

    /**
     * Build conversation text from extracted messages
     */
    private function buildConversationText(array $extractedData): string
    {
        $messages = $extractedData['messages'] ?? [];
        $texts = [];
        
        foreach ($messages as $message) {
            if (!empty($message['text'])) {
                $sender = !empty($message['sender']) ? "[{$message['sender']}]: " : "";
                $texts[] = $sender . $message['text'];
            }
        }
        
        return implode(' | ', $texts);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Email processing job permanently failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage()
        ]);

        $this->document->extractions()
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'extracted_data' => ['error' => 'Email processing failed: ' . $exception->getMessage()],
            ]);
    }
}
