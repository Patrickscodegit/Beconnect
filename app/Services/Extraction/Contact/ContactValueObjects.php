<?php

namespace App\Services\Extraction\Contact;

class ContactEmail
{
    public string $email;
    public string $context_before;
    public string $context_after;
    public int $position;
    public string $source;
    public float $confidence;
    
    public function __construct(array $data)
    {
        $this->email = $data['email'];
        $this->context_before = $data['context_before'] ?? '';
        $this->context_after = $data['context_after'] ?? '';
        $this->position = $data['position'] ?? 0;
        $this->source = $data['source'] ?? 'unknown';
        $this->confidence = $data['confidence'] ?? 0.0;
    }
    
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'context_before' => $this->context_before,
            'context_after' => $this->context_after,
            'position' => $this->position,
            'source' => $this->source,
            'confidence' => $this->confidence
        ];
    }
}

class ContactPhone
{
    public string $raw_number;
    public string $clean_number;
    public string $formatted_number;
    public ?string $country_code;
    public int $position;
    public string $source;
    public float $confidence;
    
    public function __construct(array $data)
    {
        $this->raw_number = $data['raw_number'];
        $this->clean_number = $data['clean_number'];
        $this->formatted_number = $data['formatted_number'];
        $this->country_code = $data['country_code'] ?? null;
        $this->position = $data['position'] ?? 0;
        $this->source = $data['source'] ?? 'unknown';
        $this->confidence = $data['confidence'] ?? 0.0;
    }
    
    public function toArray(): array
    {
        return [
            'raw_number' => $this->raw_number,
            'clean_number' => $this->clean_number,
            'formatted_number' => $this->formatted_number,
            'country_code' => $this->country_code,
            'position' => $this->position,
            'source' => $this->source,
            'confidence' => $this->confidence
        ];
    }
}

class ContactName
{
    public string $name;
    public string $source;
    public float $confidence;
    public ?string $associated_email;
    public int $position;
    
    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->source = $data['source'] ?? 'unknown';
        $this->confidence = $data['confidence'] ?? 0.0;
        $this->associated_email = $data['associated_email'] ?? null;
        $this->position = $data['position'] ?? 0;
    }
    
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'associated_email' => $this->associated_email,
            'position' => $this->position
        ];
    }
}
