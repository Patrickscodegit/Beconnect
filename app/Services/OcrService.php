<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Exception;

class OcrService
{
    private string $tesseractPath;
    private string $ghostscriptPath;
    private array $rateLimiter = [];

    public function __construct()
    {
        $this->tesseractPath = config('services.tesseract.path', '/opt/homebrew/bin/tesseract');
        $this->ghostscriptPath = config('services.ghostscript.path', '/opt/homebrew/bin/gs');
    }

    public function run(Document $document): void
    {
        // Rate limiting check
        $this->checkRateLimit();

        Log::info("Starting OCR processing", [
            'document_id' => $document->id,
            'filename' => $document->original_filename,
            'mime_type' => $document->mime_type
        ]);

        try {
            // Get file from storage
            $fileContent = Storage::disk('s3')->get($document->storage_path);
            $tempPath = storage_path('app/temp/ocr_' . $document->id . '_' . basename($document->storage_path));
            
            // Ensure temp directory exists
            if (!file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }
            
            file_put_contents($tempPath, $fileContent);

            $extractedText = '';
            $pageCount = 1;

            if ($document->mime_type === 'application/pdf') {
                $result = $this->extractFromPdf($tempPath);
                $extractedText = $result['text'];
                $pageCount = $result['page_count'];
            } elseif (in_array($document->mime_type, ['image/jpeg', 'image/png', 'image/tiff'])) {
                $extractedText = $this->extractFromImage($tempPath);
            }

            // Save OCR results to MinIO storage
            $ocrPath = "ocr-results/{$document->intake_id}/{$document->id}.txt";
            Storage::disk('s3')->put($ocrPath, $extractedText);

            // Update document with OCR results
            $document->update([
                'has_text_layer' => strlen(trim($extractedText)) > 0,
                'page_count' => $pageCount,
                'ocr_confidence' => $this->calculateConfidence($extractedText)
            ]);

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::info("OCR processing completed successfully", [
                'document_id' => $document->id,
                'text_length' => strlen($extractedText),
                'page_count' => $pageCount
            ]);

        } catch (Exception $e) {
            Log::error('OCR processing failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            // Clean up temp file on error
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            throw $e;
        }
    }

    public function extractFromImage(string $imagePath): string
    {
        if (!file_exists($this->tesseractPath)) {
            throw new Exception("Tesseract OCR not found at: {$this->tesseractPath}");
        }

        $outputPath = $imagePath . '_ocr';
        $languages = config('services.tesseract.languages', 'eng');
        
        try {
            // Run Tesseract OCR
            $command = [
                $this->tesseractPath,
                escapeshellarg($imagePath),
                escapeshellarg($outputPath),
                '-l', $languages,
                '--oem', '3',
                '--psm', '6'
            ];

            $result = Process::run(implode(' ', $command));

            if ($result->failed()) {
                throw new Exception("Tesseract failed: " . $result->errorOutput());
            }

            // Read the output file
            $textFile = $outputPath . '.txt';
            if (!file_exists($textFile)) {
                throw new Exception("Tesseract output file not found: {$textFile}");
            }

            $extractedText = file_get_contents($textFile);
            unlink($textFile); // Clean up

            return $this->cleanOcrText($extractedText);

        } catch (Exception $e) {
            Log::error('OCR image extraction failed', [
                'image_path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function extractFromPdf(string $pdfPath): array
    {
        try {
            // First, convert PDF to images using Ghostscript
            $imageDir = dirname($pdfPath) . '/pdf_images_' . uniqid();
            mkdir($imageDir, 0755, true);

            $dpi = config('services.pdf.dpi', 300);
            $maxPages = config('services.pdf.max_pages', 100);

            // Convert PDF to images
            $gsCommand = [
                $this->ghostscriptPath,
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-sDEVICE=png16m',
                "-r{$dpi}",
                "-dFirstPage=1",
                "-dLastPage={$maxPages}",
                "-sOutputFile={$imageDir}/page_%03d.png",
                escapeshellarg($pdfPath)
            ];

            $result = Process::run(implode(' ', $gsCommand));

            if ($result->failed()) {
                throw new Exception("Ghostscript failed: " . $result->errorOutput());
            }

            // Get list of generated images
            $images = glob($imageDir . '/page_*.png');
            sort($images);

            if (empty($images)) {
                throw new Exception("No images generated from PDF");
            }

            $allText = '';
            $pageCount = count($images);

            // Process each page with OCR
            foreach ($images as $imagePath) {
                $pageText = $this->extractFromImage($imagePath);
                $allText .= $pageText . "\n\n--- PAGE BREAK ---\n\n";
            }

            // Clean up image files
            foreach ($images as $imagePath) {
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
            rmdir($imageDir);

            return [
                'text' => $this->cleanOcrText($allText),
                'page_count' => $pageCount
            ];

        } catch (Exception $e) {
            Log::error('OCR PDF extraction failed', [
                'pdf_path' => $pdfPath,
                'error' => $e->getMessage()
            ]);

            // Clean up on error
            if (isset($imageDir) && is_dir($imageDir)) {
                $files = glob($imageDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($imageDir);
            }

            throw $e;
        }
    }

    private function cleanOcrText(string $text): string
    {
        // Remove extra whitespace and clean up common OCR errors
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/[^\w\s\-.,;:()\[\]{}\/\\\\@#$%&*+=<>?!"]/', '', $text);
        $text = trim($text);

        // Remove common OCR artifacts
        $text = str_replace(['|', '_', '~'], '', $text);
        
        return $text;
    }

    private function calculateConfidence(string $text): int
    {
        // Simple confidence calculation based on text characteristics
        $confidence = 100;

        // Reduce confidence for very short text
        if (strlen($text) < 50) {
            $confidence -= 30;
        }

        // Reduce confidence for excessive special characters
        $specialCharRatio = (strlen($text) - strlen(preg_replace('/[^\w\s]/', '', $text))) / strlen($text);
        if ($specialCharRatio > 0.3) {
            $confidence -= 20;
        }

        // Reduce confidence for excessive short words (OCR artifacts)
        $words = explode(' ', $text);
        $shortWords = array_filter($words, fn($word) => strlen(trim($word)) <= 2);
        $shortWordRatio = count($shortWords) / max(count($words), 1);
        if ($shortWordRatio > 0.5) {
            $confidence -= 25;
        }

        return max(0, $confidence);
    }

    private function checkRateLimit(): void
    {
        $now = time();
        $minute = floor($now / 60);
        $maxRequests = config('services.ocr.rate_limit_per_minute', 100);

        if (!isset($this->rateLimiter[$minute])) {
            $this->rateLimiter[$minute] = 0;
        }

        if ($this->rateLimiter[$minute] >= $maxRequests) {
            throw new Exception("OCR rate limit exceeded. Maximum {$maxRequests} requests per minute.");
        }

        $this->rateLimiter[$minute]++;

        // Clean up old entries
        foreach ($this->rateLimiter as $key => $count) {
            if ($key < $minute - 5) {
                unset($this->rateLimiter[$key]);
            }
        }
    }
}
