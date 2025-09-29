<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\AiRouter;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * ENHANCED PDF EXTRACTION STRATEGY
 * 
 * This strategy is isolated from email processing and can be enhanced
 * without affecting the email pipeline. It uses its own dedicated
 * processing methods and won't interfere with other strategies.
 */
class EnhancedPdfExtractionStrategy implements ExtractionStrategy
{
    public function __construct(
        private AiRouter $aiRouter
    ) {}

    public function getName(): string
    {
        return 'enhanced_pdf_extraction';
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
            Log::info('Starting ENHANCED PDF extraction', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'strategy' => $this->getName()
            ]);

            // Read PDF content from storage using the correct disk
            Log::info('Accessing PDF file', [
                'document_id' => $document->id,
                'storage_disk' => $document->storage_disk,
                'file_path' => $document->file_path
            ]);
            
            // Check if file exists before attempting to read
            if (!Storage::disk($document->storage_disk)->exists($document->file_path)) {
                throw new \Exception('PDF file not found: ' . $document->file_path);
            }
            
            $pdfContent = Storage::disk($document->storage_disk)->get($document->file_path);
            
            if (!$pdfContent) {
                throw new \Exception('Could not read PDF file from storage');
            }

            // Extract text from PDF using enhanced methods
            $extractedText = $this->extractTextFromPdf($pdfContent);
            
            if (empty($extractedText)) {
                throw new \Exception('No text could be extracted from PDF');
            }

            Log::info('PDF text extracted successfully (ENHANCED)', [
                'document_id' => $document->id,
                'text_length' => strlen($extractedText),
                'preview' => substr($extractedText, 0, 200) . '...'
            ]);

            // Use dedicated PDF extraction pipeline (NOT shared HybridExtractionPipeline)
            $extractedData = $this->extractPdfData($extractedText, $document);

            // Add PDF-specific metadata
            $metadata = [
                'extraction_strategy' => $this->getName(),
                'pdf_text_length' => strlen($extractedText),
                'document_type' => 'pdf',
                'filename' => $document->filename,
                'source' => 'enhanced_pdf_extraction',
                'processing_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
                'enhancement_level' => 'advanced' // This strategy uses enhanced methods
            ];
            
            // Create enhanced result with PDF context
            $extractedData['_extraction_context'] = [
                'source_type' => 'pdf_document',
                'text_extracted' => true,
                'strategy_used' => $this->getName(),
                'enhancement_features' => [
                    'advanced_text_extraction',
                    'intelligent_field_detection',
                    'context_aware_parsing'
                ]
            ];

            $confidence = $this->calculateConfidence($extractedData, $extractedText);

            Log::info('ENHANCED PDF extraction completed', [
                'document_id' => $document->id,
                'confidence' => $confidence,
                'vehicle_found' => !empty($extractedData['vehicle']),
                'contact_found' => !empty($extractedData['contact']),
                'shipment_found' => !empty($extractedData['shipment']),
                'enhancement_status' => 'active'
            ]);

            return ExtractionResult::success(
                $extractedData,
                $confidence,
                $this->getName(),
                $metadata
            );

        } catch (\Exception $e) {
            Log::error('ENHANCED PDF extraction failed', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'error' => $e->getMessage(),
                'strategy' => $this->getName()
            ]);

            return ExtractionResult::failure(
                $this->getName(),
                'Enhanced PDF extraction failed: ' . $e->getMessage(),
                [
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                    'error_type' => get_class($e)
                ]
            );
        }
    }

    /**
     * DEDICATED PDF DATA EXTRACTION
     * This method is completely isolated and can be enhanced without affecting email processing
     */
    private function extractPdfData(string $text, Document $document): array
    {
        $extractedData = [
            'contact' => [],
            'vehicle' => [],
            'shipment' => [],
            'pricing' => [],
            'dates' => [],
            'cargo' => []
        ];

        // Enhanced pattern-based extraction for PDFs
        $this->extractContactInfo($text, $extractedData);
        $this->extractVehicleInfo($text, $extractedData);
        $this->extractShipmentInfo($text, $extractedData);
        $this->extractPricingInfo($text, $extractedData);
        $this->extractDateInfo($text, $extractedData);

        // Use AI for complex extraction (isolated call)
        if (config('extraction.use_ai_extraction', true)) {
            $aiResult = $this->aiRouter->extract($text, [], [
                'cheap' => false, // Use heavy model for PDF processing
                'reasoning' => true,
                'enhancement_mode' => true, // Flag for enhanced processing
                'document_type' => 'pdf'
            ]);

            if (!empty($aiResult)) {
                // Merge AI results with pattern-based results
                $extractedData = $this->mergeExtractionResults($extractedData, $aiResult);
            }
        }

        return $extractedData;
    }

    /**
     * Enhanced contact information extraction
     */
    private function extractContactInfo(string $text, array &$extractedData): void
    {
        // Email patterns
        if (preg_match_all('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $text, $matches)) {
            $extractedData['contact']['email'] = $matches[0][0];
        }

        // Phone patterns
        if (preg_match_all('/\b(?:\+?1[-.\s]?)?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}\b/', $text, $matches)) {
            $extractedData['contact']['phone'] = $matches[0][0];
        }

        // Name patterns (enhanced)
        if (preg_match('/\b(?:Mr\.?|Ms\.?|Mrs\.?|Dr\.?)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $text, $matches)) {
            $extractedData['contact']['name'] = $matches[1];
        }
    }

    /**
     * Enhanced vehicle information extraction
     */
    private function extractVehicleInfo(string $text, array &$extractedData): void
    {
        // VIN patterns
        if (preg_match('/\b[A-HJ-NPR-Z0-9]{17}\b/', $text, $matches)) {
            $extractedData['vehicle']['vin'] = $matches[0];
        }

        // Make/Model patterns (enhanced)
        $makes = ['BMW', 'Mercedes', 'Audi', 'Volkswagen', 'Toyota', 'Honda', 'Ford', 'Chevrolet'];
        foreach ($makes as $make) {
            if (stripos($text, $make) !== false) {
                $extractedData['vehicle']['make'] = $make;
                break;
            }
        }

        // Year patterns
        if (preg_match('/\b(19|20)\d{2}\b/', $text, $matches)) {
            $extractedData['vehicle']['year'] = $matches[0];
        }
    }

    /**
     * Enhanced shipment information extraction
     */
    private function extractShipmentInfo(string $text, array &$extractedData): void
    {
        // Port patterns
        $ports = ['Antwerp', 'Rotterdam', 'Hamburg', 'Mombasa', 'Dar es Salaam', 'Lagos', 'Durban'];
        foreach ($ports as $port) {
            if (stripos($text, $port) !== false) {
                if (empty($extractedData['shipment']['origin'])) {
                    $extractedData['shipment']['origin'] = $port;
                } elseif (empty($extractedData['shipment']['destination'])) {
                    $extractedData['shipment']['destination'] = $port;
                }
            }
        }

        // Container types
        $containers = ['20ft', '40ft', '20\'', '40\'', 'TEU', 'FEU'];
        foreach ($containers as $container) {
            if (stripos($text, $container) !== false) {
                $extractedData['shipment']['container_type'] = $container;
                break;
            }
        }
    }

    /**
     * Enhanced pricing information extraction
     */
    private function extractPricingInfo(string $text, array &$extractedData): void
    {
        // Currency and amount patterns
        if (preg_match('/\b(USD|EUR|GBP)\s*(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)\b/', $text, $matches)) {
            $extractedData['pricing']['currency'] = $matches[1];
            $extractedData['pricing']['amount'] = str_replace(',', '', $matches[2]);
        }
    }

    /**
     * Enhanced date information extraction
     */
    private function extractDateInfo(string $text, array &$extractedData): void
    {
        // Date patterns
        if (preg_match_all('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', $text, $matches)) {
            $extractedData['dates']['pickup'] = $matches[0][0] ?? null;
            $extractedData['dates']['delivery'] = $matches[0][1] ?? null;
        }
    }

    /**
     * Merge extraction results intelligently
     */
    private function mergeExtractionResults(array $patternResults, array $aiResults): array
    {
        $merged = $patternResults;

        foreach ($aiResults as $category => $data) {
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    // AI results take precedence if pattern results are empty
                    if (empty($merged[$category][$key]) && !empty($value)) {
                        $merged[$category][$key] = $value;
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * Extract text from PDF content using enhanced methods
     */
    private function extractTextFromPdf(string $pdfContent): string
    {
        try {
            // Use smalot/pdfparser library
            $parser = new Parser();
            $pdf = $parser->parseContent($pdfContent);
            
            $text = $pdf->getText();
            
            // Enhanced text cleanup
            $text = $this->cleanExtractedText($text);
            
            return $text;
            
        } catch (\Exception $e) {
            Log::warning('PDF text extraction failed with parser (ENHANCED)', [
                'error' => $e->getMessage(),
                'fallback' => 'trying alternative method'
            ]);
            
            // Fallback: try basic text extraction if parser fails
            return $this->fallbackTextExtraction($pdfContent);
        }
    }

    /**
     * Enhanced text cleanup for PDFs
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
        
        // Enhanced cleanup for PDF-specific artifacts
        $text = preg_replace('/\b\d+\s*of\s*\d+\b/', '', $text); // Remove page numbers
        $text = preg_replace('/\bPage\s+\d+\b/i', '', $text); // Remove page headers
        
        return trim($text);
    }

    /**
     * Fallback text extraction method
     */
    private function fallbackTextExtraction(string $pdfContent): string
    {
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
     * Calculate confidence based on extracted data quality
     */
    private function calculateConfidence(array $extractedData, string $text): float
    {
        $score = 0.5; // Base score
        
        // Increase score based on extracted fields
        if (!empty($extractedData['contact'])) $score += 0.1;
        if (!empty($extractedData['vehicle'])) $score += 0.1;
        if (!empty($extractedData['shipment'])) $score += 0.1;
        if (!empty($extractedData['pricing'])) $score += 0.1;
        if (!empty($extractedData['dates'])) $score += 0.1;
        
        // Increase score based on text quality
        if (strlen($text) > 100) $score += 0.1;
        
        return min(1.0, $score);
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
            'description' => 'Enhanced PDF extraction with isolated processing',
            'isolation_level' => 'complete',
            'enhancement_features' => [
                'advanced_text_extraction',
                'intelligent_field_detection',
                'context_aware_parsing',
                'pattern_based_extraction',
                'ai_enhanced_extraction'
            ]
        ];
    }
}

