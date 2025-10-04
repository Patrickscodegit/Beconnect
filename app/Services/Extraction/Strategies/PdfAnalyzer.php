<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * PDF ANALYZER
 * 
 * Analyzes PDF characteristics to select the optimal extraction method.
 * Performs quick analysis without full processing to determine the best approach.
 */
class PdfAnalyzer
{
    private const LARGE_FILE_THRESHOLD = 5_000_000; // 5MB
    private const VERY_LARGE_FILE_THRESHOLD = 10_000_000; // 10MB
    private const MIN_TEXT_LENGTH = 100;

    /**
     * Analyze PDF characteristics
     */
    public function analyzePdf(Document $document): PdfCharacteristics
    {
        $startTime = microtime(true);
        
        Log::debug('Starting PDF analysis', [
            'document_id' => $document->id,
            'filename' => $document->filename
        ]);

        try {
            // Get basic file information
            $fileSize = $this->getFileSize($document);
            $filePath = Storage::disk($document->storage_disk)->path($document->file_path);
            
            // Quick analysis of first page
            $firstPageAnalysis = $this->analyzeFirstPage($filePath);
            
            // Determine characteristics
            $characteristics = new PdfCharacteristics([
                'size' => $fileSize,
                'has_text_layer' => $firstPageAnalysis['has_text_layer'],
                'is_scanned' => $firstPageAnalysis['is_scanned'],
                'complexity' => $this->estimateComplexity($firstPageAnalysis),
                'text_density' => $firstPageAnalysis['text_density'],
                'image_density' => $firstPageAnalysis['image_density'],
                'page_count' => $this->getPageCount($filePath),
                'file_path' => $filePath
            ]);
            
            $analysisTime = microtime(true) - $startTime;
            
            Log::info('PDF analysis completed', [
                'document_id' => $document->id,
                'analysis_time_ms' => round($analysisTime * 1000, 2),
                'characteristics' => $characteristics->toArray()
            ]);
            
            return $characteristics;
            
        } catch (\Exception $e) {
            Log::error('PDF analysis failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            
            // Return default characteristics
            return new PdfCharacteristics([
                'size' => $this->getFileSize($document),
                'has_text_layer' => false,
                'is_scanned' => true,
                'complexity' => 'high',
                'text_density' => 0,
                'image_density' => 1,
                'page_count' => 1,
                'file_path' => Storage::disk($document->storage_disk)->path($document->file_path)
            ]);
        }
    }

    /**
     * Select optimal extraction method based on characteristics
     */
    public function selectOptimalMethod(PdfCharacteristics $characteristics): string
    {
        $size = $characteristics->size;
        $hasTextLayer = $characteristics->has_text_layer;
        $isScanned = $characteristics->is_scanned;
        $complexity = $characteristics->complexity;
        $pageCount = $characteristics->page_count;
        
        // Decision tree for method selection
        if ($isScanned) {
            // Scanned PDF - use OCR directly
            return 'ocr_direct';
        }
        
        if ($size > self::VERY_LARGE_FILE_THRESHOLD) {
            // Very large file - use streaming
            return 'streaming';
        }
        
        if ($size > self::LARGE_FILE_THRESHOLD && $pageCount > 5) {
            // Large multi-page file - use streaming
            return 'streaming';
        }
        
        if (!$hasTextLayer) {
            // No text layer - use OCR
            return 'ocr_direct';
        }
        
        if ($complexity === 'high' || $pageCount > 10) {
            // Complex or many pages - use hybrid approach
            return 'hybrid';
        }
        
        if ($hasTextLayer && $size < self::LARGE_FILE_THRESHOLD) {
            // Small text-based PDF - use PDF parser
            return 'pdfparser';
        }
        
        // Default fallback
        return 'pdfparser';
    }

    /**
     * Get file size
     */
    private function getFileSize(Document $document): int
    {
        try {
            return Storage::disk($document->storage_disk)->size($document->file_path);
        } catch (\Exception $e) {
            Log::warning('Could not get file size', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Analyze first page of PDF
     */
    private function analyzeFirstPage(string $filePath): array
    {
        $tempDir = sys_get_temp_dir() . '/pdf_analysis_' . uniqid();
        mkdir($tempDir, 0755, true);
        
        try {
            // Convert first page to image
            $imagePath = $tempDir . '/first_page.png';
            $command = sprintf(
                'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r150 -dFirstPage=1 -dLastPage=1 -sOutputFile="%s" "%s" 2>&1',
                escapeshellarg($imagePath),
                escapeshellarg($filePath)
            );
            
            $output = shell_exec($command);
            
            if (!file_exists($imagePath)) {
                throw new \RuntimeException('Could not convert first page to image: ' . $output);
            }
            
            // Try to extract text from first page
            $textCommand = sprintf('pdftotext -f 1 -l 1 "%s" - 2>/dev/null', escapeshellarg($filePath));
            $text = shell_exec($textCommand);
            $text = $text ?: '';
            
            // Analyze the results
            $hasTextLayer = strlen(trim($text)) > self::MIN_TEXT_LENGTH;
            $isScanned = !$hasTextLayer;
            $textDensity = $this->calculateTextDensity($text);
            $imageDensity = $this->calculateImageDensity($imagePath);
            
            // Cleanup
            unlink($imagePath);
            rmdir($tempDir);
            
            return [
                'has_text_layer' => $hasTextLayer,
                'is_scanned' => $isScanned,
                'text_density' => $textDensity,
                'image_density' => $imageDensity,
                'text_length' => strlen($text)
            ];
            
        } catch (\Exception $e) {
            // Cleanup on error
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                foreach ($files as $file) {
                    unlink($file);
                }
                rmdir($tempDir);
            }
            
            throw $e;
        }
    }

    /**
     * Estimate PDF complexity
     */
    private function estimateComplexity(array $firstPageAnalysis): string
    {
        $textDensity = $firstPageAnalysis['text_density'];
        $imageDensity = $firstPageAnalysis['image_density'];
        
        if ($textDensity > 0.7 && $imageDensity < 0.3) {
            return 'low'; // Mostly text
        } elseif ($textDensity > 0.4 && $imageDensity < 0.6) {
            return 'medium'; // Mixed content
        } else {
            return 'high'; // Mostly images or complex layout
        }
    }

    /**
     * Calculate text density
     */
    private function calculateTextDensity(string $text): float
    {
        if (empty($text)) {
            return 0;
        }
        
        $textLength = strlen(trim($text));
        $wordCount = str_word_count($text);
        $lineCount = substr_count($text, "\n") + 1;
        
        // Simple density calculation
        $density = ($textLength / 1000) + ($wordCount / 100) + ($lineCount / 10);
        
        return min(1.0, $density);
    }

    /**
     * Calculate image density
     */
    private function calculateImageDensity(string $imagePath): float
    {
        if (!file_exists($imagePath)) {
            return 0;
        }
        
        $imageSize = filesize($imagePath);
        
        // Simple density calculation based on file size
        // Larger images typically indicate more visual content
        if ($imageSize > 500_000) { // 500KB
            return 1.0;
        } elseif ($imageSize > 200_000) { // 200KB
            return 0.7;
        } elseif ($imageSize > 50_000) { // 50KB
            return 0.4;
        } else {
            return 0.1;
        }
    }

    /**
     * Get page count
     */
    private function getPageCount(string $filePath): int
    {
        try {
            $command = sprintf('pdfinfo "%s" 2>/dev/null | grep Pages | awk \'{print $2}\'', escapeshellarg($filePath));
            $output = shell_exec($command);
            
            if ($output && is_numeric(trim($output))) {
                return (int) trim($output);
            }
            
            // Fallback: try to count pages by converting to images
            $tempDir = sys_get_temp_dir() . '/page_count_' . uniqid();
            mkdir($tempDir, 0755, true);
            
            $command = sprintf(
                'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r50 -sOutputFile="%s/page_%%d.png" "%s" 2>&1',
                escapeshellarg($tempDir),
                escapeshellarg($filePath)
            );
            
            shell_exec($command);
            
            $pageFiles = glob($tempDir . '/page_*.png');
            $pageCount = count($pageFiles);
            
            // Cleanup
            foreach ($pageFiles as $file) {
                unlink($file);
            }
            rmdir($tempDir);
            
            return $pageCount;
            
        } catch (\Exception $e) {
            Log::warning('Could not determine page count', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            
            return 1; // Default to 1 page
        }
    }
}

/**
 * PDF CHARACTERISTICS DATA CLASS
 */
class PdfCharacteristics
{
    public int $size;
    public bool $has_text_layer;
    public bool $is_scanned;
    public string $complexity;
    public float $text_density;
    public float $image_density;
    public int $page_count;
    public string $file_path;

    public function __construct(array $data)
    {
        $this->size = $data['size'] ?? 0;
        $this->has_text_layer = $data['has_text_layer'] ?? false;
        $this->is_scanned = $data['is_scanned'] ?? true;
        $this->complexity = $data['complexity'] ?? 'high';
        $this->text_density = $data['text_density'] ?? 0;
        $this->image_density = $data['image_density'] ?? 1;
        $this->page_count = $data['page_count'] ?? 1;
        $this->file_path = $data['file_path'] ?? '';
    }

    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'size_mb' => round($this->size / 1024 / 1024, 2),
            'has_text_layer' => $this->has_text_layer,
            'is_scanned' => $this->is_scanned,
            'complexity' => $this->complexity,
            'text_density' => $this->text_density,
            'image_density' => $this->image_density,
            'page_count' => $this->page_count,
            'file_path' => $this->file_path
        ];
    }

    public function isLarge(): bool
    {
        return $this->size > 5_000_000; // 5MB
    }

    public function isVeryLarge(): bool
    {
        return $this->size > 10_000_000; // 10MB
    }

    public function isMultiPage(): bool
    {
        return $this->page_count > 1;
    }

    public function isComplex(): bool
    {
        return $this->complexity === 'high';
    }
}
