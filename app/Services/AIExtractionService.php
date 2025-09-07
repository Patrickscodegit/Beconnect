<?php

namespace App\Services;

use App\Services\ExtractionService;
use Illuminate\Support\Facades\Log;

/**
 * AI Extraction Service Wrapper
 * 
 * This service acts as a bridge between production expectations and the 
 * existing ExtractionService, providing text-based extraction capabilities
 * with fallback patterns for when AI extraction is unavailable.
 */
class AIExtractionService
{
    /**
     * Extract data from text content using pattern matching
     * 
     * This service provides a consistent interface for text extraction
     * with fallback to pattern-based extraction when AI services are unavailable.
     */
    public function __construct()
    {
        // Constructor intentionally empty - no dependencies needed for text extraction
    }
    
    /**
     * Extract data from text content using AI
     *
     * @param string $text The text content to extract from
     * @return array|null Extracted data in consistent format
     */
    public function extractFromText(string $text): ?array
    {
        try {
            Log::info('AIExtractionService: Starting text extraction', [
                'text_length' => strlen($text),
                'preview' => substr($text, 0, 100)
            ]);

            // Use pattern-based extraction
            // Note: ExtractionService only works with files (extractFromFile), 
            // so we use fallback for direct text extraction
            $result = $this->fallbackExtraction($text);
            
            if ($result) {
                Log::info('AIExtractionService: Extraction successful', [
                    'extracted_fields' => array_keys($result),
                    'has_contact' => !empty($result['contact'])
                ]);
                
                return [
                    'data' => $result,
                    'metadata' => [
                        'extraction_method' => 'pattern_fallback',
                        'extracted_at' => now()->toISOString(),
                        'service' => 'AIExtractionService',
                        'confidence' => 0.7
                    ]
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('AIExtractionService: Extraction failed', [
                'error' => $e->getMessage(),
                'text_preview' => substr($text, 0, 200)
            ]);
            
            return null;
        }
    }
    
    /**
     * Fallback extraction using pattern matching
     * 
     * @param string $text The text to extract from
     * @return array Extracted data
     */
    private function fallbackExtraction(string $text): array
    {
        $result = [
            'contact' => [],
            'vehicle' => [],
            'shipping' => [],
            'metadata' => [
                'extraction_method' => 'pattern_fallback'
            ]
        ];
        
        // Extract contact information
        $result['contact'] = array_merge($result['contact'], $this->extractContactInfo($text));
        
        // Extract vehicle information  
        $result['vehicle'] = array_merge($result['vehicle'], $this->extractVehicleInfo($text));
        
        // Extract shipping/route information
        $result['shipping'] = array_merge($result['shipping'], $this->extractShippingInfo($text));
        
        // Legacy flat structure for backward compatibility
        $result['customer_name'] = $result['contact']['name'] ?? null;
        $result['contact_email'] = $result['contact']['email'] ?? null;
        $result['contact_phone'] = $result['contact']['phone'] ?? null;
        
        return array_filter($result, function($value) {
            return !empty($value);
        });
    }
    
    /**
     * Extract contact information from text
     */
    private function extractContactInfo(string $text): array
    {
        $contact = [];
        
        // Extract email addresses
        $email = $this->extractPattern('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text);
        if ($email) {
            $contact['email'] = $email;
        }
        
        // Extract phone numbers (international and local formats)
        $phone = $this->extractPattern('/[\+]?[(]?[0-9]{1,3}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,4}[-\s\.]?[0-9]{1,9}/', $text);
        if ($phone) {
            $contact['phone'] = $phone;
        }
        
        // Extract names from email or signature patterns
        if ($email) {
            $contact['name'] = $this->extractNameFromEmail($email, $text);
        }
        
        // Extract company from email domain or text
        if ($email) {
            $contact['company'] = $this->extractCompanyFromEmail($email);
        }
        
        return $contact;
    }
    
    /**
     * Extract vehicle information from text
     */
    private function extractVehicleInfo(string $text): array
    {
        $vehicle = [];
        
        // Extract VIN (17 characters, excluding I, O, Q)
        $vin = $this->extractPattern('/\b[A-HJ-NPR-Z0-9]{17}\b/', $text);
        if ($vin) {
            $vehicle['vin'] = $vin;
        }
        
        // Extract vehicle make and model patterns
        $makeModel = $this->extractPattern('/(?:vehicle|car|auto)[\s:]+([A-Za-z0-9\s]{5,40})/i', $text);
        if ($makeModel) {
            $vehicle['description'] = trim($makeModel);
        }
        
        // Extract year pattern
        $year = $this->extractPattern('/(?:year|model year)[\s:]+(\d{4})/i', $text);
        if ($year) {
            $vehicle['year'] = $year;
        }
        
        return $vehicle;
    }
    
    /**
     * Extract shipping/route information from text
     */
    private function extractShippingInfo(string $text): array
    {
        $shipping = [];
        
        // Extract route patterns (from X to Y)
        if (preg_match('/from\s+([^to]+?)\s+to\s+([^,\.\n]+)/i', $text, $matches)) {
            $shipping['origin'] = trim($matches[1]);
            $shipping['destination'] = trim($matches[2]);
        } elseif (preg_match('/([A-Za-z\s,]+)\s*[-→]\s*([A-Za-z\s,]+)/', $text, $matches)) {
            $shipping['origin'] = trim($matches[1]);
            $shipping['destination'] = trim($matches[2]);
        }
        
        // Extract dates
        $date = $this->extractPattern('/(?:date|delivery|pickup)[\s:]+(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i', $text);
        if ($date) {
            $shipping['date'] = $date;
        }
        
        return $shipping;
    }
    
    /**
     * Extract a pattern from text and return the match
     */
    private function extractPattern(string $pattern, string $text): ?string
    {
        if (preg_match($pattern, $text, $matches)) {
            return isset($matches[1]) ? trim($matches[1]) : trim($matches[0]);
        }
        return null;
    }
    
    /**
     * Extract name from email context
     */
    private function extractNameFromEmail(string $email, string $text): ?string
    {
        // Look for "Name <email>" pattern
        $namePattern = '/([A-Z][a-z]+\s+[A-Z][a-z]+)\s*<' . preg_quote($email, '/') . '>/';
        if (preg_match($namePattern, $text, $matches)) {
            return trim($matches[1]);
        }
        
        // Look for name patterns near the email
        $emailPos = strpos($text, $email);
        if ($emailPos !== false) {
            $context = substr($text, max(0, $emailPos - 100), 200);
            if (preg_match('/([A-Z][a-z]+\s+[A-Z][a-z]+)/', $context, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
    }
    
    /**
     * Extract company name from email domain
     */
    private function extractCompanyFromEmail(string $email): ?string
    {
        if (preg_match('/@([^\.]+)\./', $email, $matches)) {
            $domain = $matches[1];
            return ucfirst(strtolower($domain));
        }
        
        return null;
    }
}
