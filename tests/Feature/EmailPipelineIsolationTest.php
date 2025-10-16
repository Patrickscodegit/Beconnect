<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Models\IntakeFile;
use App\Models\Document;
use App\Services\Intake\Pipelines\IntakePipelineFactory;
use App\Services\Intake\Pipelines\EmailIntakePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;

class EmailPipelineIsolationTest extends TestCase
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

    /** @test */
    public function email_pipeline_can_handle_eml_files()
    {
        // Create test intake and file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'message/rfc822',
            'filename' => 'test-email.eml',
            'storage_path' => 'test-email.eml',
            'storage_disk' => 'documents'
        ]);

        // Test pipeline detection
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(EmailIntakePipeline::class, $pipeline);
        $this->assertTrue($pipeline->canHandle($intakeFile));
        $this->assertEquals('emails', $pipeline->getQueueName());
        $this->assertEquals(100, $pipeline->getPriority());
    }

    /** @test */
    public function email_pipeline_does_not_handle_pdf_files()
    {
        // Create test intake and PDF file
        $intake = Intake::factory()->create();

        $pdfFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'application/pdf',
            'filename' => 'test-document.pdf'
        ]);

        // Get email pipeline directly
        $emailPipeline = app(\App\Services\Intake\Pipelines\EmailIntakePipeline::class);

        // Email pipeline should not handle PDF files
        $this->assertFalse($emailPipeline->canHandle($pdfFile));
    }

    /** @test */
    public function email_pipeline_does_not_handle_image_files()
    {
        // Create test intake and image file
        $intake = Intake::factory()->create();

        $imageFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'image/jpeg',
            'filename' => 'test-image.jpg'
        ]);

        // Test pipeline detection
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($imageFile);

        // Should return null for non-email files
        $this->assertNull($pipeline);
    }

    /** @test */
    public function email_pipeline_dispatches_correct_jobs()
    {
        // Create test intake and email file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'message/rfc822',
            'filename' => 'test-email.eml',
            'storage_path' => 'test-email.eml',
            'storage_disk' => 'documents'
        ]);

        // Create test file content
        Storage::disk('documents')->put('test-email.eml', 'From: test@example.com\nTo: recipient@example.com\nSubject: Test Email\n\nThis is a test email.');

        // Get pipeline and process file
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(EmailIntakePipeline::class, $pipeline);

        // Process the file
        $pipeline->process($intake, $intakeFile);

        // Assert that email-specific jobs were dispatched
        Queue::assertPushed(\App\Jobs\Intake\ProcessEmailIntakeJob::class, function ($job) use ($intake, $intakeFile) {
            return $job->intake->id === $intake->id && $job->file->id === $intakeFile->id;
        });
    }

    /** @test */
    public function orchestrator_uses_email_pipeline_for_eml_files()
    {
        // Create test intake and email file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'message/rfc822',
            'filename' => 'test-email.eml',
            'storage_path' => 'test-email.eml',
            'storage_disk' => 'documents'
        ]);

        // Create corresponding document
        $document = Document::factory()->create([
            'intake_id' => $intake->id,
            'filename' => 'test-email.eml',
            'file_path' => 'test-email.eml',
            'mime_type' => 'message/rfc822',
            'status' => 'pending',
            'extraction_status' => 'pending'
        ]);

        // Create test file content
        Storage::disk('documents')->put('test-email.eml', 'From: test@example.com\nTo: recipient@example.com\nSubject: Test Email\n\nThis is a test email.');

        // Test that the pipeline factory correctly identifies email files
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(EmailIntakePipeline::class, $pipeline);

        // Test that the pipeline dispatches email-specific jobs
        $pipeline->process($intake, $intakeFile);

        // Assert that email-specific jobs were dispatched
        Queue::assertPushed(\App\Jobs\Intake\ProcessEmailIntakeJob::class);
    }

    /** @test */
    public function orchestrator_routes_pdfs_to_pdf_pipeline_not_email()
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

        // Test that the pipeline factory routes PDF to PDF pipeline, not email
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        // Should return PdfIntakePipeline (not null, not EmailIntakePipeline)
        $this->assertInstanceOf(\App\Services\Intake\Pipelines\PdfIntakePipeline::class, $pipeline);
        $this->assertNotInstanceOf(\App\Services\Intake\Pipelines\EmailIntakePipeline::class, $pipeline);
    }

    /** @test */
    public function pipeline_factory_returns_correct_supported_mime_types()
    {
        $pipelineFactory = app(IntakePipelineFactory::class);
        $supportedTypes = $pipelineFactory->getAllSupportedMimeTypes();

        // Should support email types
        $this->assertContains('message/rfc822', $supportedTypes);
        $this->assertContains('application/vnd.ms-outlook', $supportedTypes);
        
        // Should also support PDF (Phase 2 complete)
        $this->assertContains('application/pdf', $supportedTypes);
        
        // Should not support images yet (Phase 3 pending)
        $this->assertNotContains('image/jpeg', $supportedTypes);
    }

    /** @test */
    public function pipeline_factory_correctly_identifies_supported_mime_types()
    {
        $pipelineFactory = app(IntakePipelineFactory::class);

        // Email types (Phase 1)
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('message/rfc822'));
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('application/vnd.ms-outlook'));
        
        // PDF type (Phase 2)
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('application/pdf'));
        
        // Unsupported types
        $this->assertFalse($pipelineFactory->isMimeTypeSupported('image/jpeg'));
        $this->assertFalse($pipelineFactory->isMimeTypeSupported('text/plain'));
    }
}
