<?php

namespace App\Services\Intake\Pipelines;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Jobs\Intake\ProcessPdfIntakeJob;
use Illuminate\Support\Facades\Log;

class PdfIntakePipeline implements IntakePipelineInterface
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
        Log::info('PdfIntakePipeline: Processing PDF file', [
            'intake_id' => $intake->id,
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'queue' => $this->getQueueName()
        ]);

        // Dispatch PDF-specific job to dedicated queue
        ProcessPdfIntakeJob::dispatch($intake, $file)
            ->onQueue($this->getQueueName());
    }

    /**
     * Get the queue name for this pipeline
     */
    public function getQueueName(): string
    {
        return 'pdfs';
    }

    /**
     * Get the job class for this pipeline
     */
    public function getJobClass(): string
    {
        return ProcessPdfIntakeJob::class;
    }

    /**
     * Get supported MIME types for this pipeline
     */
    public function getSupportedMimeTypes(): array
    {
        return [
            'application/pdf',
        ];
    }

    /**
     * Get pipeline priority (higher = processed first)
     * PDF gets medium priority (lower than email, but still important)
     */
    public function getPriority(): int
    {
        return 80;
    }
}

