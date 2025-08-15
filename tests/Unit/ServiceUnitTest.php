<?php

use App\Services\LlmExtractor;

describe('LlmExtractor Unit Tests', function () {
    it('calculates rate limits correctly', function () {
        $llmExtractor = app(LlmExtractor::class);
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($llmExtractor);
        $rateLimiterProperty = $reflection->getProperty('rateLimiter');
        $rateLimiterProperty->setAccessible(true);
        
        $checkRateLimitMethod = $reflection->getMethod('checkRateLimit');
        $checkRateLimitMethod->setAccessible(true);
        
        // Should not throw on first call
        $checkRateLimitMethod->invoke($llmExtractor);
        
        // Check that rate limiter was updated
        $rateLimiter = $rateLimiterProperty->getValue($llmExtractor);
        $currentMinute = floor(time() / 60);
        
        expect($rateLimiter)->toHaveKey($currentMinute)
            ->and($rateLimiter[$currentMinute])->toBe(1);
    });

    it('provides structured fallback data', function () {
        $llmExtractor = app(LlmExtractor::class);
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($llmExtractor);
        $method = $reflection->getMethod('getFallbackExtraction');
        $method->setAccessible(true);
        
        $result = $method->invoke($llmExtractor);
        
        expect($result)
            ->toHaveKeys(['json', 'confidence'])
            ->and($result['json'])->toHaveKeys(['vehicles', 'parties', 'por', 'pol'])
            ->and($result['json']['vehicles'])->toBeArray()
            ->and($result['confidence'])->toBe(0.5);
    });
});

describe('DocumentService Unit Tests', function () {
    it('performs keyword-based classification correctly', function () {
        $documentService = app(\App\Services\DocumentService::class);
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($documentService);
        $method = $reflection->getMethod('keywordBasedClassification');
        $method->setAccessible(true);
        
        // Test different document types
        $vehicleText = 'This is a vehicle registration document with VIN number';
        $shippingText = 'Freight shipping manifest for cargo transport';
        $invoiceText = 'Invoice number 12345 total amount due $1000';
        $unknownText = 'This is some random text without keywords';
        
        expect($method->invoke($documentService, $vehicleText, 'reg.pdf'))
            ->toBe('vehicle_document')
            ->and($method->invoke($documentService, $shippingText, 'manifest.pdf'))
            ->toBe('shipping_document')
            ->and($method->invoke($documentService, $invoiceText, 'bill.pdf'))
            ->toBe('financial_document')
            ->and($method->invoke($documentService, $unknownText, 'random.pdf'))
            ->toBe('unknown');
    });

    it('extracts vehicle data with pattern matching', function () {
        $documentService = app(\App\Services\DocumentService::class);
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($documentService);
        $method = $reflection->getMethod('patternBasedExtraction');
        $method->setAccessible(true);
        
        $text = 'Vehicle: 2023 Toyota Camry, VIN: JT2BG22K1X0123456, Year: 2023';
        
        $result = $method->invoke($documentService, $text);
        
        expect($result)
            ->toHaveKey('vin', 'JT2BG22K1X0123456')
            ->toHaveKey('year', 2023)
            ->toHaveKey('make', 'Toyota');
    });
});
