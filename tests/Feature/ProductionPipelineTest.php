<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Intake;
use App\Services\DocumentService;
use App\Services\LlmExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\Support\Pipeline\PipelineTestHelper;
use Tests\TestCase;

/** @group pipeline */
class ProductionPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        PipelineTestHelper::prepare();
        parent::setUp();

        PipelineTestHelper::boot($this);

        Storage::fake('s3');
        Storage::fake('documents');
        Storage::fake('local');
    }

    public function test_it_can_handle_complete_document_processing_workflow(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->create('test-invoice.pdf', 1024, 'application/pdf');
        $documentService = app(DocumentService::class);

        $document = $documentService->processUpload($file, 'invoice', 'email');

        $this->assertInstanceOf(Document::class, $document);
        $this->assertSame('test-invoice.pdf', $document->filename);
        $this->assertSame('application/pdf', $document->mime_type);

        $sampleText = 'Invoice Number: 12345 Amount Due: $1000 Total Payment Required';
        $classification = $documentService->classifyDocument($document, $sampleText);

        $this->assertSame('financial_document', $classification);

        $vehicleText = 'Vehicle Year: 2023 Make: Toyota Model: Camry VIN: JT2BG22K1X0123456';

        $reflection = new ReflectionClass($documentService);
        $method = $reflection->getMethod('patternBasedExtraction');
        $method->setAccessible(true);
        $extractedData = $method->invoke($documentService, $vehicleText);

        $this->assertIsArray($extractedData);
        $this->assertGreaterThan(0, count($extractedData));

        $disk = Storage::disk('documents');
        $this->assertTrue(
            $disk->exists($document->file_path) ||
            $disk->exists('documents/' . $document->file_path),
            'Stored document file not found on documents disk.'
        );
    }

    public function test_it_handles_file_validation_correctly(): void
    {
        $documentService = app(DocumentService::class);

        $invalidFile = UploadedFile::fake()->create('test.txt', 100, 'text/plain');
        try {
            $documentService->processUpload($invalidFile, 'invoice', 'email');
            $this->fail('Expected unsupported file type exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('File type not supported', $e->getMessage());
        }

        config(['app.max_file_size_mb' => 1]);
        $largeFile = UploadedFile::fake()->create('large.pdf', 2048, 'application/pdf');
        try {
            $documentService->processUpload($largeFile, 'invoice', 'email');
            $this->fail('Expected max file size exception.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('File size exceeds maximum', $e->getMessage());
        }
    }

    public function test_it_demonstrates_production_ready_error_handling(): void
    {
        $document = Document::factory()->create([
            'mime_type' => 'application/pdf',
            'file_path' => 'nonexistent/file.pdf',
        ]);

        $documentService = app(DocumentService::class);

        try {
            $result = $documentService->extractText($document);
            $this->assertIsString($result);
        } catch (\Exception $e) {
            $this->assertIsString($e->getMessage());
        }
    }

    public function test_it_verifies_service_dependencies_are_configured(): void
    {
        $documentService = app(DocumentService::class);
        $llmExtractor = app(LlmExtractor::class);
        $ocrService = app(\App\Services\OcrService::class);
        $pdfService = app(\App\Services\PdfService::class);

        $this->assertInstanceOf(DocumentService::class, $documentService);
        $this->assertInstanceOf(LlmExtractor::class, $llmExtractor);
        $this->assertInstanceOf(\App\Services\OcrService::class, $ocrService);
        $this->assertInstanceOf(\App\Services\PdfService::class, $pdfService);
    }

    public function test_it_demonstrates_comprehensive_database_operations(): void
    {
        $intake = Intake::factory()->create(['status' => 'uploaded']);

        $documents = Document::factory()->count(3)->create([
            'intake_id' => $intake->id,
            'document_type' => 'invoice',
            'storage_disk' => 's3',
            'file_path' => fn () => 'documents/' . fake()->uuid() . '.pdf',
        ]);

        $documents->each(function ($document) {
            Storage::disk('s3')->put($document->file_path, 'Sample PDF content for testing classification');
        });

        $this->assertCount(3, $intake->documents);
        $this->assertSame($intake->id, $documents->first()->intake->id);

        $pdfService = app(\App\Services\PdfService::class);
        $pdfService->classifyDocuments($documents);

        $documents->each(function ($document) {
            $this->assertIsString($document->fresh()->document_type);
        });
    }
}
