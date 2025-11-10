<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Intake;
use App\Models\IntakeFile;
use App\Models\Document;
use App\Services\Intake\Pipelines\IntakePipelineFactory;
use App\Services\Intake\Pipelines\ImageIntakePipeline;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pipeline\PipelineTestHelper;

/** @group pipeline */
class ImagePipelineIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        PipelineTestHelper::prepare();
        parent::setUp();

        PipelineTestHelper::boot($this);
        
        // Fake the queue for testing
        Queue::fake();
        
        // Create test storage
        Storage::fake('documents');
    }

    public function test_image_pipeline_can_handle_image_files(): void
    {
        // Create test intake and file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'image/jpeg',
            'filename' => 'test-image.jpg',
            'storage_path' => 'test-image.jpg',
            'storage_disk' => 'documents'
        ]);

        // Test pipeline detection
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(ImageIntakePipeline::class, $pipeline);
        $this->assertTrue($pipeline->canHandle($intakeFile));
        $this->assertEquals('images', $pipeline->getQueueName());
        $this->assertEquals(60, $pipeline->getPriority());
    }

    public function test_image_pipeline_supports_multiple_image_formats(): void
    {
        $imagePipeline = app(ImageIntakePipeline::class);
        $supportedTypes = $imagePipeline->getSupportedMimeTypes();

        // Test various image formats
        $this->assertContains('image/jpeg', $supportedTypes);
        $this->assertContains('image/jpg', $supportedTypes);
        $this->assertContains('image/png', $supportedTypes);
        $this->assertContains('image/gif', $supportedTypes);
        $this->assertContains('image/webp', $supportedTypes);
        $this->assertContains('image/bmp', $supportedTypes);
        $this->assertContains('image/tiff', $supportedTypes);
    }

    public function test_image_pipeline_does_not_handle_email_files(): void
    {
        // Create test intake and email file
        $intake = Intake::factory()->create();

        $emailFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'message/rfc822',
            'filename' => 'test-email.eml'
        ]);

        // Get image pipeline directly
        $imagePipeline = app(ImageIntakePipeline::class);

        // Image pipeline should not handle email files
        $this->assertFalse($imagePipeline->canHandle($emailFile));
    }

    public function test_image_pipeline_does_not_handle_pdf_files(): void
    {
        // Create test intake and PDF file
        $intake = Intake::factory()->create();

        $pdfFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'application/pdf',
            'filename' => 'test-document.pdf'
        ]);

        // Get image pipeline directly
        $imagePipeline = app(ImageIntakePipeline::class);

        // Image pipeline should not handle PDF files
        $this->assertFalse($imagePipeline->canHandle($pdfFile));
    }

    public function test_image_pipeline_dispatches_correct_jobs(): void
    {
        // Create test intake and image file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'image/png',
            'filename' => 'test-image.png',
            'storage_path' => 'test-image.png',
            'storage_disk' => 'documents'
        ]);

        // Create test file content
        Storage::disk('documents')->put('test-image.png', 'Image content here');

        // Get pipeline and process file
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(ImageIntakePipeline::class, $pipeline);

        // Process the file
        $pipeline->process($intake, $intakeFile);

        // Assert that image-specific jobs were dispatched
        Queue::assertPushed(\App\Jobs\Intake\ProcessImageIntakeJob::class, function ($job) use ($intake, $intakeFile) {
            return $job->intake->id === $intake->id && $job->file->id === $intakeFile->id;
        });
    }

    public function test_orchestrator_uses_image_pipeline_for_image_files(): void
    {
        // Create test intake and image file
        $intake = Intake::factory()->create([
            'is_multi_document' => false,
            'total_documents' => 1
        ]);

        $intakeFile = IntakeFile::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'image/jpeg',
            'filename' => 'test-image.jpg',
            'storage_path' => 'test-image.jpg',
            'storage_disk' => 'documents'
        ]);

        // Create corresponding document
        $document = Document::factory()->create([
            'intake_id' => $intake->id,
            'filename' => 'test-image.jpg',
            'file_path' => 'test-image.jpg',
            'mime_type' => 'image/jpeg',
            'status' => 'pending',
            'extraction_status' => 'pending'
        ]);

        // Create test file content
        Storage::disk('documents')->put('test-image.jpg', 'Image content here');

        // Test that the pipeline factory correctly identifies image files
        $pipelineFactory = app(IntakePipelineFactory::class);
        $pipeline = $pipelineFactory->getPipelineForFile($intakeFile);

        $this->assertInstanceOf(ImageIntakePipeline::class, $pipeline);

        // Test that the pipeline dispatches image-specific jobs
        $pipeline->process($intake, $intakeFile);

        // Assert that image-specific jobs were dispatched
        Queue::assertPushed(\App\Jobs\Intake\ProcessImageIntakeJob::class);
    }

    public function test_pipeline_factory_returns_all_supported_mime_types(): void
    {
        $pipelineFactory = app(IntakePipelineFactory::class);
        $supportedTypes = $pipelineFactory->getAllSupportedMimeTypes();

        // Should support email types (Phase 1)
        $this->assertContains('message/rfc822', $supportedTypes);
        $this->assertContains('application/vnd.ms-outlook', $supportedTypes);
        
        // Should support PDF (Phase 2)
        $this->assertContains('application/pdf', $supportedTypes);
        
        // Should support images (Phase 3)
        $this->assertContains('image/jpeg', $supportedTypes);
        $this->assertContains('image/png', $supportedTypes);
        $this->assertContains('image/gif', $supportedTypes);
    }

    public function test_pipeline_factory_correctly_identifies_all_supported_types(): void
    {
        $pipelineFactory = app(IntakePipelineFactory::class);

        // Email types (Phase 1)
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('message/rfc822'));
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('application/vnd.ms-outlook'));
        
        // PDF type (Phase 2)
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('application/pdf'));
        
        // Image types (Phase 3)
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('image/jpeg'));
        $this->assertTrue($pipelineFactory->isMimeTypeSupported('image/png'));
        
        // Unsupported types
        $this->assertFalse($pipelineFactory->isMimeTypeSupported('text/plain'));
        $this->assertFalse($pipelineFactory->isMimeTypeSupported('application/zip'));
    }

    public function test_image_pipeline_has_correct_priority_order(): void
    {
        $emailPipeline = app(\App\Services\Intake\Pipelines\EmailIntakePipeline::class);
        $pdfPipeline = app(\App\Services\Intake\Pipelines\PdfIntakePipeline::class);
        $imagePipeline = app(ImageIntakePipeline::class);

        // Priority order should be: Email (100) > PDF (80) > Image (60)
        $this->assertEquals(100, $emailPipeline->getPriority());
        $this->assertEquals(80, $pdfPipeline->getPriority());
        $this->assertEquals(60, $imagePipeline->getPriority());
        
        $this->assertGreaterThan($imagePipeline->getPriority(), $pdfPipeline->getPriority());
        $this->assertGreaterThan($pdfPipeline->getPriority(), $emailPipeline->getPriority());
    }
}

