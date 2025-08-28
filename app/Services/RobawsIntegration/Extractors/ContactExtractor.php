<?php

namespace App\Services\RobawsIntegration\Extractors;

class ContactExtractor
{
    /**
     * Extract contact information from various possible structures
     */
    public function extract(array $data): array
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
        
        return [
            'name' => $contact['name'] ?? 'Unknown Customer',
            'email' => $contact['email'] ?? $this->findEmail($data),
            'phone' => $contact['phone'] ?? $contact['phone_number'] ?? null,
            'company' => $contact['company'] ?? null,
        ];
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
