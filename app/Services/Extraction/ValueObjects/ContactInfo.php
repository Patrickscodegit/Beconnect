<?php

namespace App\Services\Extraction\ValueObjects;

class ContactInfo
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $company = null,
        public string $source = 'unknown',
        public float $confidence = 0.0,
        public ?\Illuminate\Support\Collection $sources = null,
        public ?array $validation = null,
        public ?array $metadata = null
    ) {
        $this->sources = $this->sources ?? collect();
        $this->validation = $this->validation ?? ['valid' => false, 'errors' => [], 'warnings' => []];
        $this->metadata = $this->metadata ?? [];
    }
    
    public function isValid(): bool
    {
        return !empty($this->email) || !empty($this->phone);
    }
    
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            '_source' => $this->source,
            '_confidence' => $this->confidence
        ], fn($v) => $v !== null);
    }
}

class ExtractionSource
{
    public function __construct(
        public string $source,
        public ContactInfo $data,
        public float $confidence
    ) {}
}
