<?php

namespace Tests\Unit;

use App\Services\LlmExtractor;
use App\Services\DocumentService;
use Tests\TestCase;

class ServiceUnitTest extends TestCase
{
    public function test_llm_extractor_can_be_instantiated(): void
    {
        $llmExtractor = app(LlmExtractor::class);
        
        $this->assertInstanceOf(LlmExtractor::class, $llmExtractor);
    }

    public function test_llm_extractor_truncates_payload_when_too_large(): void
    {
        $llmExtractor = app(LlmExtractor::class);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($llmExtractor);
        $method = $reflection->getMethod('truncatePayload');
        $method->setAccessible(true);
        
        $largePayload = [
            'intake_id' => 1,
            'documents' => [
                [
                    'name' => 'test.pdf',
                    'mime' => 'application/pdf',
                    'text' => str_repeat('A', 10000) // Large text
                ]
            ]
        ];
        
        $result = $method->invoke($llmExtractor, $largePayload, 5000);
        
        $this->assertEquals(1, $result['intake_id']);
        $this->assertStringContainsString('[NOTE] Truncated for size.', $result['documents'][0]['text']);
    }

    public function test_document_service_performs_keyword_based_classification(): void
    {
        $documentService = app(DocumentService::class);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($documentService);
        $method = $reflection->getMethod('keywordBasedClassification');
        $method->setAccessible(true);
        
        // Test different document types
        $vehicleText = 'This is a vehicle registration document with VIN number';
        $result = $method->invoke($documentService, $vehicleText, 'reg.pdf');
        
        $this->assertIsString($result);
    }

    public function test_document_service_extracts_vehicle_data_with_pattern_matching(): void
    {
        $documentService = app(DocumentService::class);
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($documentService);
        $method = $reflection->getMethod('patternBasedExtraction');
        $method->setAccessible(true);
        
        $text = 'Vehicle: 2023 Toyota Camry, VIN: JT2BG22K1X0123456, Year: 2023';
        
        $result = $method->invoke($documentService, $text);
        
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
    }
}
