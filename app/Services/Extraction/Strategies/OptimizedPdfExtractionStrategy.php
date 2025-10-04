<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use App\Services\Extraction\Results\ExtractionResult;
use App\Services\RobawsIntegration\JsonFieldMapper;
use App\Services\PdfService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * OPTIMIZED PDF EXTRACTION STRATEGY
 * 
 * Phase 1 optimizations:
 * - Streaming PDF processing for large files
 * - Compiled regex patterns for faster matching
 * - Memory monitoring and optimization
 * - Intelligent method selection
 * - Optimized temporary file management
 */
class OptimizedPdfExtractionStrategy implements ExtractionStrategy
{
    private PdfService $pdfService;
    private JsonFieldMapper $jsonFieldMapper;
    private CompiledPatternEngine $patternEngine;
    private TempFileManager $tempManager;
    private MemoryMonitor $memoryMonitor;
    private PdfAnalyzer $pdfAnalyzer;

    public function __construct(
        PdfService $pdfService,
        JsonFieldMapper $jsonFieldMapper,
        CompiledPatternEngine $patternEngine,
        TempFileManager $tempManager,
        MemoryMonitor $memoryMonitor,
        PdfAnalyzer $pdfAnalyzer
    ) {
        $this->pdfService = $pdfService;
        $this->jsonFieldMapper = $jsonFieldMapper;
        $this->patternEngine = $patternEngine;
        $this->tempManager = $tempManager;
        $this->memoryMonitor = $memoryMonitor;
        $this->pdfAnalyzer = $pdfAnalyzer;
    }

    public function getName(): string
    {
        return 'optimized_pdf_extraction';
    }

    public function getPriority(): int
    {
        return 96; // Higher priority than SimplePdfExtractionStrategy (95)
    }

    public function supports(Document $document): bool
    {
        return $document->mime_type === 'application/pdf';
    }

    public function extract(Document $document): ExtractionResult
    {
        $startTime = microtime(true);
        $this->memoryMonitor->startMonitoring();

        Log::info('Starting optimized PDF extraction', [
            'document_id' => $document->id,
            'filename' => $document->filename,
            'file_size' => $this->getFileSize($document),
            'strategy' => $this->getName(),
            'is_temporary' => str_starts_with($document->id, 'temp_')
        ]);

        try {
            // Check if this is a temporary document (from ExtractionService)
            if (str_starts_with($document->id, 'temp_')) {
                Log::info('Processing temporary document, using fallback to SimplePdfExtractionStrategy', [
                    'document_id' => $document->id,
                    'reason' => 'Temporary documents not supported by optimized strategy'
                ]);
                
                // Fall back to SimplePdfExtractionStrategy for temporary documents
                $simpleStrategy = app(\App\Services\Extraction\Strategies\SimplePdfExtractionStrategy::class);
                return $simpleStrategy->extract($document);
            }

            // Phase 1: Analyze PDF characteristics
            $pdfCharacteristics = $this->pdfAnalyzer->analyzePdf($document);
            Log::info('PDF analysis completed', [
                'document_id' => $document->id,
                'characteristics' => $pdfCharacteristics->toArray()
            ]);

            // Phase 2: Select optimal extraction method
            $extractionMethod = $this->pdfAnalyzer->selectOptimalMethod($pdfCharacteristics);
            Log::info('Selected extraction method', [
                'document_id' => $document->id,
                'method' => $extractionMethod,
                'reason' => $this->getMethodSelectionReason($pdfCharacteristics, $extractionMethod)
            ]);

            // Phase 3: Extract text using optimized method
            $extractedText = $this->extractTextOptimized($document, $extractionMethod, $pdfCharacteristics);
            
            if (empty($extractedText)) {
                throw new \RuntimeException('No text extracted from PDF');
            }

            Log::info('Text extraction completed', [
                'document_id' => $document->id,
                'text_length' => strlen($extractedText),
                'method' => $extractionMethod
            ]);

            // Phase 4: Extract structured data using compiled patterns
            $extractedData = $this->extractStructuredDataOptimized($extractedText);
            
            // Phase 5: Apply transformations
            $mappedData = $this->jsonFieldMapper->mapFields($extractedData);

            $processingTime = microtime(true) - $startTime;
            $memoryUsage = $this->memoryMonitor->getPeakMemoryUsage();

            Log::info('Optimized PDF extraction completed', [
                'document_id' => $document->id,
                'processing_time' => round($processingTime, 3),
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'extracted_fields' => count($extractedData),
                'method' => $extractionMethod
            ]);

            return ExtractionResult::success($this->getName(), $mappedData);

        } catch (\Exception $e) {
            $processingTime = microtime(true) - $startTime;
            $memoryUsage = $this->memoryMonitor->getPeakMemoryUsage();

            Log::error('Optimized PDF extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'processing_time' => round($processingTime, 3),
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'trace' => $e->getTraceAsString()
            ]);

            // Fall back to SimplePdfExtractionStrategy on error
            try {
                Log::info('Falling back to SimplePdfExtractionStrategy due to error', [
                    'document_id' => $document->id,
                    'original_error' => $e->getMessage()
                ]);
                
                $simpleStrategy = app(\App\Services\Extraction\Strategies\SimplePdfExtractionStrategy::class);
                return $simpleStrategy->extract($document);
                
            } catch (\Exception $fallbackError) {
                Log::error('Fallback strategy also failed', [
                    'document_id' => $document->id,
                    'fallback_error' => $fallbackError->getMessage()
                ]);
                
                return ExtractionResult::failure(
                    $this->getName(),
                    $e->getMessage(),
                    ['document_id' => $document->id, 'error_type' => get_class($e)]
                );
            }
        } finally {
            $this->memoryMonitor->stopMonitoring();
            $this->tempManager->cleanup();
        }
    }

    /**
     * Extract text using optimized method based on PDF characteristics
     */
    private function extractTextOptimized(Document $document, string $method, PdfCharacteristics $characteristics): string
    {
        switch ($method) {
            case 'streaming':
                return $this->extractTextStreaming($document);
            
            case 'pdfparser':
                return $this->extractTextPdfParser($document);
            
            case 'ocr_direct':
                return $this->extractTextOcrDirect($document);
            
            case 'hybrid':
                return $this->extractTextHybrid($document);
            
            default:
                return $this->extractTextPdfParser($document);
        }
    }

    /**
     * Streaming text extraction for large files
     */
    private function extractTextStreaming(Document $document): string
    {
        Log::info('Using streaming text extraction', ['document_id' => $document->id]);
        
        $pdfPath = Storage::disk($document->storage_disk)->path($document->file_path);
        $tempDir = $this->tempManager->getTempDirectory();
        
        // Use poppler-utils for streaming extraction
        $command = sprintf(
            'pdftotext -layout "%s" "%s/extracted_text.txt" 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempDir)
        );
        
        $output = shell_exec($command);
        $textFile = $tempDir . '/extracted_text.txt';
        
        if (!file_exists($textFile)) {
            throw new \RuntimeException('Streaming extraction failed: ' . $output);
        }
        
        $text = file_get_contents($textFile);
        unlink($textFile);
        
        return $text ?: '';
    }

    /**
     * Standard PDF parser extraction
     */
    private function extractTextPdfParser(Document $document): string
    {
        Log::info('Using PDF parser extraction', ['document_id' => $document->id]);
        return $this->pdfService->extractText($document);
    }

    /**
     * Direct OCR extraction (skip PDF parser)
     */
    private function extractTextOcrDirect(Document $document): string
    {
        Log::info('Using direct OCR extraction', ['document_id' => $document->id]);
        
        // Convert PDF to images and extract text via OCR
        $pdfPath = Storage::disk($document->storage_disk)->path($document->file_path);
        $tempDir = $this->tempManager->getTempDirectory();
        
        // Convert PDF to images
        $command = sprintf(
            'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r150 -sOutputFile="%s/page_%%d.png" "%s" 2>&1',
            escapeshellarg($tempDir),
            escapeshellarg($pdfPath)
        );
        
        $output = shell_exec($command);
        
        // Extract text from images using OCR
        $extractedText = '';
        $pageFiles = glob($tempDir . '/page_*.png');
        
        foreach ($pageFiles as $pageFile) {
            $command = sprintf('tesseract "%s" stdout 2>/dev/null', escapeshellarg($pageFile));
            $pageText = shell_exec($command);
            $extractedText .= $pageText . "\n";
            unlink($pageFile);
        }
        
        return $extractedText;
    }

    /**
     * Hybrid extraction (PDF parser + OCR fallback)
     */
    private function extractTextHybrid(Document $document): string
    {
        Log::info('Using hybrid extraction', ['document_id' => $document->id]);
        
        try {
            // Try PDF parser first
            $text = $this->extractTextPdfParser($document);
            
            // If text is too short or seems incomplete, try OCR
            if (strlen($text) < 100 || $this->isTextIncomplete($text)) {
                Log::info('PDF parser text incomplete, trying OCR fallback', [
                    'document_id' => $document->id,
                    'text_length' => strlen($text)
                ]);
                
                $ocrText = $this->extractTextOcrDirect($document);
                
                // Use OCR text if it's longer
                if (strlen($ocrText) > strlen($text)) {
                    return $ocrText;
                }
            }
            
            return $text;
            
        } catch (\Exception $e) {
            Log::warning('PDF parser failed, falling back to OCR', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            
            return $this->extractTextOcrDirect($document);
        }
    }

    /**
     * Extract structured data using compiled patterns
     */
    private function extractStructuredDataOptimized(string $text): array
    {
        $extractedData = [];
        
        // Process patterns in order of likelihood with early termination
        $patternOrder = [
            'contact_info' => 0.9,    // Most likely to be found
            'vehicle_info' => 0.8,
            'routing_info' => 0.7,
            'cargo_info' => 0.6,
        ];
        
        foreach ($patternOrder as $patternType => $confidence) {
            if ($this->extractPatternType($patternType, $text, $extractedData)) {
                Log::debug('Pattern extracted', [
                    'pattern_type' => $patternType,
                    'confidence' => $confidence,
                    'fields_found' => count($extractedData)
                ]);
                
                // Early termination if we have enough data
                if ($this->hasMinimumRequiredData($extractedData)) {
                    Log::info('Early termination - minimum required data found', [
                        'fields_found' => count($extractedData)
                    ]);
                    break;
                }
            }
        }
        
        return $extractedData;
    }

    /**
     * Extract specific pattern type using compiled patterns
     */
    private function extractPatternType(string $patternType, string $text, array &$extractedData): bool
    {
        switch ($patternType) {
            case 'contact_info':
                return $this->extractContactInfo($text, $extractedData);
            
            case 'vehicle_info':
                return $this->extractVehicleInfo($text, $extractedData);
            
            case 'routing_info':
                return $this->extractRoutingInfo($text, $extractedData);
            
            case 'cargo_info':
                return $this->extractCargoInfo($text, $extractedData);
            
            default:
                return false;
        }
    }

    /**
     * Extract contact information using compiled patterns
     */
    private function extractContactInfo(string $text, array &$extractedData): bool
    {
        $found = false;
        
        // Extract shipper
        if ($shipperMatch = $this->patternEngine->match('shipper', $text)) {
            $extractedData['shipper'] = $this->buildContactData($shipperMatch, 'shipper');
            $found = true;
        }
        
        // Extract consignee
        if ($consigneeMatch = $this->patternEngine->match('consignee', $text)) {
            $extractedData['consignee'] = $this->buildContactData($consigneeMatch, 'consignee');
            $found = true;
        }
        
        // Extract notify
        if ($notifyMatch = $this->patternEngine->match('notify', $text)) {
            $extractedData['notify'] = $this->buildContactData($notifyMatch, 'notify');
            $found = true;
        }
        
        return $found;
    }

    /**
     * Extract vehicle information using compiled patterns
     */
    private function extractVehicleInfo(string $text, array &$extractedData): bool
    {
        $found = false;
        
        // Extract vehicle model
        if ($modelMatch = $this->patternEngine->match('vehicle_model', $text)) {
            $extractedData['vehicle_model'] = $modelMatch[1] ?? null;
            $found = true;
        }
        
        // Extract VIN
        if ($vinMatch = $this->patternEngine->match('vin', $text)) {
            $extractedData['vin'] = $vinMatch[1] ?? null;
            $found = true;
        }
        
        // Extract year
        if ($yearMatch = $this->patternEngine->match('year', $text)) {
            $extractedData['year'] = $yearMatch[1] ?? null;
            $found = true;
        }
        
        return $found;
    }

    /**
     * Extract routing information using compiled patterns
     */
    private function extractRoutingInfo(string $text, array &$extractedData): bool
    {
        $found = false;
        
        // Extract POR
        if ($porMatch = $this->patternEngine->match('por', $text)) {
            $extractedData['por'] = $porMatch[1] ?? null;
            $found = true;
        }
        
        // Extract POL
        if ($polMatch = $this->patternEngine->match('pol', $text)) {
            $extractedData['pol'] = $polMatch[1] ?? null;
            $found = true;
        }
        
        // Extract destination
        if ($destMatch = $this->patternEngine->match('destination', $text)) {
            $extractedData['destination'] = $destMatch[1] ?? null;
            $found = true;
        }
        
        return $found;
    }

    /**
     * Extract cargo information using compiled patterns
     */
    private function extractCargoInfo(string $text, array &$extractedData): bool
    {
        $found = false;
        
        // Extract cargo
        if ($cargoMatch = $this->patternEngine->match('cargo', $text)) {
            $extractedData['cargo'] = $this->formatCargoData($cargoMatch);
            $found = true;
        }
        
        return $found;
    }

    /**
     * Build contact data from pattern match
     */
    private function buildContactData(array $match, string $type): array
    {
        return [
            'name' => $match[1] ?? null,
            'address' => $match[2] ?? null,
            'email' => $match[3] ?? null,
            'phone' => $match[4] ?? null,
            'client_type' => $type
        ];
    }

    /**
     * Format cargo data from pattern match
     */
    private function formatCargoData(array $match): string
    {
        $parts = [];
        
        if (!empty($match[1])) $parts[] = $match[1]; // quantity
        if (!empty($match[2])) $parts[] = $match[2]; // condition
        if (!empty($match[3])) $parts[] = $match[3]; // make
        if (!empty($match[4])) $parts[] = $match[4]; // model
        if (!empty($match[5])) $parts[] = $match[5]; // year
        if (!empty($match[6])) $parts[] = $match[6]; // vin
        
        return implode(' ', $parts);
    }

    /**
     * Check if we have minimum required data
     */
    private function hasMinimumRequiredData(array $extractedData): bool
    {
        $requiredFields = ['shipper', 'consignee', 'cargo'];
        $foundFields = 0;
        
        foreach ($requiredFields as $field) {
            if (!empty($extractedData[$field])) {
                $foundFields++;
            }
        }
        
        return $foundFields >= 2; // Need at least 2 of 3 required fields
    }

    /**
     * Check if text seems incomplete
     */
    private function isTextIncomplete(string $text): bool
    {
        // Check for common indicators of incomplete text
        $incompleteIndicators = [
            'Serialnumber YearWeightType', // Placeholder text
            'CategoryMake VIN',           // Incomplete pattern
            'Truck',                     // Just the word "Truck"
        ];
        
        foreach ($incompleteIndicators as $indicator) {
            if (strpos($text, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get file size for analysis
     */
    private function getFileSize(Document $document): int
    {
        try {
            return Storage::disk($document->storage_disk)->size($document->file_path);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get method selection reason
     */
    private function getMethodSelectionReason(PdfCharacteristics $characteristics, string $method): string
    {
        if ($method === 'streaming') {
            return 'Large file size (' . round($characteristics->size / 1024 / 1024, 2) . 'MB)';
        } elseif ($method === 'ocr_direct') {
            return 'Scanned PDF detected';
        } elseif ($method === 'hybrid') {
            return 'Complex PDF requiring multiple methods';
        } else {
            return 'Standard text-based PDF';
        }
    }
}
