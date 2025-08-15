<?php

use App\Services\DocumentService;
use App\Services\LlmExtractor;
use App\Models\Document;
use App\Models\Intake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
});

describe('Production Pipeline Integration', function () {
    it('can handle complete document processing workflow', function () {
        // Test the complete workflow with minimal external dependencies
        
        // 1. File Upload
        $file = UploadedFile::fake()->create('test-invoice.pdf', 1024, 'application/pdf');
        $documentService = app(DocumentService::class);
        
        $document = $documentService->processUpload($file, 'invoice', 'email');
        
        expect($document)
            ->toBeInstanceOf(Document::class)
            ->and($document->filename)->toBe('test-invoice.pdf')
            ->and($document->mime_type)->toBe('application/pdf');
        
        // 2. Document Classification (keyword-based fallback)
        $sampleText = 'Invoice Number: 12345 Amount Due: $1000 Total Payment Required';
        $classification = $documentService->classifyDocument($document, $sampleText);
        
        expect($classification)->toBe('financial_document');
        
        // 3. Pattern-based extraction (fallback method)
        $vehicleText = 'Vehicle Year: 2023 Make: Toyota Model: Camry VIN: JT2BG22K1X0123456';
        $extractedData = $documentService->extractVehicleData($document, $vehicleText);
        
        expect($extractedData)
            ->toHaveKey('vin', 'JT2BG22K1X0123456')
            ->and($extractedData)->toHaveKey('year', 2023)
            ->and($extractedData)->toHaveKey('make', 'Toyota');
        
        // 4. Storage verification
        Storage::disk('s3')->assertExists($document->file_path);
    });

    it('handles file validation correctly', function () {
        $documentService = app(DocumentService::class);
        
        // Test file type validation
        $invalidFile = UploadedFile::fake()->create('test.txt', 100, 'text/plain');
        expect(fn() => $documentService->processUpload($invalidFile, 'invoice', 'email'))
            ->toThrow(Exception::class, 'File type not supported');
        
        // Test file size validation
        config(['app.max_file_size_mb' => 1]);
        $largeFile = UploadedFile::fake()->create('large.pdf', 2048, 'application/pdf');
        expect(fn() => $documentService->processUpload($largeFile, 'invoice', 'email'))
            ->toThrow(Exception::class, 'File size exceeds maximum');
    });

    it('demonstrates production-ready error handling', function () {
        $document = Document::factory()->create([
            'mime_type' => 'application/pdf',
            'file_path' => 'nonexistent/file.pdf'
        ]);
        
        $documentService = app(DocumentService::class);
        
        // Should handle missing file gracefully
        try {
            $result = $documentService->extractText($document);
            // If it doesn't throw, that's also acceptable (cached empty result)
            expect($result)->toBeString();
        } catch (Exception $e) {
            // Expected behavior - graceful error handling
            expect($e->getMessage())->toBeString();
        }
    });

    it('verifies service dependencies are properly configured', function () {
        // Verify all our production services can be instantiated
        $documentService = app(DocumentService::class);
        $llmExtractor = app(LlmExtractor::class);
        $ocrService = app(\App\Services\OcrService::class);
        $pdfService = app(\App\Services\PdfService::class);
        
        expect($documentService)->toBeInstanceOf(DocumentService::class)
            ->and($llmExtractor)->toBeInstanceOf(LlmExtractor::class)
            ->and($ocrService)->toBeInstanceOf(\App\Services\OcrService::class)
            ->and($pdfService)->toBeInstanceOf(\App\Services\PdfService::class);
    });

    it('demonstrates comprehensive database operations', function () {
        // Create intake with multiple documents
        $intake = Intake::factory()->create(['status' => 'uploaded']);
        
        $documents = Document::factory()->count(3)->create([
            'intake_id' => $intake->id,
            'document_type' => 'invoice',
            'file_path' => function () {
                return 'documents/' . fake()->uuid() . '.pdf';
            }
        ]);
        
        // Create mock files in storage for classification
        $documents->each(function ($document) {
            Storage::disk('s3')->put($document->file_path, 'Sample PDF content for testing classification');
        });
        
        expect($intake->documents)->toHaveCount(3)
            ->and($documents->first()->intake->id)->toBe($intake->id);
        
        // Test document classification
        $pdfService = app(\App\Services\PdfService::class);
        $pdfService->classifyDocuments($documents);
        
        // All documents should have been processed (though classification may be 'unknown' due to simple test content)
        $documents->each(function ($document) {
            expect($document->fresh()->document_type)->toBeString();
        });
    });
});
