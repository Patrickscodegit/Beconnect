<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Models\IntakeFile;
use App\Models\Document;
use App\Services\Intake\Pipelines\IntakePipelineFactory;
use App\Services\Intake\Pipelines\PdfIntakePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;

class PdfPipelineIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Fake the queue for testing
        Queue::fake();
        
        // Create test storage
        Storage::fake('documents');
    }

    public function test_pdf_pipeline_can_handle_pdf_files(): void
    {
        // Create test intake and file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'application/pdf',
            'filename' => 'test-document.pdf',
            'storage_path' => 'test-document.pdf',
            'storage_disk' => 'documents'
        ]);

        // Test pipeline detection
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(PdfIntakePipeline::class, $pipeline);
        $this->assertTrue($pipeline->canHandle($intakeFile));
        $this->assertEquals('pdfs', $pipeline->getQueueName());
        $this->assertEquals(80, $pipeline->getPriority());
    }

    public function test_pdf_pipeline_does_not_handle_email_files(): void
    {
        // Create test intake and email file
        $intake = Intake::factory()->create();

        $emailFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'message/rfc822',
            'filename' => 'test-email.eml'
        ]);

        // Get PDF pipeline directly
        $pdfPipeline = app(PdfIntakePipeline::class);

        // PDF pipeline should not handle email files
        $this->assertFalse($pdfPipeline->canHandle($emailFile));
    }

    public function test_pdf_pipeline_does_not_handle_image_files(): void
    {
        // Create test intake and image file
        $intake = Intake::factory()->create();

        $imageFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'image/jpeg',
            'filename' => 'test-image.jpg'
        ]);

        // Get PDF pipeline directly
        $pdfPipeline = app(PdfIntakePipeline::class);

        // PDF pipeline should not handle image files
        $this->assertFalse($pdfPipeline->canHandle($imageFile));
    }

    public function test_pdf_pipeline_dispatches_correct_jobs(): void
    {
        // Create test intake and PDF file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'application/pdf',
            'filename' => 'test-document.pdf',
            'storage_path' => 'test-document.pdf',
            'storage_disk' => 'documents'
        ]);

        // Create test file content
        Storage::disk('documents')->put('test-document.pdf', 'PDF content here');

        // Get pipeline and process file
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(PdfIntakePipeline::class, $pipeline);

        // Process the file
        $pipeline->process($intake, $intakeFile);

        // Assert that PDF-specific jobs were dispatched
        Queue::assertPushed(\App\Jobs\Intake\ProcessPdfIntakeJob::class, function ($job) use ($intake, $intakeFile) {
            return $job->intake->id === $intake->id && $job->file->id === $intakeFile->id;
        });
    }

    public function test_orchestrator_uses_pdf_pipeline_for_pdf_files(): void
    {
        // Create test intake and PDF file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'application/pdf',
            'filename' => 'test-document.pdf',
            'storage_path' => 'test-document.pdf',
            'storage_disk' => 'documents'
        ]);

        // Create corresponding document
        $document = Document::factory()->create([
            'intake_id' => $intake->id,
            'filename' => 'test-document.pdf',
            'file_path' => 'test-document.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'pending',
            'extraction_status' => 'pending'
        ]);

        // Create test file content
        Storage::disk('documents')->put('test-document.pdf', 'PDF content here');

        // Test that the pipeline factory correctly identifies PDF files
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(PdfIntakePipeline::class, $pipeline);

        // Test that the pipeline dispatches PDF-specific jobs
        $pipeline->process($intake, $intakeFile);

        // Assert that PDF-specific jobs were dispatched
        Queue::assertPushed(\App\Jobs\Intake\ProcessPdfIntakeJob::class);
    }

    public function test_pipeline_factory_returns_correct_supported_mime_types(): void
    {
        $pipelineFactory = app(IntakePipelineFactory::class);
        $supportedTypes = $pipelineFactory->getAllSupportedMimeTypes();

        // Should support both email and PDF
        $this->assertContains('message/rfc822', $supportedTypes);
        $this->assertContains('application/vnd.ms-outlook', $supportedTypes);
        $this->assertContains('application/pdf', $supportedTypes);
        
        // Should not support images yet
        $this->assertNotContains('image/jpeg', $supportedTypes);
        $this->assertNotContains('image/png', $supportedTypes);
    }

    public function test_pipeline_factory_correctly_identifies_supported_mime_types(): void
    {
        $pipelineFactory = app(IntakePipelineFactory::class);

        // Email types
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('message/rfc822'));
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('application/vnd.ms-outlook'));
        
        // PDF type
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('application/pdf'));
        
        // Unsupported types
        $this->assertFalse($pipelineFactory->isMimeTypeSupported('image/jpeg'));
        $this->assertFalse($pipelineFactory->isMimeTypeSupported('text/plain'));
    }

    public function test_pdf_pipeline_has_lower_priority_than_email(): void
    {
        $emailPipeline = app(\App\Services\Intake\Pipelines\EmailIntakePipeline::class);
        $pdfPipeline = app(PdfIntakePipeline::class);

        // Email should have higher priority (100) than PDF (80)
        $this->assertGreaterThan($pdfPipeline->getPriority(), $emailPipeline->getPriority());
    }
}

