<?php

namespace App\Services\Extraction\Contact;

use App\Services\Extraction\Contact\ContactExtractionResult;
use App\Services\Extraction\Contact\ContactEntity;
use App\Services\Extraction\Contact\ContactEmail;
use App\Services\Extraction\Contact\ContactPhone;
use App\Services\Extraction\Contact\ContactName;

class ContactFieldExtractor
{
    private array $extractedContacts = [];
    private array $sourceTracking = [];
    
    /**
     * Extract contact information with source tracking
     */
    public function extractContactInfo(string $content, string $sourceType = 'document'): ContactExtractionResult
    {
        $contacts = [];
        $confidence = 0.0;
        
        // Extract emails with context
        $emails = $this->extractEmailsWithContext($content);
        
        // Extract phone numbers with validation
        $phones = $this->extractPhonesWithValidation($content);
        
        // Extract names from context
        $names = $this->extractNamesFromContext($content, $emails, $phones);
        
        // Combine into contact entities
        $contacts = $this->combineIntoContacts($emails, $phones, $names);
        
        // Calculate confidence based on completeness
        $confidence = $this->calculateContactConfidence($contacts);
        
        return new ContactExtractionResult([
            'contacts' => $contacts,
            'confidence' => $confidence,
            'source' => $sourceType,
            'extraction_method' => 'pattern_matching',
            'metadata' => [
                'emails_found' => count($emails),
                'phones_found' => count($phones),
                'names_found' => count($names),
                'complete_contacts' => count(array_filter($contacts, fn($c) => $this->isCompleteContact($c)))
            ]
        ]);
    }
    
    /**
     * Extract email addresses with surrounding context
     */
    private function extractEmailsWithContext(string $content): array
    {
        $emails = [];
        
        // Enhanced email pattern with context capture
        $pattern = '/(?:([A-Za-z\s]+)[\s\:\-<]*)?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})(?:[\s\>\,\;]*([A-Za-z\s]+))?/i';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $email = trim($match[2][0]);
                
                // Skip common system emails
                if ($this->isSystemEmail($email)) {
                    continue;
                }
                
                $emails[] = new ContactEmail([
                    'email' => $email,
                    'context_before' => trim($match[1][0] ?? ''),
                    'context_after' => trim($match[3][0] ?? ''),
                    'position' => $match[2][1],
                    'source' => 'document_extraction',
                    'confidence' => $this->calculateEmailConfidence($email, $match)
                ]);
            }
        }
        
        return $emails;
    }
    
    /**
     * Extract phone numbers with validation
     */
    private function extractPhonesWithValidation(string $content): array
    {
        $phones = [];
        
        // Multiple phone patterns
        $patterns = [
            // US formats: (123) 456-7890, 123-456-7890, 123.456.7890
            '/(\(?\d{3}\)?)[\s\.\-]?(\d{3})[\s\.\-]?(\d{4})/',
            // International: +1 123 456 7890, +44 20 1234 5678
            '/\+(\d{1,3})[\s\.\-]?(\d{1,4})[\s\.\-]?(\d{3,4})[\s\.\-]?(\d{3,4})/',
            // Simple: 1234567890
            '/\b(\d{10,14})\b/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $match) {
                    $rawNumber = $match[0][0];
                    $cleanNumber = $this->cleanPhoneNumber($rawNumber);
                    
                    // Validate phone number
                    if ($this->isValidPhoneNumber($cleanNumber)) {
                        $phones[] = new ContactPhone([
                            'raw_number' => $rawNumber,
                            'clean_number' => $cleanNumber,
                            'formatted_number' => $this->formatPhoneNumber($cleanNumber),
                            'country_code' => $this->extractCountryCode($cleanNumber),
                            'position' => $match[0][1],
                            'source' => 'document_extraction',
                            'confidence' => $this->calculatePhoneConfidence($cleanNumber, $rawNumber)
                        ]);
                    }
                }
            }
        }
        
        return array_unique($phones, SORT_REGULAR);
    }
    
    /**
     * Extract names from context around emails and phones
     */
    private function extractNamesFromContext(string $content, array $emails, array $phones): array
    {
        $names = [];
        $processedNames = [];
        
        // Look for names in email contexts
        foreach ($emails as $email) {
            $name = $this->extractNameFromEmailContext($email, $content);
            if ($name && !in_array(strtolower($name), $processedNames)) {
                $names[] = new ContactName([
                    'name' => $name,
                    'source' => 'email_context',
                    'confidence' => 0.8,
                    'associated_email' => $email->email
                ]);
                $processedNames[] = strtolower($name);
            }
        }
        
        // Look for names in common patterns
        $namePatterns = [
            '/(?:from|sender|contact|name)[\s\:]+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i',
            '/([A-Z][a-z]+\s+[A-Z][a-z]+)(?:\s+<|\s+at\s+|\s+\()/i',
        ];
        
        foreach ($namePatterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $name = trim($match);
                    if (strlen($name) > 3 && !in_array(strtolower($name), $processedNames)) {
                        $names[] = new ContactName([
                            'name' => $name,
                            'source' => 'pattern_extraction',
                            'confidence' => 0.6
                        ]);
                        $processedNames[] = strtolower($name);
                    }
                }
            }
        }
        
        return $names;
    }
    
    /**
     * Combine emails, phones, and names into contact entities
     */
    private function combineIntoContacts(array $emails, array $phones, array $names): array
    {
        $contacts = [];
        
        // Create contacts from emails as primary
        foreach ($emails as $email) {
            $contact = new ContactEntity([
                'email' => $email->email,
                'source' => $email->source,
                'confidence' => $email->confidence
            ]);
            
            // Find associated name
            $associatedName = $this->findAssociatedName($email, $names);
            if ($associatedName) {
                $contact->setName($associatedName->name);
                $contact->addConfidence($associatedName->confidence);
            }
            
            // Find associated phone
            $associatedPhone = $this->findAssociatedPhone($email, $phones);
            if ($associatedPhone) {
                $contact->setPhone($associatedPhone->formatted_number);
                $contact->addConfidence($associatedPhone->confidence);
            }
            
            $contacts[] = $contact;
        }
        
        // Add standalone phone contacts
        foreach ($phones as $phone) {
            if (!$this->phoneAlreadyAssigned($phone, $contacts)) {
                $contact = new ContactEntity([
                    'phone' => $phone->formatted_number,
                    'source' => $phone->source,
                    'confidence' => $phone->confidence
                ]);
                
                $associatedName = $this->findAssociatedNameForPhone($phone, $names);
                if ($associatedName) {
                    $contact->setName($associatedName->name);
                    $contact->addConfidence($associatedName->confidence);
                }
                
                $contacts[] = $contact;
            }
        }
        
        return $contacts;
    }
    
    /**
     * Helper methods for contact processing
     */
    private function isSystemEmail(string $email): bool
    {
        $systemDomains = ['noreply', 'no-reply', 'system', 'admin', 'notification'];
        $emailLower = strtolower($email);
        
        foreach ($systemDomains as $domain) {
            if (str_contains($emailLower, $domain)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function cleanPhoneNumber(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone);
    }
    
    private function isValidPhoneNumber(string $phone): bool
    {
        // Must be between 10-15 digits (excluding country code +)
        $digitsOnly = preg_replace('/[^\d]/', '', $phone);
        return strlen($digitsOnly) >= 10 && strlen($digitsOnly) <= 15;
    }
    
    private function formatPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/[^\d]/', '', $phone);
        
        if (strlen($digits) == 10) {
            return sprintf('(%s) %s-%s', 
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }
        
        if (strlen($digits) == 11 && $digits[0] == '1') {
            return sprintf('+1 (%s) %s-%s', 
                substr($digits, 1, 3),
                substr($digits, 4, 3),
                substr($digits, 7, 4)
            );
        }
        
        return $phone; // Return original if can't format
    }
    
    private function extractCountryCode(string $phone): ?string
    {
        if (str_starts_with($phone, '+1')) {
            return '+1';
        }
        
        if (preg_match('/^\+(\d{1,3})/', $phone, $matches)) {
            return '+' . $matches[1];
        }
        
        return null;
    }
    
    private function calculateEmailConfidence(string $email, array $match): float
    {
        $confidence = 0.5; // Base confidence
        
        // Higher confidence for common TLDs
        if (preg_match('/\.(com|org|net|edu|gov)$/', $email)) {
            $confidence += 0.2;
        }
        
        // Higher confidence if has context
        if (!empty(trim($match[1][0] ?? ''))) {
            $confidence += 0.2;
        }
        
        // Higher confidence for business domains
        if (!preg_match('/(gmail|yahoo|hotmail|outlook)/', $email)) {
            $confidence += 0.1;
        }
        
        return min(1.0, $confidence);
    }
    
    private function calculatePhoneConfidence(string $clean, string $raw): float
    {
        $confidence = 0.6; // Base confidence
        
        // Higher confidence for formatted numbers
        if (preg_match('/[\(\)\-\.\s]/', $raw)) {
            $confidence += 0.2;
        }
        
        // Higher confidence for US numbers
        if (strlen(preg_replace('/[^\d]/', '', $clean)) == 10) {
            $confidence += 0.1;
        }
        
        // Higher confidence for international format
        if (str_starts_with($clean, '+')) {
            $confidence += 0.1;
        }
        
        return min(1.0, $confidence);
    }
    
    private function calculateContactConfidence(array $contacts): float
    {
        if (empty($contacts)) {
            return 0.0;
        }
        
        $totalConfidence = 0;
        foreach ($contacts as $contact) {
            $totalConfidence += $contact->getConfidence();
        }
        
        return $totalConfidence / count($contacts);
    }
    
    private function isCompleteContact(ContactEntity $contact): bool
    {
        return $contact->hasEmail() && $contact->hasName();
    }
    
    private function extractNameFromEmailContext(ContactEmail $email, string $content): ?string
    {
        // Try to extract name from email prefix
        $emailParts = explode('@', $email->email);
        $prefix = $emailParts[0];
        
        // Convert common patterns to names
        if (preg_match('/^([a-z]+)\.([a-z]+)$/', $prefix, $matches)) {
            return ucfirst($matches[1]) . ' ' . ucfirst($matches[2]);
        }
        
        // Check context before email
        if ($email->context_before) {
            $context = trim($email->context_before);
            if (preg_match('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)$/', $context, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    private function findAssociatedName(ContactEmail $email, array $names): ?ContactName
    {
        foreach ($names as $name) {
            if (isset($name->associated_email) && $name->associated_email === $email->email) {
                return $name;
            }
        }
        
        return null;
    }
    
    private function findAssociatedPhone(ContactEmail $email, array $phones): ?ContactPhone
    {
        // Simple proximity-based association
        foreach ($phones as $phone) {
            if (abs($phone->position - $email->position) < 100) {
                return $phone;
            }
        }
        
        return null;
    }
    
    private function phoneAlreadyAssigned(ContactPhone $phone, array $contacts): bool
    {
        foreach ($contacts as $contact) {
            if ($contact->hasPhone() && $contact->getPhone() === $phone->formatted_number) {
                return true;
            }
        }
        
        return false;
    }
    
    private function findAssociatedNameForPhone(ContactPhone $phone, array $names): ?ContactName
    {
        foreach ($names as $name) {
            if (abs($name->position ?? 0 - $phone->position) < 50) {
                return $name;
            }
        }
        
        return null;
    }
}
