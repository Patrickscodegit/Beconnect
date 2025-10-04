<?php

namespace App\Services;

use App\Models\IntakeFile;
use App\Models\Document;
use App\Services\Extraction\ExtractionPipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractionService
{
    public function __construct(
        private ExtractionPipeline $extractionPipeline
    ) {}

    /**
     * Extract data from an IntakeFile
     */
    public function extractFromFile(IntakeFile $file): ?array
    {
        Log::info('Extracting data from intake file', [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type
        ]);

        try {
            // Use the existing extraction pipeline which already handles images well
            return $this->extractUsingPipeline($file);

        } catch (\Exception $e) {
            Log::error('Exception during file extraction', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Use existing extraction pipeline for all file types including images
     */
    private function extractUsingPipeline(IntakeFile $file): ?array
    {
        // Create a temporary Document model for the extraction pipeline
        // The existing pipeline expects Document models
        $tempDocument = new Document([
            'filename' => $file->filename,
            'file_path' => $file->storage_path,  // Map storage_path to file_path for compatibility
            'storage_disk' => $file->storage_disk,
            'mime_type' => $file->mime_type,
            'file_size' => $file->file_size,
        ]);

        // Set the ID so the extraction can find the file
        $tempDocument->id = 'temp_' . $file->id;

        // Ensure the file exists
        if (!Storage::disk($file->storage_disk)->exists($file->storage_path)) {
            Log::error('File not found for extraction', [
                'file_id' => $file->id,
                'storage_path' => $file->storage_path,
                'storage_disk' => $file->storage_disk
            ]);
            return null;
        }

        Log::info('Processing file through extraction pipeline', [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'storage_path' => $file->storage_path,
            'storage_disk' => $file->storage_disk
        ]);

        // Run extraction using the existing pipeline
        $result = $this->extractionPipeline->process($tempDocument);

        if ($result->isSuccessful()) {
            Log::info('Extraction successful', [
                'file_id' => $file->id,
                'data_extracted' => !empty($result->getData()),
                'is_image' => str_starts_with($file->mime_type, 'image/'),
                'extraction_strategy' => $result->getStrategy(),
                'extracted_data_keys' => array_keys($result->getData())
            ]);
            
            return $this->formatExtractionData($result->getData(), $file, $result->getStrategy());
        } else {
            Log::warning('Extraction failed', [
                'file_id' => $file->id,
                'error' => $result->getErrorMessage(),
                'is_image' => str_starts_with($file->mime_type, 'image/'),
                'strategy_used' => $result->getStrategy()
            ]);
            return null;
        }
    }

    /**
     * Format extraction data for consistent structure
     */
    private function formatExtractionData(array $data, IntakeFile $file, string $strategy = 'unknown'): array
    {
        // Extract contact data from nested structure (supports both flat and nested formats)
        $contactEmail = $data['contact_email'] ?? $data['contact']['email'] ?? null;
        $contactPhone = $data['contact_phone'] ?? $data['contact']['phone'] ?? null;
        $customerName = $data['customer_name'] ?? $data['contact']['name'] ?? null;
        
        return [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'customer_name' => $customerName,
            'contact' => $data['contact'] ?? [], // Preserve nested contact data for ProcessIntake
            'raw_data' => $data,
            'service' => $strategy, // Include the strategy name for ExtractDocumentDataJob
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Perform OCR on image using AI vision
     */
    private function performOCR(IntakeFile $file): string
    {
        try {
            $imageContent = Storage::disk($file->storage_disk)->get($file->storage_path);
            
            // For now, we'll use AI vision for OCR
            // In the future, this could be extended to use Google Vision API or Tesseract
            return $this->performAIVisionOCR($imageContent);
            
        } catch (\Exception $e) {
            Log::error('OCR failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Use AI vision capabilities for OCR
     */
    private function performAIVisionOCR(string $imageContent): string
    {
        try {
            $base64Image = base64_encode($imageContent);
            
            $prompt = "Extract ALL text from this image. Include any contact information, addresses, phone numbers, email addresses, vehicle information, shipping details, dates, reference numbers, and any other relevant text. Return ONLY the extracted text, no commentary.";
            
            // Use the existing LLM extraction service
            $llmService = app(\App\Services\Extraction\LLMExtractionService::class);
            
            // Create a vision request
            $response = $llmService->extractWithVision($base64Image, $prompt);
            
            return $response['text'] ?? '';
            
        } catch (\Exception $e) {
            Log::error('AI Vision OCR failed', [
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Analyze image with AI when OCR doesn't return text
     */
    private function analyzeImageWithAI(IntakeFile $file): ?array
    {
        try {
            $imageContent = Storage::disk($file->storage_disk)->get($file->storage_path);
            $base64Image = base64_encode($imageContent);
            
            $prompt = "Analyze this image and extract any relevant information for a shipping/logistics quote. Look for:
                - Company names and logos
                - Contact information (emails, phones, addresses)
                - Vehicle details (make, model, VIN, license plates)
                - Shipping information (ports, destinations, dates)
                - Reference numbers or codes
                - Any text visible in the image
                
                Return the information in a structured JSON format.";
            
            $llmService = app(\App\Services\Extraction\LLMExtractionService::class);
            $response = $llmService->extractWithVision($base64Image, $prompt);
            
            if (isset($response['structured_data'])) {
                return $this->formatExtractionData($response['structured_data'], $file);
            }
            
            // Parse AI response into structured format
            $parsedData = $this->parseAIVisionResponse($response['text'] ?? '');
            return $this->formatExtractionData($parsedData, $file);
            
        } catch (\Exception $e) {
            Log::error('AI image analysis failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'metadata' => [
                    'extraction_method' => 'ai_vision_failed',
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Extract contact information from text using patterns
     */
    private function extractContactFromText(string $text): ?array
    {
        $contact = [];
        
        // Extract email
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches)) {
            $contact['email'] = $matches[0];
        }
        
        // Extract phone (various formats)
        $phonePatterns = [
            '/\+\d{1,3}[\s.-]?\d{1,4}[\s.-]?\d{1,4}[\s.-]?\d{1,9}/', // International
            '/\(\d{2,4}\)[\s.-]?\d{3,4}[\s.-]?\d{3,4}/', // With area code
            '/\d{3,4}[\s.-]\d{3,4}[\s.-]\d{3,4}/', // Standard format
        ];
        
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $contact['phone'] = $matches[0];
                break;
            }
        }
        
        // Extract company name (heuristic - look for common patterns)
        if (preg_match('/(?:Company|Société|Firma|Bedrijf|Entreprise)[\s:]+([A-Z][A-Za-z0-9\s&\-\.]+)/i', $text, $matches)) {
            $contact['company'] = trim($matches[1]);
        }
        
        // Extract person name (heuristic)
        if (preg_match('/(?:Contact|Name|Nom|Naam)[\s:]+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $text, $matches)) {
            $contact['name'] = trim($matches[1]);
            
            // Split into first and last name
            $nameParts = explode(' ', $contact['name'], 2);
            $contact['first_name'] = $nameParts[0] ?? '';
            $contact['last_name'] = $nameParts[1] ?? '';
        }
        
        return !empty($contact) ? $contact : null;
    }

    /**
     * Extract vehicle information from text
     */
    private function extractVehicleFromText(string $text): ?array
    {
        $vehicle = [];
        
        // Extract VIN
        if (preg_match('/\b[A-HJ-NPR-Z0-9]{17}\b/', $text, $matches)) {
            $vehicle['vin'] = $matches[0];
        }
        
        // Extract license plate patterns
        if (preg_match('/\b[A-Z]{1,3}[-\s]?\d{1,4}[-\s]?[A-Z]{1,3}\b/', $text, $matches)) {
            $vehicle['license_plate'] = $matches[0];
        }
        
        // Extract make/model (basic patterns)
        $carBrands = ['BMW', 'Mercedes', 'Audi', 'Volkswagen', 'Toyota', 'Honda', 'Ford', 'Chevrolet', 'Nissan', 'Hyundai'];
        foreach ($carBrands as $brand) {
            if (preg_match('/\b' . preg_quote($brand) . '\s+([A-Za-z0-9\s]+)/i', $text, $matches)) {
                $vehicle['make'] = $brand;
                $vehicle['model'] = trim($matches[1]);
                break;
            }
        }
        
        return !empty($vehicle) ? $vehicle : null;
    }

    /**
     * Extract shipping/logistics information from text
     */
    private function extractShippingFromText(string $text): ?array
    {
        $shipping = [];
        
        // Extract ports (common European ports)
        $ports = ['Antwerp', 'Rotterdam', 'Hamburg', 'Le Havre', 'Bremerhaven', 'Felixstowe', 'Göteborg', 'Valencia'];
        foreach ($ports as $port) {
            if (stripos($text, $port) !== false) {
                $shipping['ports'][] = $port;
            }
        }
        
        // Extract dates
        if (preg_match('/\b\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}\b/', $text, $matches)) {
            $shipping['date'] = $matches[0];
        }
        
        // Extract reference numbers
        if (preg_match('/(?:Ref|Reference|Order)[\s#:]*([A-Z0-9]{4,})/i', $text, $matches)) {
            $shipping['reference'] = $matches[1];
        }
        
        return !empty($shipping) ? $shipping : null;
    }

    /**
     * Parse AI vision response into structured format
     */
    private function parseAIVisionResponse(string $text): array
    {
        // Try to extract JSON from the response
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) {
                return $json;
            }
        }
        
        // If no JSON found, extract contact info from plain text
        $data = [];
        $contactInfo = $this->extractContactFromText($text);
        if ($contactInfo) {
            $data['contact'] = $contactInfo;
        }
        
        $vehicleInfo = $this->extractVehicleFromText($text);
        if ($vehicleInfo) {
            $data['vehicle'] = $vehicleInfo;
        }
        
        $shippingInfo = $this->extractShippingFromText($text);
        if ($shippingInfo) {
            $data['shipping'] = $shippingInfo;
        }
        
        return $data;
    }
}
