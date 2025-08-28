<?php

namespace App\Services\Extraction\Contact;

class ContactExtractionResult
{
    public array $contacts;
    public float $confidence;
    public string $source;
    public string $extraction_method;
    public array $metadata;
    
    public function __construct(array $data)
    {
        $this->contacts = $data['contacts'] ?? [];
        $this->confidence = $data['confidence'] ?? 0.0;
        $this->source = $data['source'] ?? 'unknown';
        $this->extraction_method = $data['extraction_method'] ?? 'unknown';
        $this->metadata = $data['metadata'] ?? [];
    }
    
    public function getContacts(): array
    {
        return $this->contacts;
    }
    
    public function getConfidence(): float
    {
        return $this->confidence;
    }
    
    public function getSource(): string
    {
        return $this->source;
    }
    
    public function hasContacts(): bool
    {
        return !empty($this->contacts);
    }
    
    public function getCompleteContacts(): array
    {
        return array_filter($this->contacts, fn($contact) => $contact->isComplete());
    }
    
    public function toArray(): array
    {
        return [
            'contacts' => array_map(fn($contact) => $contact->toArray(), $this->contacts),
            'confidence' => $this->confidence,
            'source' => $this->source,
            'extraction_method' => $this->extraction_method,
            'metadata' => $this->metadata
        ];
    }
}
