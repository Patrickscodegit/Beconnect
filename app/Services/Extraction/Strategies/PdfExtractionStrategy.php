<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class PdfExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private HybridExtractionPipeline $hybridPipeline
    ) {}

    public function getName(): string
    {
        return 'pdf_hybrid';
    }

    public function getPriority(): int
    {
        return 90; // High priority for PDF documents, just below email
    }

    public function supports(Document $document): bool
    {
        // Support PDF files and PDF mime type
        return $document->mime_type === 'application/pdf' || 
               str_ends_with(strtolower($document->filename), '.pdf');
    }

    public function extract(Document $document): ExtractionResult
    {
        try {
            Log::info('Starting PDF extraction', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'strategy' => $this->getName()
            ]);

            // Read PDF content from storage
            $pdfContent = Storage::get($document->file_path);
            
            if (!$pdfContent) {
                throw new \Exception('Could not read PDF file from storage');
            }

            // Extract text from PDF
            $extractedText = $this->extractTextFromPdf($pdfContent);
            
            if (empty($extractedText)) {
                throw new \Exception('No text could be extracted from PDF');
            }

            Log::info('PDF text extracted successfully', [
                'document_id' => $document->id,
                'text_length' => strlen($extractedText),
                'preview' => substr($extractedText, 0, 200) . '...'
            ]);

            // Use hybrid pipeline to extract structured data from text
            $extractionResult = $this->hybridPipeline->extract($extractedText, [
                'document_type' => 'pdf',
                'filename' => $document->filename,
                'source' => 'pdf_extraction'
            ]);

            // Add PDF-specific metadata
            $metadata = $extractionResult->getMetadata();
            $metadata['extraction_strategy'] = $this->getName();
            $metadata['pdf_text_length'] = strlen($extractedText);
            $metadata['processing_time'] = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            
            // Create enhanced result with PDF context
            $enhancedData = $extractionResult->getData();
            $enhancedData['_extraction_context'] = [
                'source_type' => 'pdf_document',
                'text_extracted' => true,
                'strategy_used' => $this->getName()
            ];

            Log::info('PDF extraction completed', [
                'document_id' => $document->id,
                'confidence' => $extractionResult->getConfidence(),
                'vehicle_found' => !empty($enhancedData['vehicle']),
                'contact_found' => !empty($enhancedData['contact']),
                'shipment_found' => !empty($enhancedData['shipment'])
            ]);

            return ExtractionResult::success(
                $enhancedData,
                $extractionResult->getConfidence(),
                $this->getName(),
                $metadata
            );

        } catch (\Exception $e) {
            Log::error('PDF extraction failed', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'error' => $e->getMessage(),
                'strategy' => $this->getName()
            ]);

            return ExtractionResult::failure(
                $this->getName(),
                'PDF extraction failed: ' . $e->getMessage(),
                [
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                    'error_type' => get_class($e)
                ]
            );
        }
    }

    /**
     * Extract text from PDF content
     */
    private function extractTextFromPdf(string $pdfContent): string
    {
        try {
            // Use smalot/pdfparser library
            $parser = new Parser();
            $pdf = $parser->parseContent($pdfContent);
            
            $text = $pdf->getText();
            
            // Clean up extracted text
            $text = $this->cleanExtractedText($text);
            
            return $text;
            
        } catch (\Exception $e) {
            Log::warning('PDF text extraction failed with parser', [
                'error' => $e->getMessage(),
                'fallback' => 'trying alternative method'
            ]);
            
            // Fallback: try basic text extraction if parser fails
            return $this->fallbackTextExtraction($pdfContent);
        }
    }

    /**
     * Clean up extracted text from PDF
     */
    private function cleanExtractedText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove null bytes and control characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Remove excessive newlines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        return trim($text);
    }

    /**
     * Fallback text extraction method
     */
    private function fallbackTextExtraction(string $pdfContent): string
    {
        // This is a very basic fallback - in production you might want
        // to use additional PDF parsing libraries or tools
        
        // Try to find readable text patterns in the PDF
        $text = '';
        
        // Extract text between common PDF text markers
        if (preg_match_all('/\((.*?)\)/', $pdfContent, $matches)) {
            $text = implode(' ', $matches[1]);
        }
        
        // If that doesn't work, try stream content
        if (empty($text) && preg_match_all('/stream\s*(.*?)\s*endstream/s', $pdfContent, $matches)) {
            foreach ($matches[1] as $stream) {
                // Basic text extraction from stream
                $streamText = preg_replace('/[^\x20-\x7E\s]/', '', $stream);
                if (strlen(trim($streamText)) > 10) {
                    $text .= ' ' . $streamText;
                }
            }
        }
        
        return $this->cleanExtractedText($text);
    }

    /**
     * Check if PDF parsing library is available
     */
    public static function isAvailable(): bool
    {
        return class_exists('\Smalot\PdfParser\Parser');
    }

    /**
     * Get information about this strategy
     */
    public function getInfo(): array
    {
        return [
            'name' => $this->getName(),
            'priority' => $this->getPriority(),
            'supported_types' => ['application/pdf'],
            'supported_extensions' => ['.pdf'],
            'requires_library' => 'smalot/pdfparser',
            'library_available' => self::isAvailable(),
            'description' => 'Extracts text from PDF documents and processes with hybrid AI pipeline'
        ];
    }
}
