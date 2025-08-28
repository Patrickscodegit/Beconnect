<?php

namespace App\Services\Extraction\Strategies\Fields;

use App\Services\Extraction\Contracts\FieldExtractor;
use App\Services\Extraction\ValueObjects\ContactInfo;
use App\Services\Extraction\ValueObjects\ExtractionSource;
use Illuminate\Support\Collection;

class ContactFieldExtractor implements FieldExtractor
{
    // Contact patterns with confidence weights
    private array $patterns = [
        'email' => [
            'standard' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'confidence' => 1.0
        ],
        'phone' => [
            // International format with optional country code
            'international' => '/(?:\+|00)(?:[1-9]\d{0,2})[\s.-]?(?:\(?\d{1,4}\)?[\s.-]?)?(?:\d{1,4}[\s.-]?){1,3}\d{1,4}/',
            // North American format
            'north_american' => '/(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/',
            // Generic format
            'generic' => '/\b\d{3,4}[\s.-]?\d{3,4}[\s.-]?\d{3,4}\b/',
            'confidence' => 0.8
        ],
        'name' => [
            // Email signature patterns
            'signature' => '/(?:Best regards|Regards|Sincerely|Cordialement|Thanks|Thank you|Salutations),?\s*\n+\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3})/m',
            // From line patterns
            'from_line' => '/^From:\s*([^<\n]+?)(?:\s*<|$)/m',
            'confidence' => 0.7
        ]
    ];
    
    /**
     * Extract contact information with source tracking
     */
    public function extract(array $data, string $content = ''): ContactInfo
    {
        $sources = new Collection();
        
        // 1. Try structured data extraction first (highest confidence)
        $structured = $this->extractFromStructuredData($data);
        if ($structured->isValid()) {
            $sources->push(new ExtractionSource('structured_data', $structured, 0.95));
        }
        
        // 2. Extract from email metadata if available
        if (isset($data['email_metadata'])) {
            $metadata = $this->extractFromEmailMetadata($data['email_metadata']);
            if ($metadata->isValid()) {
                $sources->push(new ExtractionSource('email_metadata', $metadata, 0.9));
            }
        }
        
        // 3. Pattern-based extraction from content
        if (!empty($content)) {
            $patterns = $this->extractFromPatterns($content);
            if ($patterns->isValid()) {
                $sources->push(new ExtractionSource('content_patterns', $patterns, 0.7));
            }
        }
        
        // 4. Extract from messages if available
        if (isset($data['messages']) && is_array($data['messages'])) {
            $messages = $this->extractFromMessages($data['messages']);
            if ($messages->isValid()) {
                $sources->push(new ExtractionSource('messages', $messages, 0.6));
            }
        }
        
        // 5. Merge results intelligently
        $merged = $this->mergeContactInfo($sources);
        
        // Set metadata on the ContactInfo object
        $merged->confidence = $this->calculateConfidence($merged, $sources);
        $merged->sources = $sources;
        $merged->validation = $this->validateContact($merged);
        $merged->metadata = [
            'complete' => $this->isContactComplete($merged),
            'sources_count' => $sources->count(),
            'extracted_at' => now()->toISOString()
        ];
        
        return $merged;
    }
    
    /**
     * Extract from structured data fields
     */
    private function extractFromStructuredData(array $data): ContactInfo
    {
        // Define search paths with priority
        $searchPaths = [
            'contact' => 1.0,
            'contact_info' => 0.9,
            'customer' => 0.8,
            'sender' => 0.7,
            'from' => 0.6
        ];
        
        foreach ($searchPaths as $path => $priority) {
            $contactData = data_get($data, $path);
            if (is_array($contactData)) {
                return new ContactInfo(
                    name: $contactData['name'] ?? null,
                    email: $this->normalizeEmail($contactData['email'] ?? null),
                    phone: $this->normalizePhone($contactData['phone'] ?? $contactData['phone_number'] ?? null),
                    company: $contactData['company'] ?? $contactData['organization'] ?? null,
                    source: 'structured_data',
                    confidence: $priority
                );
            }
        }
        
        return new ContactInfo();
    }
    
    /**
     * Extract from email metadata
     */
    private function extractFromEmailMetadata(array $metadata): ContactInfo
    {
        $contact = new ContactInfo(source: 'email_metadata');
        
        // Parse FROM header
        if (!empty($metadata['from'])) {
            $parsed = $this->parseEmailHeader($metadata['from']);
            $contact->name = $parsed['name'];
            $contact->email = $parsed['email'];
            $contact->confidence = 0.9;
        }
        
        // Extract from other headers
        if (empty($contact->name) && !empty($metadata['sender_name'])) {
            $contact->name = $metadata['sender_name'];
        }
        
        // Extract company from domain if missing
        if (empty($contact->company) && !empty($contact->email)) {
            $contact->company = $this->inferCompanyFromEmail($contact->email);
        }
        
        return $contact;
    }
    
    /**
     * Extract using pattern matching
     */
    private function extractFromPatterns(string $content): ContactInfo
    {
        $contact = new ContactInfo(source: 'patterns');
        
        // Extract emails
        $emails = $this->extractEmails($content);
        if (!empty($emails)) {
            $contact->email = $emails[0]['value'];
            $contact->confidence = max($contact->confidence, $emails[0]['confidence']);
        }
        
        // Extract phone numbers
        $phones = $this->extractPhoneNumbers($content);
        if (!empty($phones)) {
            $contact->phone = $phones[0]['value'];
            $contact->confidence = max($contact->confidence, $phones[0]['confidence']);
        }
        
        // Extract names from signatures
        $names = $this->extractNames($content);
        if (!empty($names)) {
            $contact->name = $names[0]['value'];
            $contact->confidence = max($contact->confidence, $names[0]['confidence']);
        }
        
        return $contact;
    }
    
    /**
     * Extract from messages array
     */
    private function extractFromMessages(array $messages): ContactInfo
    {
        $contact = new ContactInfo(source: 'messages');
        $allText = '';
        
        foreach ($messages as $message) {
            $text = $message['text'] ?? $message['content'] ?? '';
            $allText .= "\n" . $text;
            
            // Look for structured contact info in message
            if (isset($message['from']) || isset($message['sender'])) {
                $senderInfo = $message['from'] ?? $message['sender'] ?? [];
                if (is_array($senderInfo)) {
                    $contact->name = $senderInfo['name'] ?? $contact->name;
                    $contact->email = $senderInfo['email'] ?? $contact->email;
                }
            }
        }
        
        // Extract from combined text if needed
        if (empty($contact->email)) {
            $emails = $this->extractEmails($allText);
            if (!empty($emails)) {
                $contact->email = $emails[0]['value'];
            }
        }
        
        if (empty($contact->name)) {
            $names = $this->extractNames($allText);
            if (!empty($names)) {
                $contact->name = $names[0]['value'];
            }
        }
        
        if (empty($contact->phone)) {
            $phones = $this->extractPhoneNumbers($allText);
            if (!empty($phones)) {
                $contact->phone = $phones[0]['value'];
            }
        }
        
        $contact->confidence = 0.6; // Lower confidence for message extraction
        
        return $contact;
    }
    
    /**
     * Parse email header (handles "Name <email>" format)
     */
    private function parseEmailHeader(string $header): array
    {
        $header = trim($header);
        
        // Match "Name <email@example.com>" format
        if (preg_match('/^(.+?)\s*<([^>]+)>$/', $header, $matches)) {
            return [
                'name' => $this->cleanName(trim($matches[1], ' "\'')),
                'email' => $this->normalizeEmail($matches[2])
            ];
        }
        
        // Just email address
        if (filter_var($header, FILTER_VALIDATE_EMAIL)) {
            return [
                'name' => null,
                'email' => $this->normalizeEmail($header)
            ];
        }
        
        return ['name' => null, 'email' => null];
    }
    
    /**
     * Extract emails with confidence scoring
     */
    private function extractEmails(string $content): array
    {
        $emails = [];
        
        // First, try to find the email pattern more conservatively
        $emailPattern = '/\b[A-Za-z0-9][A-Za-z0-9._%+-]*@[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Z|a-z]{2,}\b/';
        
        if (preg_match_all($emailPattern, $content, $matches)) {
            foreach ($matches[0] as $email) {
                $normalized = $this->normalizeEmail($email);
                if ($normalized && !$this->isGenericEmail($normalized)) {
                    $emails[] = [
                        'value' => $normalized,
                        'confidence' => $this->patterns['email']['confidence']
                    ];
                }
            }
        }
        
        // Sort by confidence and remove duplicates
        return collect($emails)
            ->unique('value')
            ->sortByDesc('confidence')
            ->values()
            ->toArray();
    }
    
    /**
     * Extract and validate phone numbers
     */
    private function extractPhoneNumbers(string $content): array
    {
        $phones = [];
        
        foreach (['international', 'north_american', 'generic'] as $type) {
            if (preg_match_all($this->patterns['phone'][$type], $content, $matches)) {
                foreach ($matches[0] as $phone) {
                    $cleaned = preg_replace('/[^\d+]/', '', $phone);
                    if (strlen($cleaned) >= 10) {
                        $phones[] = [
                            'value' => $cleaned,
                            'confidence' => $this->patterns['phone']['confidence']
                        ];
                    }
                }
            }
        }
        
        return collect($phones)
            ->unique('value')
            ->sortByDesc('confidence')
            ->values()
            ->toArray();
    }
    
    /**
     * Extract names from content
     */
    private function extractNames(string $content): array
    {
        $names = [];
        
        // Extract from signature patterns
        if (preg_match_all($this->patterns['name']['signature'], $content, $matches)) {
            foreach ($matches[1] as $name) {
                $cleaned = $this->cleanName($name);
                if ($this->isValidName($cleaned)) {
                    $names[] = [
                        'value' => $cleaned,
                        'confidence' => $this->patterns['name']['confidence']
                    ];
                }
            }
        }
        
        return collect($names)
            ->unique('value')
            ->sortByDesc('confidence')
            ->values()
            ->toArray();
    }
    
    /**
     * Merge contact information from multiple sources
     */
    private function mergeContactInfo(Collection $sources): ContactInfo
    {
        if ($sources->isEmpty()) {
            return new ContactInfo();
        }
        
        // Start with highest confidence source
        $merged = clone $sources->sortByDesc(fn($s) => $s->confidence)->first()->data;
        
        // Fill in missing fields from other sources
        foreach ($sources->sortByDesc(fn($s) => $s->confidence) as $source) {
            if (empty($merged->name) && !empty($source->data->name)) {
                $merged->name = $source->data->name;
            }
            if (empty($merged->email) && !empty($source->data->email)) {
                $merged->email = $source->data->email;
            }
            if (empty($merged->phone) && !empty($source->data->phone)) {
                $merged->phone = $source->data->phone;
            }
            if (empty($merged->company) && !empty($source->data->company)) {
                $merged->company = $source->data->company;
            }
        }
        
        // Set merged source
        $merged->source = 'merged';
        $merged->confidence = $sources->avg(fn($s) => $s->confidence);
        
        return $merged;
    }
    
    /**
     * Calculate overall confidence
     */
    private function calculateConfidence(ContactInfo $contact, Collection $sources): float
    {
        if ($sources->isEmpty()) {
            return 0.0;
        }
        
        // Base confidence from sources
        $baseConfidence = $sources->avg(fn($s) => $s->confidence);
        
        // Adjust based on completeness
        $completeness = 0;
        if (!empty($contact->name)) $completeness += 0.3;
        if (!empty($contact->email)) $completeness += 0.4;
        if (!empty($contact->phone)) $completeness += 0.2;
        if (!empty($contact->company)) $completeness += 0.1;
        
        return min(1.0, $baseConfidence * $completeness);
    }
    
    /**
     * Validate contact information
     */
    private function validateContact(ContactInfo $contact): array
    {
        $errors = [];
        $warnings = [];
        
        // Validate email
        if (!empty($contact->email)) {
            if (!filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format';
            } elseif ($this->isGenericEmail($contact->email)) {
                $warnings[] = 'Generic email address detected';
            }
        } else {
            $warnings[] = 'No email address found';
        }
        
        // Validate phone
        if (!empty($contact->phone)) {
            if (!preg_match('/^\+?\d{10,}$/', $contact->phone)) {
                $warnings[] = 'Phone number may be invalid';
            }
        }
        
        // Validate name
        if (empty($contact->name)) {
            $warnings[] = 'No contact name found';
        } elseif (!$this->isValidName($contact->name)) {
            $warnings[] = 'Contact name may be invalid';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Check if contact is complete
     */
    private function isContactComplete(ContactInfo $contact): bool
    {
        return !empty($contact->name) && (!empty($contact->email) || !empty($contact->phone));
    }
    
    /**
     * Helper methods
     */
    private function normalizeEmail(?string $email): ?string
    {
        if (empty($email)) return null;
        
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
    
    private function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) return null;
        
        // Keep only digits and + symbol
        return preg_replace('/[^\d+]/', '', $phone);
    }
    
    private function cleanName(?string $name): ?string
    {
        if (empty($name)) return null;
        
        // Remove extra whitespace and common artifacts
        $name = preg_replace('/\s+/', ' ', trim($name));
        $name = trim($name, '.,;:-');
        
        return $name;
    }
    
    private function isValidName(string $name): bool
    {
        // Basic validation
        return strlen($name) >= 2 && 
               strlen($name) <= 100 &&
               preg_match('/^[\p{L}\s\'-\.]+$/u', $name) &&
               !in_array(strtolower($name), ['admin', 'test', 'user', 'customer', 'unknown']);
    }
    
    private function isGenericEmail(string $email): bool
    {
        $genericPatterns = [
            'noreply', 'no-reply', 'donotreply', 'admin@', 'info@',
            'contact@', 'support@', 'sales@', 'test@'
        ];
        
        foreach ($genericPatterns as $pattern) {
            if (str_contains(strtolower($email), $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function inferCompanyFromEmail(string $email): ?string
    {
        $domain = substr(strrchr($email, "@"), 1);
        
        // Skip common email providers
        $commonProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com'];
        if (in_array($domain, $commonProviders)) {
            return null;
        }
        
        // Extract company name from domain
        $company = explode('.', $domain)[0];
        return ucfirst($company);
    }
}
