<?php

namespace App\Services\RobawsIntegration\Extractors;

use App\Services\Extraction\Strategies\Fields\ContactFieldExtractor as AdvancedContactExtractor;

class ContactExtractor
{
    private AdvancedContactExtractor $advancedExtractor;
    
    public function __construct()
    {
        $this->advancedExtractor = new AdvancedContactExtractor();
    }
    
    /**
     * Extract contact information from various possible structures
     * Uses the new advanced contact extractor with fallback to legacy format
     */
    public function extract(array $data): array
    {
        // Get the raw text content if available
        $content = $this->extractContentFromData($data);
        
        // Use advanced extractor
        $advancedResult = $this->advancedExtractor->extract($data, $content);
        
        // Return in the expected legacy format for backward compatibility
        $contact = $advancedResult['contact'];
        $metadata = $advancedResult['_metadata'];
        
        return [
            'name' => $contact['name'] ?? $this->fallbackExtractName($data),
            'email' => $contact['email'] ?? $this->findEmail($data),
            'phone' => $contact['phone'] ?? $this->fallbackExtractPhone($data),
            'company' => $contact['company'] ?? $this->fallbackExtractCompany($data),
            // Add metadata for transparency
            '_advanced_extraction' => [
                'confidence' => $metadata['confidence'],
                'sources' => $metadata['sources'],
                'complete' => $metadata['complete'],
                'validation' => $metadata['validation']
            ]
        ];
    }
    
    /**
     * Extract content text from various data structures
     */
    private function extractContentFromData(array $data): string
    {
        $content = '';
        
        // Try to get raw text content
        if (isset($data['extracted_text'])) {
            $content .= $data['extracted_text'] . "\n";
        }
        
        if (isset($data['raw_content'])) {
            $content .= $data['raw_content'] . "\n";
        }
        
        // Extract from messages
        if (isset($data['messages']) && is_array($data['messages'])) {
            foreach ($data['messages'] as $message) {
                $text = $message['text'] ?? $message['content'] ?? '';
                $content .= $text . "\n";
            }
        }
        
        return trim($content);
    }
    
    /**
     * Fallback methods for backward compatibility
     */
    private function fallbackExtractName(array $data): string
    {
        // Try multiple possible locations for contact data
        $contact = $this->tryExtractFromMultipleSources($data, [
            'contact',
            'contact_info',
            'sender',
            'customer'
        ]);
        
        // If no structured contact, try to extract from email metadata
        if (empty($contact['name']) && isset($data['email_metadata']['from'])) {
            $contact = array_merge($contact, $this->parseEmailFrom($data['email_metadata']['from']));
        }
        
        // Try to extract from messages if still missing
        if (empty($contact['name']) && isset($data['messages'])) {
            $contact = array_merge($contact, $this->extractFromMessages($data['messages']));
        }
        
        return $contact['name'] ?? 'Unknown Customer';
    }
    
    private function fallbackExtractPhone(array $data): ?string
    {
        $contact = $this->tryExtractFromMultipleSources($data, [
            'contact',
            'contact_info',
            'sender',
            'customer'
        ]);
        
        return $contact['phone'] ?? $contact['phone_number'] ?? null;
    }
    
    private function fallbackExtractCompany(array $data): ?string
    {
        $contact = $this->tryExtractFromMultipleSources($data, [
            'contact',
            'contact_info',
            'sender',
            'customer'
        ]);
        
        return $contact['company'] ?? null;
    }
    
    private function parseEmailFrom(string $from): array
    {
        // Parse "Name <email@example.com>" format
        if (preg_match('/^(.+?)\s*<(.+?)>$/', $from, $matches)) {
            return [
                'name' => trim($matches[1]),
                'email' => trim($matches[2])
            ];
        }
        
        // Just email address
        if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $from];
        }
        
        return [];
    }
    
    private function extractFromMessages(array $messages): array
    {
        $result = [];
        
        foreach ($messages as $message) {
            $text = $message['text'] ?? $message['content'] ?? '';
            
            // Look for name patterns in signatures
            if (preg_match('/(?:Best regards|Regards|Cordialement|Salutations),?\s*\n\s*([A-Za-z\s]+)/i', $text, $matches)) {
                $result['name'] = trim($matches[1]);
                break;
            }
            
            // Look for phone numbers
            if (preg_match('/(?:Tel|Phone|Tél)\.?\s*:?\s*([\d\s\+\-\(\)]{10,})/i', $text, $matches)) {
                $result['phone'] = preg_replace('/[^\d\+]/', '', $matches[1]);
            }
        }
        
        return $result;
    }
    
    private function findEmail(array $data): ?string
    {
        // Search for email in various locations
        $paths = [
            'contact.email',
            'contact_info.email',
            'email',
            'sender_email',
            'email_metadata.from'
        ];
        
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if ($value && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }
        
        // Try to extract from email_metadata.from
        if (!empty($data['email_metadata']['from'])) {
            $parsed = $this->parseEmailFrom($data['email_metadata']['from']);
            if (!empty($parsed['email'])) {
                return $parsed['email'];
            }
        }
        
        return null;
    }
    
    private function tryExtractFromMultipleSources(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        return [];
    }
}
