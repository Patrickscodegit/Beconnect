<?php

namespace App\Services\Intake\Pipelines;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Jobs\Intake\ProcessEmailIntakeJob;
use Illuminate\Support\Facades\Log;

class EmailIntakePipeline implements IntakePipelineInterface
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
        Log::info('EmailIntakePipeline: Processing email file', [
            'intake_id' => $intake->id,
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'queue' => $this->getQueueName()
        ]);

        // Dispatch email-specific job to dedicated queue
        ProcessEmailIntakeJob::dispatch($intake, $file)
            ->onQueue($this->getQueueName());
    }

    /**
     * Get the queue name for this pipeline
     */
    public function getQueueName(): string
    {
        return 'emails';
    }

    /**
     * Get the job class for this pipeline
     */
    public function getJobClass(): string
    {
        return ProcessEmailIntakeJob::class;
    }

    /**
     * Get supported MIME types for this pipeline
     */
    public function getSupportedMimeTypes(): array
    {
        return [
            'message/rfc822',  // .eml files
            'application/vnd.ms-outlook', // .msg files
        ];
    }

    /**
     * Get pipeline priority (higher = processed first)
     * Email gets high priority as it often contains the most important routing info
     */
    public function getPriority(): int
    {
        return 100;
    }
}
