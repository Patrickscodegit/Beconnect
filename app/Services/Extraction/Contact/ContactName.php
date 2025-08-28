<?php

namespace App\Services\Extraction\Contact;

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
