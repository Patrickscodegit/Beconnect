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
