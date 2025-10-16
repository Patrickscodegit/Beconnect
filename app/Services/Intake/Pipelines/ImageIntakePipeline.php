<?php

namespace App\Services\Intake\Pipelines;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Jobs\Intake\ProcessImageIntakeJob;
use Illuminate\Support\Facades\Log;

class ImageIntakePipeline implements IntakePipelineInterface
{
    /**
     * Check if this pipeline can handle the given file
     */
    public function canHandle(IntakeFile $file): bool
    {
        return in_array($file->mime_type, $this->getSupportedMimeTypes());
    }

    /**
     * Process a single file through this pipeline
     */
    public function process(Intake $intake, IntakeFile $file): void
    {
        Log::info('ImageIntakePipeline: Processing image file', [
            'intake_id' => $intake->id,
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'queue' => $this->getQueueName()
        ]);

        // Dispatch image-specific job to dedicated queue
        ProcessImageIntakeJob::dispatch($intake, $file)
            ->onQueue($this->getQueueName());
    }

    /**
     * Get the queue name for this pipeline
     */
    public function getQueueName(): string
    {
        return 'images';
    }

    /**
     * Get the job class for this pipeline
     */
    public function getJobClass(): string
    {
        return ProcessImageIntakeJob::class;
    }

    /**
     * Get supported MIME types for this pipeline
     */
    public function getSupportedMimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/tiff',
        ];
    }

    /**
     * Get pipeline priority (higher = processed first)
     * Images get lower priority (processed after emails and PDFs)
     */
    public function getPriority(): int
    {
        return 60;
    }
}

