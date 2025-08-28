<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EmailExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private HybridExtractionPipeline $hybridPipeline
    ) {}

    public function getName(): string
    {
        return 'email_hybrid';
    }

    public function getPriority(): int
    {
        return 100; // High priority for email documents
    }

    public function supports(Document $document): bool
    {
        // Support .eml files and RFC822 mime type
        return $document->mime_type === 'message/rfc822' || 
               str_ends_with(strtolower($document->filename), '.eml');
    }

    public function extract(Document $document): ExtractionResult
    {
        try {
            Log::info('Starting email extraction', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'strategy' => $this->getName()
            ]);

            // Get email content
            $content = $this->getEmailContent($document);

            if (empty($content)) {
                return ExtractionResult::failure(
                    $this->getName(), 
                    'Could not read email content',
                    ['document_id' => $document->id]
                );
            }

            // Use hybrid pipeline for extraction
            $extractionData = $this->hybridPipeline->extract($content, 'email');

            // Add email-specific metadata
            $emailMetadata = $this->extractEmailMetadata($content);
            $extractionData['metadata']['email_info'] = $emailMetadata;

            Log::info('Email extraction completed', [
                'document_id' => $document->id,
                'confidence' => $extractionData['metadata']['overall_confidence'],
                'strategies_used' => $extractionData['metadata']['extraction_strategies']
            ]);

            return ExtractionResult::success(
                $extractionData['data'],
                $extractionData['metadata']['overall_confidence'],
                $this->getName(),
                $extractionData['metadata']
            );

        } catch (\Exception $e) {
            Log::error('Email extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'strategy' => $this->getName()
            ]);

            return ExtractionResult::failure(
                $this->getName(),
                $e->getMessage(),
                ['document_id' => $document->id, 'error_type' => get_class($e)]
            );
        }
    }

    /**
     * Get email content from document
     */
    private function getEmailContent(Document $document): string
    {
        try {
            // Try to read from file path first
            if (file_exists($document->file_path)) {
                return file_get_contents($document->file_path);
            }

            // Try storage disk
            $disk = $document->disk ?: config('filesystems.default');
            return Storage::disk($disk)->get($document->file_path);

        } catch (\Exception $e) {
            Log::error('Could not read email file', [
                'document_id' => $document->id,
                'file_path' => $document->file_path,
                'disk' => $document->disk,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException("Could not read email file: " . $e->getMessage());
        }
    }

    /**
     * Extract basic email metadata
     */
    private function extractEmailMetadata(string $content): array
    {
        $metadata = [
            'content_length' => strlen($content),
            'has_attachments' => false,
            'message_id' => null,
            'from' => null,
            'to' => null,
            'subject' => null,
            'date' => null
        ];

        // Extract email headers
        $lines = explode("\n", $content);
        $inHeaders = true;

        foreach ($lines as $line) {
            if ($inHeaders && trim($line) === '') {
                $inHeaders = false;
                continue;
            }

            if (!$inHeaders) break;

            // Parse headers
            if (preg_match('/^From:\s*(.+)$/i', $line, $matches)) {
                $metadata['from'] = trim($matches[1]);
            } elseif (preg_match('/^To:\s*(.+)$/i', $line, $matches)) {
                $metadata['to'] = trim($matches[1]);
            } elseif (preg_match('/^Subject:\s*(.+)$/i', $line, $matches)) {
                $metadata['subject'] = trim($matches[1]);
            } elseif (preg_match('/^Date:\s*(.+)$/i', $line, $matches)) {
                $metadata['date'] = trim($matches[1]);
            } elseif (preg_match('/^Message-ID:\s*(.+)$/i', $line, $matches)) {
                $metadata['message_id'] = trim($matches[1]);
            }
        }

        // Check for attachments
        $metadata['has_attachments'] = str_contains($content, 'Content-Disposition: attachment');

        return $metadata;
    }
}
