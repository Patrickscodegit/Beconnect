<?php

namespace App\Services\Intake\Pipelines;

use App\Models\Intake;
use App\Models\IntakeFile;

interface IntakePipelineInterface
{
    /**
     * Check if this pipeline can handle the given file
     */
    public function canHandle(IntakeFile $file): bool;

    /**
     * Process a single file through this pipeline
     */
    public function process(Intake $intake, IntakeFile $file): void;

    /**
     * Get the queue name for this pipeline
     */
    public function getQueueName(): string;

    /**
     * Get the job class for this pipeline
     */
    public function getJobClass(): string;

    /**
     * Get supported MIME types for this pipeline
     */
    public function getSupportedMimeTypes(): array;

    /**
     * Get pipeline priority (higher = processed first)
     */
    public function getPriority(): int;
}
