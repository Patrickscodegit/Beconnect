<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImageExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private AiRouter $aiRouter
    ) {}

    public function getName(): string
    {
        return 'image_vision';
    }

    public function getPriority(): int
    {
        return 80; // Priority between PDF (90) and other strategies
    }

    public function supports(Document $document): bool
    {
        // Support common image formats
        $supportedMimeTypes = [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/gif',
            'image/webp'
        ];

        return in_array($document->mime_type, $supportedMimeTypes) ||
               preg_match('/\.(jpe?g|png|gif|webp)$/i', $document->filename ?? '');
    }

    public function extract(Document $document): ExtractionResult
    {
        $startTime = microtime(true);
        
        Log::info('Starting image extraction', [
            'document_id' => $document->id,
            'filename' => $document->filename,
            'mime_type' => $document->mime_type,
            'strategy' => $this->getName()
        ]);

        try {
            // Get image content from storage
            $imageContent = Storage::disk($document->storage_disk)->get($document->file_path);
            
            if (!$imageContent) {
                throw new \RuntimeException('Could not read image file: ' . $document->file_path);
            }

            Log::info('Image file loaded successfully', [
                'document_id' => $document->id,
                'file_size' => strlen($imageContent),
                'mime_type' => $document->mime_type
            ]);

            // Use AiRouter to extract data from image using vision AI
            $extractionInput = [
                'bytes' => $imageContent,
                'mime' => $document->mime_type,
                'filename' => $document->filename
            ];

            $aiResult = $this->aiRouter->extractAdvanced($extractionInput, 'comprehensive');

            // Process the AI result
            if (empty($aiResult['data'])) {
                throw new \RuntimeException('No data extracted from image by AI vision');
            }

            // Add image-specific metadata
            $metadata = $aiResult['metadata'] ?? [];
            $metadata['extraction_strategy'] = $this->getName();
            $metadata['image_file_size'] = strlen($imageContent);
            $metadata['document_type'] = 'image';
            $metadata['filename'] = $document->filename;
            $metadata['source'] = 'ai_vision_extraction';
            $metadata['processing_time'] = microtime(true) - $startTime;
            $metadata['vision_model'] = config('ai.vision_model', 'gpt-4o');
            
            // Create enhanced result with image context
            $enhancedData = $aiResult['data'];
            $enhancedData['_extraction_context'] = [
                'source_type' => 'image_document',
                'vision_processed' => true,
                'strategy_used' => $this->getName(),
                'ai_confidence' => $aiResult['confidence'] ?? 0
            ];

            $confidence = $aiResult['confidence'] ?? 0;

            Log::info('Image extraction completed', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'vehicle_found' => !empty($enhancedData['vehicle']),
                'contact_found' => !empty($enhancedData['contact']),
                'shipment_found' => !empty($enhancedData['shipment']),
                'processing_time_ms' => round(($metadata['processing_time'] ?? 0) * 1000, 2)
            ]);

            return ExtractionResult::success(
                $enhancedData,
                $confidence,
                $this->getName(),
                $metadata
            );

        } catch (\Exception $e) {
            $processingTime = microtime(true) - $startTime;
            
            Log::error('Image extraction failed', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'error' => $e->getMessage(),
                'processing_time_ms' => round($processingTime * 1000, 2),
                'strategy' => $this->getName()
            ]);

            return ExtractionResult::failure(
                $e->getMessage(),
                $this->getName(),
                [
                    'extraction_strategy' => $this->getName(),
                    'document_type' => 'image',
                    'filename' => $document->filename,
                    'error_details' => $e->getMessage(),
                    'processing_time' => $processingTime,
                    'source' => 'ai_vision_extraction_failed'
                ]
            );
        }
    }

    /**
     * Check if the image file is accessible and readable
     */
    private function validateImageFile(Document $document): bool
    {
        try {
            return Storage::disk($document->storage_disk)->exists($document->file_path) &&
                   Storage::disk($document->storage_disk)->size($document->file_path) > 0;
        } catch (\Exception $e) {
            Log::warning('Image file validation failed', [
                'document_id' => $document->id,
                'file_path' => $document->file_path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get supported image formats for this strategy
     */
    public function getSupportedFormats(): array
    {
        return [
            'mime_types' => [
                'image/jpeg',
                'image/jpg',
                'image/png', 
                'image/gif',
                'image/webp'
            ],
            'extensions' => [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp'
            ]
        ];
    }

    /**
     * Estimate processing complexity for the image
     */
    private function estimateComplexity(string $imageContent): string
    {
        $fileSize = strlen($imageContent);
        
        if ($fileSize > 5 * 1024 * 1024) { // > 5MB
            return 'high';
        } elseif ($fileSize > 1 * 1024 * 1024) { // > 1MB
            return 'medium';
        } else {
            return 'low';
        }
    }
}
