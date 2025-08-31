<?php

use App\Services\DocumentService;
use App\Services\LlmExtractor;
use App\Services\OcrService;
use App\Services\PdfService;
use App\Models\Document;
use App\Models\Intake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Use fakes for all storage disks that might be used
        Storage::fake('s3');
        Storage::fake('documents'); 
        Storage::fake('local');  // DocumentService uses config('filesystems.default')
    }

    /** @test */
    public function it_can_process_a_pdf_upload_with_full_pipeline(): void
    {
        // Create a mock PDF file
        $file = UploadedFile::fake()->create('test-invoice.pdf', 1024, 'application/pdf');
        
        $documentService = app(DocumentService::class);
        
        // Test file upload
        $document = $documentService->processUpload($file, 'invoice', 'email');
        
        $this->assertInstanceOf(Document::class, $document);
        $this->assertEquals('test-invoice.pdf', $document->filename);
        $this->assertEquals('application/pdf', $document->mime_type);
        $this->assertEquals('invoice', $document->document_type);
        
        // Verify file was stored on the default disk (local)
        Storage::disk('local')->assertExists($document->file_path);
    }

    /** @test */
    public function it_validates_file_size_limits(): void
    {
        // Create a file larger than the limit
        config(['app.max_file_size_mb' => 1]); // Set limit to 1MB
        $file = UploadedFile::fake()->create('large-file.pdf', 2048, 'application/pdf'); // 2MB file
        
        $documentService = app(DocumentService::class);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/File size exceeds maximum allowed size/');
        
        $documentService->processUpload($file, 'invoice', 'email');
    }

    /** @test */
    public function it_validates_file_types(): void
    {
        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');
        
        $documentService = app(DocumentService::class);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/File type not supported/');
        
        $documentService->processUpload($file, 'invoice', 'email');
    }

    /** @test */
    public function it_has_proper_service_configuration(): void
    {
        // Test that our service configurations are properly set
        $allowedModels = ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo-preview'];
        $this->assertContains(config('services.openai.model'), $allowedModels);
        
        $this->assertStringContainsString('tesseract', config('services.tesseract.path'));
        $this->assertEquals(300, (int) config('services.pdf.dpi'));
        $this->assertEquals(50, (int) config('app.max_file_size_mb'));
    }

    /** @test */
    public function it_handles_missing_external_tools_gracefully(): void
    {
        // Test OCR service handles missing tesseract
        config(['services.tesseract.path' => '/nonexistent/path']);
        
        $ocrService = app(OcrService::class);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Tesseract OCR not found/');
        
        $ocrService->extractFromImage('/tmp/test.jpg');
    }
}
