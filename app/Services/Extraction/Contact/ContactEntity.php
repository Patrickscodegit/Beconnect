<?php

namespace App\Services\Extraction\Contact;

class ContactEntity
{
    private ?string $name = null;
    private ?string $email = null;
    private ?string $phone = null;
    private string $source;
    private float $confidence;
    private array $metadata = [];
    
    public function __construct(array $data)
    {
        $this->name = $data['name'] ?? null;
        $this->email = $data['email'] ?? null;
        $this->phone = $data['phone'] ?? null;
        $this->source = $data['source'] ?? 'unknown';
        $this->confidence = $data['confidence'] ?? 0.0;
        $this->metadata = $data['metadata'] ?? [];
    }
    
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    
    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }
    
    public function setPhone(string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }
    
    public function addConfidence(float $confidence): self
    {
        $this->confidence = min(1.0, ($this->confidence + $confidence) / 2);
        return $this;
    }
    
    public function getName(): ?string
    {
        return $this->name;
    }
    
    public function getEmail(): ?string
    {
        return $this->email;
    }
    
    public function getPhone(): ?string
    {
        return $this->phone;
    }
    
    public function getSource(): string
    {
        return $this->source;
    }
    
    public function getConfidence(): float
    {
        return $this->confidence;
    }
    
    public function hasName(): bool
    {
        return !empty($this->name);
    }
    
    public function hasEmail(): bool
    {
        return !empty($this->email);
    }
    
    public function hasPhone(): bool
    {
        return !empty($this->phone);
    }
    
    public function isComplete(): bool
    {
        return $this->hasEmail() && $this->hasName();
    }
    
    public function getCompleteness(): float
    {
        $fields = [$this->name, $this->email, $this->phone];
        $filled = count(array_filter($fields, fn($field) => !empty($field)));
        return $filled / count($fields);
    }
    
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'completeness' => $this->getCompleteness(),
            'is_complete' => $this->isComplete(),
            'metadata' => $this->metadata
        ];
    }
    
    public function __toString(): string
    {
        $parts = array_filter([
            $this->name,
            $this->email,
            $this->phone
        ]);
        
        return implode(' | ', $parts) . sprintf(' (%.1f%% confidence)', $this->confidence * 100);
    }
}
