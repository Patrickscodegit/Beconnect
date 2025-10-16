<?php

namespace App\Services\Intake\Pipelines;

use App\Models\IntakeFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class IntakePipelineFactory
{
    /**
     * Available pipelines (will be expanded in future phases)
     */
    private array $pipelines = [
        EmailIntakePipeline::class,
        PdfIntakePipeline::class,
        // Future: ImageIntakePipeline::class,
    ];

    /**
     * Get the appropriate pipeline for a file
     */
    public function getPipelineForFile(IntakeFile $file): ?IntakePipelineInterface
    {
        $availablePipelines = $this->getAvailablePipelines();

        foreach ($availablePipelines as $pipeline) {
            if ($pipeline->canHandle($file)) {
                Log::debug('PipelineFactory: Found pipeline for file', [
                    'file_id' => $file->id,
                    'filename' => $file->filename,
                    'mime_type' => $file->mime_type,
                    'pipeline_class' => get_class($pipeline)
                ]);
                
                return $pipeline;
            }
        }

        Log::warning('PipelineFactory: No pipeline found for file', [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'available_pipelines' => $availablePipelines->map(fn($p) => get_class($p))->toArray()
        ]);

        return null;
    }

    /**
     * Get all available pipelines
     */
    private function getAvailablePipelines(): Collection
    {
        return collect($this->pipelines)
            ->map(fn(string $class) => app($class))
            ->sortByDesc(fn(IntakePipelineInterface $pipeline) => $pipeline->getPriority());
    }

    /**
     * Get pipeline by class name
     */
    public function getPipelineByClass(string $className): ?IntakePipelineInterface
    {
        if (in_array($className, $this->pipelines)) {
            return app($className);
        }

        return null;
    }

    /**
     * Get all supported MIME types across all pipelines
     */
    public function getAllSupportedMimeTypes(): array
    {
        $availablePipelines = $this->getAvailablePipelines();
        
        return $availablePipelines
            ->flatMap(fn(IntakePipelineInterface $pipeline) => $pipeline->getSupportedMimeTypes())
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Check if a MIME type is supported by any pipeline
     */
    public function isMimeTypeSupported(string $mimeType): bool
    {
        return in_array($mimeType, $this->getAllSupportedMimeTypes());
    }
}
