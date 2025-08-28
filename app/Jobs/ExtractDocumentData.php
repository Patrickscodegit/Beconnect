<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Extraction;
use App\Services\DocumentService;
use App\Services\AiRouter;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Helpers\FileInput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractDocumentData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Document $document
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DocumentService $documentService, AiRouter $aiRouter): void
    {
        try {
            Log::info('Starting extraction for document', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename
            ]);

            // Create extraction record
            $extraction = $this->document->extractions()->create([
                'intake_id' => $this->document->intake_id,
                'status' => 'processing',
                'confidence' => 0.0,
                'service_used' => 'llm',
                'extracted_data' => [],
                'raw_json' => '{}',
            ]);

            // Extract text from document
            $text = '';
            try {
                $text = $documentService->extractText($this->document);
            } catch (\Exception $e) {
                Log::warning('Text extraction failed, will use basic analysis', [
                    'document_id' => $this->document->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            if (empty($text)) {
                // Try advanced extraction using FileInput helper
                try {
                    Log::info('Text extraction failed, trying advanced AI extraction', [
                        'document_id' => $this->document->id,
                        'filename' => $this->document->filename
                    ]);
                    
                    $fileInput = FileInput::forExtractor(
                        $this->document->file_path,
                        $this->document->mime_type ?? 'image/png'
                    );
                    
                    // Determine analysis type based on filename or content
                    $analysisType = $this->determineAnalysisType($this->document->filename);
                    
                    Log::info('Determined analysis type', [
                        'document_id' => $this->document->id,
                        'filename' => $this->document->filename,
                        'analysis_type' => $analysisType
                    ]);
                    
                    $extractedData = $aiRouter->extractAdvanced($fileInput, $analysisType);
                    
                    Log::info('AI extraction completed', [
                        'document_id' => $this->document->id,
                        'analysis_type' => $analysisType,
                        'extracted_keys' => array_keys($extractedData['extracted_data'] ?? []),
                        'status' => $extractedData['status'] ?? 'unknown'
                    ]);
                    
                    // Re-evaluate analysis type based on extracted content
                    $contentBasedType = $this->determineAnalysisTypeFromContent($extractedData['extracted_data'] ?? []);
                    if ($contentBasedType !== $analysisType) {
                        Log::info('Updated analysis type based on content', [
                            'document_id' => $this->document->id,
                            'original_type' => $analysisType,
                            'content_based_type' => $contentBasedType
                        ]);
                        $analysisType = $contentBasedType;
                    }
                    
                    // Check if extraction returned an error response (for backwards compatibility)
                    if (isset($extractedData['extracted_data']['error']) || isset($extractedData['extracted_data']['fallback'])) {
                        $errorMsg = $extractedData['extracted_data']['error'] ?? 'Unknown extraction error';
                        throw new \RuntimeException($errorMsg);
                    }
                    
                    // Structure the data for shipping documents
                    Log::info('Starting data structuring', [
                        'document_id' => $this->document->id,
                        'analysis_type' => $analysisType,
                        'extracted_data_keys' => array_keys($extractedData['extracted_data'] ?? [])
                    ]);
                    
                    try {
                        $structuredData = $this->structureExtractedData($extractedData, $analysisType);
                        
                        Log::info('Data structuring completed', [
                            'document_id' => $this->document->id,
                            'structured_keys' => array_keys($structuredData ?? [])
                        ]);
                    } catch (\Exception $structuringError) {
                        Log::error('Data structuring failed, using raw extracted data', [
                            'document_id' => $this->document->id,
                            'error' => $structuringError->getMessage(),
                            'trace' => $structuringError->getTraceAsString()
                        ]);
                        
                        // Fallback to raw extracted data with basic structure
                        $structuredData = [
                            'document_type' => 'AI Extracted Document',
                            'status' => 'processed',
                            'analysis_type' => $analysisType,
                            'raw_extracted_data' => $extractedData['extracted_data'] ?? [],
                            'metadata' => $extractedData['metadata'] ?? []
                        ];
                    }
                    
                    $extraction->update([
                        'status' => 'completed',
                        'extracted_data' => $structuredData,
                        'confidence' => $extractedData['metadata']['confidence_score'] ?? 0.8,
                        'raw_json' => json_encode($extractedData),
                        'service_used' => 'ai_router_' . $analysisType,
                        'analysis_type' => $analysisType,
                    ]);
                    
                    Log::info('Extraction completed with advanced AI extraction', [
                        'document_id' => $this->document->id,
                        'extraction_id' => $extraction->id,
                        'analysis_type' => $analysisType
                    ]);
                    return;
                    
                } catch (\Exception $e) {
                    Log::warning('Advanced AI extraction failed, falling back to basic analysis', [
                        'document_id' => $this->document->id,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Final fallback to basic analysis
                $documentData = $this->analyzeDocument($this->document);
                $extraction->update([
                    'status' => 'completed',
                    'extracted_data' => $documentData,
                    'confidence' => 0.6,
                    'raw_json' => json_encode($documentData),
                    'service_used' => 'basic_analyzer',
                    'analysis_type' => 'basic',
                ]);
                
                Log::info('Extraction completed with basic analysis (no text extracted)', [
                    'document_id' => $this->document->id,
                    'extraction_id' => $extraction->id
                ]);
                return;
            }

            // Classify document type and extract data
            try {
                $classification = $documentService->classifyDocument($this->document, $text);
                
                // Define extraction schema for freight forwarding documents
                $schema = [
                    'consignee' => [
                        'type' => 'object',
                        'description' => 'Consignee information',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Company or person name'],
                            'address' => ['type' => 'string', 'description' => 'Full address'],
                            'contact' => ['type' => 'string', 'description' => 'Phone or email']
                        ]
                    ],
                    'invoice' => [
                        'type' => 'object',
                        'description' => 'Invoice details',
                        'properties' => [
                            'number' => ['type' => 'string', 'description' => 'Invoice number'],
                            'amount' => ['type' => 'number', 'description' => 'Total amount'],
                            'currency' => ['type' => 'string', 'description' => 'Currency code'],
                            'date' => ['type' => 'string', 'description' => 'Invoice date']
                        ]
                    ],
                    'container' => [
                        'type' => 'object',
                        'description' => 'Container information',
                        'properties' => [
                            'number' => ['type' => 'string', 'description' => 'Container number'],
                            'size' => ['type' => 'string', 'description' => 'Container size'],
                            'type' => ['type' => 'string', 'description' => 'Container type']
                        ]
                    ],
                    'ports' => [
                        'type' => 'object',
                        'description' => 'Port information',
                        'properties' => [
                            'origin' => ['type' => 'string', 'description' => 'Port of origin'],
                            'destination' => ['type' => 'string', 'description' => 'Port of destination']
                        ]
                    ]
                ];
                
                // Extract structured data using AiRouter
                $extractedData = $aiRouter->extract($text, $schema);

                // Calculate confidence score
                $confidence = $this->calculateConfidence($extractedData ?? []);
                
                // Update extraction with results
                $extraction->update([
                    'status' => 'completed',
                    'extracted_data' => $extractedData ?? [],
                    'confidence' => $confidence,
                    'raw_json' => json_encode($extractedData),
                    'service_used' => 'ai_router',
                    'analysis_type' => 'detailed',
                ]);

                Log::info('Extraction completed successfully with AiRouter', [
                    'document_id' => $this->document->id,
                    'extraction_id' => $extraction->id,
                    'confidence' => $confidence
                ]);

                // Auto-format data for Robaws if extraction was successful
                if (!empty($extractedData) && $confidence > 0.5) {
                    try {
                        $robawsIntegration = app(EnhancedRobawsIntegrationService::class);
                        $robawsIntegration->processDocument($this->document, $extractedData);
                        
                        Log::info('Document data automatically formatted for Robaws using JSON mapping', [
                            'document_id' => $this->document->id
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to format data for Robaws (non-critical)', [
                            'document_id' => $this->document->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
            } catch (\Exception $e) {
                Log::warning('AiRouter extraction failed, using basic analysis', [
                    'document_id' => $this->document->id,
                    'error' => $e->getMessage()
                ]);
                
                // Fallback to basic analysis
                $documentData = $this->analyzeDocument($this->document);
                $extraction->update([
                    'status' => 'completed',
                    'extracted_data' => $documentData,
                    'confidence' => 0.6,
                    'raw_json' => json_encode($documentData),
                    'service_used' => 'basic_analyzer',
                    'analysis_type' => 'basic',
                ]);
                
                Log::info('Extraction completed with basic analysis', [
                    'document_id' => $this->document->id,
                    'extraction_id' => $extraction->id
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Extraction failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update extraction status
            if (isset($extraction)) {
                $extraction->update([
                    'status' => 'failed',
                    'extracted_data' => ['error' => $e->getMessage()],
                    'analysis_type' => 'failed',
                ]);
            }

            throw $e;
        }
    }

    private function calculateConfidence(array $extractedData): float
    {
        if (empty($extractedData)) {
            return 0.0;
        }

        $totalFields = count($extractedData);
        $filledFields = 0;

        foreach ($extractedData as $value) {
            if (!empty($value) && $value !== null && $value !== '') {
                $filledFields++;
            }
        }

        return round(($filledFields / $totalFields) * 100, 2) / 100;
    }

    private function analyzeDocument(Document $document): array
    {
        // Basic document analysis without external dependencies
        return [
            'document_name' => $document->filename,
            'file_size' => $document->file_size,
            'mime_type' => $document->mime_type,
            'upload_date' => $document->created_at->toISOString(),
            'analysis_type' => 'basic',
            'extracted_fields' => [
                'document_type' => $this->guessDocumentType($document->filename),
                'file_extension' => pathinfo($document->filename, PATHINFO_EXTENSION),
                'estimated_pages' => $this->estimatePages($document->file_size),
            ],
            'processing_notes' => 'Basic extraction completed - advanced AI extraction pending service configuration'
        ];
    }

    private function guessDocumentType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return match($extension) {
            'pdf' => 'PDF Document',
            'doc', 'docx' => 'Word Document',
            'xls', 'xlsx' => 'Excel Spreadsheet',
            'txt' => 'Text Document',
            'jpg', 'jpeg', 'png' => 'Image Document',
            default => 'Unknown Document Type'
        };
    }

    private function estimatePages(int $fileSize): int
    {
        // Rough estimation: 50KB per page for PDF
        return max(1, intval($fileSize / 50000));
    }

        /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Extraction job permanently failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage()
        ]);

        $this->document->extractions()
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'extracted_data' => ['error' => 'Job failed: ' . $exception->getMessage()],
            ]);
    }

    /**
     * Determine the analysis type based on filename and content patterns
     *
     * @param string $filename
     * @return string
     */
    private function determineAnalysisType(string $filename): string
    {
        $filename = strtolower($filename);
        
        // Check for shipping/logistics indicators
        if (str_contains($filename, 'whatsapp') || 
            str_contains($filename, 'screenshot') ||
            str_contains($filename, 'img_') ||
            str_contains($filename, 'photo') ||
            str_contains($filename, 'shipping') ||
            str_contains($filename, 'freight')) {
            return 'shipping';
        }
        
        // Check for invoice indicators
        if (str_contains($filename, 'invoice') ||
            str_contains($filename, 'bill') ||
            str_contains($filename, 'receipt')) {
            return 'detailed';
        }
        
        return 'basic';
    }

    /**
     * Determine analysis type based on extracted content
     *
     * @param array $extractedData
     * @return string
     */
    private function determineAnalysisTypeFromContent(array $extractedData): string
    {
        // Check for shipping/logistics indicators in extracted content
        $content = json_encode($extractedData, JSON_UNESCAPED_UNICODE);
        $contentLower = strtolower($content);
        
        // Look for shipping-related keywords in the extracted content
        $shippingKeywords = [
            'ship', 'shipping', 'freight', 'cargo', 'delivery', 'transport',
            'vehicle', 'truck', 'sprinter', 'mercedes', 'container',
            'origin', 'destination', 'pickup', 'drop', 'port',
            'antwerp', 'tema', 'ghana', 'whatsapp', 'quote'
        ];
        
        $matchingKeywords = 0;
        foreach ($shippingKeywords as $keyword) {
            if (str_contains($contentLower, $keyword)) {
                $matchingKeywords++;
            }
        }
        
        // If we find multiple shipping-related terms, classify as shipping
        if ($matchingKeywords >= 3) {
            return 'shipping';
        }
        
        // Check for invoice/billing patterns
        $invoiceKeywords = ['invoice', 'bill', 'payment', 'total', 'subtotal', 'tax', 'amount due'];
        $invoiceMatches = 0;
        foreach ($invoiceKeywords as $keyword) {
            if (str_contains($contentLower, $keyword)) {
                $invoiceMatches++;
            }
        }
        
        if ($invoiceMatches >= 2) {
            return 'detailed';
        }
        
        return 'basic';
    }

    /**
     * Structure extracted data based on analysis type
     *
     * @param array $extractedData
     * @param string $analysisType
     * @return array
     */
    private function structureExtractedData(array $extractedData, string $analysisType): array
    {
        if ($analysisType === 'shipping') {
            return $this->structureShippingData($extractedData);
        }
        
        // Return the extracted data as-is for other types
        return $extractedData;
    }

    /**
     * Structure shipping data for better usability
     *
     * @param array $rawData
     * @return array
     */
    private function structureShippingData(array $rawData): array
    {
        $extractedData = $rawData['extracted_data'] ?? [];
        $metadata = $rawData['metadata'] ?? [];
        
        // Extract shipping details from messages if available
        $origin = $this->extractLocationFromMessages($extractedData, 'from') ?: 
                  $this->extractValue($extractedData, ['origin', 'from', 'pickup', 'source']);
        $destination = $this->extractLocationFromMessages($extractedData, 'to') ?: 
                       $this->extractValue($extractedData, ['destination', 'to', 'delivery', 'target']);
        
        return [
            'document_type' => 'Shipping Document',
            'status' => $rawData['status'] ?? 'processed',
            'analysis_type' => 'shipping',
            'shipment' => [
                'origin' => $origin,
                'destination' => $destination,
                'vehicle' => [
                    'type' => $this->extractValue($extractedData, ['vehicle_info.make_model', 'vehicle_type', 'vehicle', 'truck_type', 'car_type']),
                    'model' => $this->extractValue($extractedData, ['vehicle_info.make_model', 'vehicle_model', 'model', 'make_model']),
                    'details' => $this->extractValue($extractedData, ['vehicle_info.details', 'vehicle_details', 'specifications', 'details'])
                ]
            ],
            'pricing' => [
                'amount' => $this->extractValue($extractedData, ['vehicle_info.price', 'price', 'amount', 'cost', 'total']),
                'currency' => $this->extractCurrency($extractedData) ?: 'EUR',
                'notes' => $this->extractValue($extractedData, ['vehicle_info.net_price', 'price_notes', 'pricing_details', 'cost_details'])
            ],
            'contact' => [
                'phone' => $this->extractValue($extractedData, ['contact_info.phone_number', 'phone', 'contact_phone', 'telephone']),
                'name' => $this->extractValue($extractedData, ['contact_info.name', 'name', 'contact_name', 'sender']),
                'company' => $this->extractValue($extractedData, ['contact_info.account_type', 'company', 'business', 'organization'])
            ],
            'dates' => [
                'requested' => $this->extractValue($extractedData, ['date', 'requested_date', 'pickup_date']),
                'extracted_at' => now()->toIso8601String()
            ],
            'extracted_text' => $this->extractConversationText($extractedData),
            'metadata' => [
                'source' => 'AI Vision Extraction',
                'confidence' => $metadata['confidence_score'] ?? 0.8,
                'processed_at' => now()->toIso8601String(),
                'service_used' => 'ai_router_shipping'
            ]
        ];
    }

    /**
     * Extract location information from messages
     */
    private function extractLocationFromMessages(array $extractedData, string $direction): string
    {
        $messages = $extractedData['messages'] ?? [];
        foreach ($messages as $message) {
            $text = strtolower($message['text'] ?? '');
            if ($direction === 'from' && (str_contains($text, 'from') || str_contains($text, 'antwerpern') || str_contains($text, 'antwerp'))) {
                if (preg_match('/from\s+([a-zA-Z\s,]+)(?:\s+to|$)/i', $message['text'], $matches)) {
                    return trim($matches[1]);
                }
                if (str_contains($text, 'antwerpern') || str_contains($text, 'antwerp')) {
                    return 'Antwerp';
                }
            }
            if ($direction === 'to' && (str_contains($text, 'to') || str_contains($text, 'tema') || str_contains($text, 'ghana'))) {
                if (preg_match('/to\s+([a-zA-Z\s,]+)/i', $message['text'], $matches)) {
                    return trim($matches[1]);
                }
                if (str_contains($text, 'tema') && str_contains($text, 'ghana')) {
                    return 'Tema, Ghana';
                }
            }
        }
        return '';
    }

    /**
     * Extract currency from pricing info
     */
    private function extractCurrency(array $extractedData): string
    {
        $price = $this->extractValue($extractedData, ['vehicle_info.price', 'price', 'amount']);
        if (str_contains($price, '€')) return 'EUR';
        if (str_contains($price, '$')) return 'USD';
        if (str_contains($price, '£')) return 'GBP';
        return 'EUR'; // default
    }

    /**
     * Extract conversation text from messages
     */
    private function extractConversationText(array $extractedData): string
    {
        $messages = $extractedData['messages'] ?? [];
        $texts = [];
        foreach ($messages as $message) {
            if (!empty($message['text'])) {
                $texts[] = $message['text'];
            }
        }
        return implode(' | ', $texts);
    }

    /**
     * Extract value from nested array using multiple possible keys
     *
     * @param array $data
     * @param array $possibleKeys
     * @return string
     */
    private function extractValue(array $data, array $possibleKeys): string
    {
        foreach ($possibleKeys as $key) {
            // Handle nested keys with dot notation
            if (str_contains($key, '.')) {
                $value = $this->getNestedValue($data, $key);
            } else {
                $value = $this->findValueRecursive($data, $key);
            }
            
            if (!empty($value)) {
                // Handle array values
                if (is_array($value)) {
                    // If it's a simple single-value array, return the value
                    if (count($value) === 1 && isset($value[0]) && is_string($value[0])) {
                        return $value[0];
                    }
                    // If it's an associative array with common keys, extract meaningful text
                    if (isset($value['make']) && isset($value['model'])) {
                        return $value['make'] . ' ' . $value['model'];
                    }
                    if (isset($value['type'])) return (string) $value['type'];
                    if (isset($value['name'])) return (string) $value['name'];
                    if (isset($value['text'])) return (string) $value['text'];
                    if (isset($value['value'])) return (string) $value['value'];
                    
                    // For vehicle data, try to create a readable string
                    if (isset($value['make']) || isset($value['model']) || isset($value['type'])) {
                        $parts = array_filter([
                            $value['make'] ?? '',
                            $value['model'] ?? '',
                            $value['type'] ?? ''
                        ]);
                        if (!empty($parts)) {
                            return implode(' ', $parts);
                        }
                    }
                    
                    // Otherwise convert to a readable format instead of JSON
                    if (count($value) === 1) {
                        return (string) reset($value);
                    }
                    
                    // Fallback to JSON for complex structures
                    return json_encode($value);
                }
                
                // Try to decode JSON strings and extract meaningful content
                if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        // Apply the same logic as above for decoded JSON
                        if (isset($decoded['make']) && isset($decoded['model'])) {
                            return $decoded['make'] . ' ' . $decoded['model'];
                        }
                        if (isset($decoded['type'])) return (string) $decoded['type'];
                        if (isset($decoded['name'])) return (string) $decoded['name'];
                    }
                }
                
                return is_string($value) ? $value : (string) $value;
            }
        }
        
        return '';
    }

    /**
     * Find value recursively in nested array
     *
     * @param array $array
     * @param string $key
     * @return mixed
     */
    private function findValueRecursive(array $array, string $key)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }
        
        // Check for case-insensitive match
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
}
