<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentConversion
{
    /**
     * Ensure we have a proper artifact for Robaws upload
     * Returns array with path, mime, size, filename, and source info
     */
    public function ensureUploadArtifact(Document $document): array
    {
        $path = $this->ensurePdfForUpload($document);
        $filename = $this->generateUploadFilename($document, $path);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        $size = is_file($path) ? (filesize($path) ?: 0) : 0;
        
        if (!is_file($path) || $size === 0) {
            Log::warning('Upload artifact missing/empty', compact('path', 'size'));
            // Fall back to original path if converted file is missing
            $originalPath = $this->getDocumentPath($document);
            if (is_file($originalPath)) {
                $path = $originalPath;
                $filename = $document->filename;
                $mimeType = mime_content_type($path) ?: 'application/octet-stream';
                $size = filesize($path) ?: 0;
            }
        }
        
        return [
            'path' => $path,
            'filename' => $filename,
            'mime' => $mimeType,
            'size' => $size,
            'source' => $this->getSourceType($document, $path),
        ];
    }

    /**
     * Ensure we have a proper file for Robaws upload
     * Returns local path to PDF/JPEG that can be uploaded
     */
    public function ensurePdfForUpload(Document $document): string
    {
        $originalPath = $this->getDocumentPath($document);
        $mimeType = $document->mime_type ?? (mime_content_type($originalPath) ?: null);
        
        Log::info('Preparing document for Robaws upload', [
            'document_id' => $document->id,
            'filename' => $document->filename,
            'mime_type' => $mimeType,
            'has_text_layer' => $document->has_text_layer ?? 'unknown'
        ]);

        // Handle different file types
        switch (true) {
            case $this->isEmlFile($document):
                // For EML files, upload the original file directly instead of converting to PDF
                // This avoids memory issues with large converted PDFs
                Log::info('Using original EML file for upload (no conversion)', [
                    'document_id' => $document->id,
                    'original_path' => $originalPath
                ]);
                return $originalPath;
                
            case $this->isHeicFile($mimeType):
                return $this->convertHeicToJpeg($originalPath);
                
            case $this->isImage($mimeType):
                return $this->ensureImageForUpload($document, $originalPath);
                
            case $this->isPdf($mimeType, $originalPath):
                return $this->ensurePdfForUpload_Internal($document, $originalPath);
                
            default:
                Log::warning('Unknown file type for Robaws upload', [
                    'document_id' => $document->id,
                    'mime_type' => $mimeType
                ]);
                return $originalPath; // Upload as-is and let Robaws decide
        }
    }

    /**
     * Check if document needs OCR processing
     */
    public function needsOcr(Document $document): bool
    {
        // Only PDFs can have text layers
        if (!$this->isPdf($document->mime_type, $this->getDocumentPath($document))) {
            return false; // Images will be handled differently
        }

        // If has_text_layer is explicitly 0, needs OCR
        if ($document->has_text_layer === 0) {
            return true;
        }

        // If unknown, be safe and assume it has text
        return false;
    }

    /**
     * Run OCR on a document and return extracted text
     */
    public function runOcr(string $filePath): string
    {
        try {
            // Check if we have tesseract available
            $tesseractPath = config('services.tesseract.path', 'tesseract');
            
            // For PDFs, convert to images first
            $mime = mime_content_type($filePath) ?: null;
            if ($this->isPdf($mime, $filePath)) {
                return $this->runPdfOcr($filePath);
            }
            
            // For images, run OCR directly
            return $this->runImageOcr($filePath);
            
        } catch (\Throwable $e) {
            Log::error('OCR processing failed', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Convert HEIC to JPEG
     */
    public function convertHeicToJpeg(string $heicPath): string
    {
        $outputPath = $this->getConvertedPath($heicPath, 'jpg');
        
        try {
            // Try with ImageMagick first
            if ($this->hasImageMagick()) {
                $quality = (int) config('services.images.jpeg_quality', 85);
                $command = sprintf(
                    '%s %s -quality %d -auto-orient %s',
                    $this->convertCmd(),
                    escapeshellarg($heicPath),
                    $quality,
                    escapeshellarg($outputPath)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0 && file_exists($outputPath)) {
                    Log::info('HEIC converted to JPEG with ImageMagick', [
                        'input' => $heicPath,
                        'output' => $outputPath
                    ]);
                    return $outputPath;
                }
            }
            
            // Fallback: copy as-is and let upload handle it
            copy($heicPath, $outputPath);
            return $outputPath;
            
        } catch (\Throwable $e) {
            Log::error('HEIC conversion failed', [
                'input' => $heicPath,
                'error' => $e->getMessage()
            ]);
            return $heicPath; // Return original if conversion fails
        }
    }

    /**
     * Strip EXIF data from images for security
     */
    public function stripExifData(string $imagePath): string
    {
        $outputPath = $this->getConvertedPath($imagePath, pathinfo($imagePath, PATHINFO_EXTENSION));
        
        try {
            if ($this->hasImageMagick()) {
                $command = sprintf(
                    '%s %s -strip %s',
                    $this->convertCmd(),
                    escapeshellarg($imagePath),
                    escapeshellarg($outputPath)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0 && file_exists($outputPath)) {
                    return $outputPath;
                }
            }
            
            // Fallback: copy as-is
            copy($imagePath, $outputPath);
            return $outputPath;
            
        } catch (\Throwable $e) {
            Log::error('EXIF stripping failed', [
                'input' => $imagePath,
                'error' => $e->getMessage()
            ]);
            return $imagePath;
        }
    }

    /**
     * Convert EML to PDF for upload
     */
    private function convertEmlToPdf(Document $document, string $emlPath): string
    {
        $outputPath = $this->getConvertedPath($emlPath, 'pdf');
        
        try {
            // Check if there's an attached PDF we can use instead
            $attachedPdf = $this->extractPdfFromEml($emlPath);
            if ($attachedPdf) {
                Log::info('Using attached PDF from EML', [
                    'document_id' => $document->id,
                    'pdf_path' => $attachedPdf
                ]);
                return $attachedPdf;
            }
            
            // Generate PDF from email content
            $emailContent = $this->parseEmlContent($emlPath);
            $this->generatePdfFromEmailContent($emailContent, $outputPath);
            
            if (!is_file($outputPath) || filesize($outputPath) === 0) {
                Log::warning('EML conversion did not produce a PDF, falling back to original EML', [
                    'document_id' => $document->id, 
                    'output' => $outputPath
                ]);
                return $emlPath;
            }
            
            return $outputPath;
            
        } catch (\Throwable $e) {
            Log::error('EML to PDF conversion failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return $emlPath; // Return original if conversion fails
        }
    }

    /**
     * Ensure image is properly formatted for upload
     */
    private function ensureImageForUpload(Document $document, string $imagePath): string
    {
        // Strip EXIF data for security
        $cleanPath = $this->stripExifData($imagePath);
        
        // Optionally convert to PDF for better Robaws compatibility
        if (config('services.robaws.convert_images_to_pdf', true)) {
            return $this->convertImageToPdf($cleanPath);
        }
        
        return $cleanPath;
    }

    /**
     * Ensure PDF is ready for upload
     */
    private function ensurePdfForUpload_Internal(Document $document, string $pdfPath): string
    {
        // If PDF has no text layer, we might want to OCR it first
        // But for upload purposes, we can send the original PDF to Robaws
        // Robaws might have its own OCR capabilities
        
        return $pdfPath;
    }

    /**
     * Convert image to PDF
     */
    private function convertImageToPdf(string $imagePath): string
    {
        $outputPath = $this->getConvertedPath($imagePath, 'pdf');
        
        try {
            if ($this->hasImageMagick()) {
                $command = sprintf(
                    '%s %s -page A4 -resize 595x842 -gravity center %s',
                    $this->convertCmd(),
                    escapeshellarg($imagePath),
                    escapeshellarg($outputPath)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode === 0 && file_exists($outputPath)) {
                    return $outputPath;
                }
            }
            
            return $imagePath; // Return original if conversion fails
            
        } catch (\Throwable $e) {
            Log::error('Image to PDF conversion failed', [
                'input' => $imagePath,
                'error' => $e->getMessage()
            ]);
            return $imagePath;
        }
    }

    /**
     * Run OCR on PDF
     */
    private function runPdfOcr(string $pdfPath): string
    {
        // Convert PDF pages to images first, then OCR
        $tempDir = sys_get_temp_dir() . '/bconnect_ocr_' . uniqid();
        mkdir($tempDir, 0755, true);
        
        try {
            $popplerPath = rtrim(config('services.poppler.path', ''), '/');
            $pdftoppm = $popplerPath ? $popplerPath . '/pdftoppm' : 'pdftoppm';
            $dpi = (int) config('services.pdf.dpi', 300);
            $maxPages = (int) config('services.pdf.max_pages', 100);
            
            // Convert PDF to images
            $command = sprintf(
                '%s -jpeg -r %d -f 1 -l %d %s %s/page',
                $pdftoppm,
                $dpi,
                $maxPages,
                escapeshellarg($pdfPath),
                escapeshellarg($tempDir)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \RuntimeException('PDF to image conversion failed');
            }
            
            // OCR each page
            $allText = [];
            $images = glob($tempDir . '/page-*.jpg');
            
            foreach ($images as $imagePath) {
                $pageText = $this->runImageOcr($imagePath);
                if (!empty($pageText)) {
                    $allText[] = $pageText;
                }
            }
            
            return implode("\n\n", $allText);
            
        } finally {
            // Clean up temp directory
            $this->cleanupDirectory($tempDir);
        }
    }

    /**
     * Run OCR on image
     */
    private function runImageOcr(string $imagePath): string
    {
        $tesseractPath = config('services.tesseract.path', 'tesseract');
        $languages = config('services.tesseract.languages', 'eng');
        $outputFile = sys_get_temp_dir() . '/ocr_' . uniqid();
        
        try {
            $command = sprintf(
                '%s %s %s -l %s',
                $tesseractPath,
                escapeshellarg($imagePath),
                escapeshellarg($outputFile),
                escapeshellarg($languages)
            );
            
            exec($command, $output, $returnCode);
            
            $textFile = $outputFile . '.txt';
            if ($returnCode === 0 && file_exists($textFile)) {
                $text = file_get_contents($textFile);
                unlink($textFile);
                return trim($text);
            }
            
            return '';
            
        } catch (\Throwable $e) {
            Log::error('Image OCR failed', [
                'image_path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Extract PDF attachment from EML file
     */
    private function extractPdfFromEml(string $emlPath): ?string
    {
        // Simple implementation - look for PDF attachments
        $emlContent = file_get_contents($emlPath);
        
        // This is a simplified parser - in production you'd use a proper email library
        if (preg_match('/Content-Type:\s*application\/pdf/i', $emlContent)) {
            // Found PDF attachment - extract it
            // Implementation would depend on your email parsing library
            Log::info('Found PDF attachment in EML', ['eml_path' => $emlPath]);
        }
        
        return null; // Return null if no PDF found
    }

    /**
     * Parse EML content for PDF generation
     */
    private function parseEmlContent(string $emlPath): array
    {
        $content = file_get_contents($emlPath);
        
        // Extract basic email info
        $subject = '';
        $from = '';
        $to = '';
        $body = '';
        
        if (preg_match('/^Subject:\s*(.+)$/mi', $content, $matches)) {
            $subject = trim($matches[1]);
        }
        
        if (preg_match('/^From:\s*(.+)$/mi', $content, $matches)) {
            $from = trim($matches[1]);
        }
        
        if (preg_match('/^To:\s*(.+)$/mi', $content, $matches)) {
            $to = trim($matches[1]);
        }
        
        // Extract body (simplified)
        $parts = explode("\r\n\r\n", $content, 2);
        if (count($parts) > 1) {
            $body = $parts[1];
        }
        
        return [
            'subject' => $subject,
            'from' => $from,
            'to' => $to,
            'body' => $body
        ];
    }

    /**
     * Generate PDF from email content
     */
    private function generatePdfFromEmailContent(array $emailContent, string $outputPath): void
    {
        // This would use a PDF generation library like TCPDF or DOMPDF
        // For now, create a simple text file and convert with ImageMagick
        
        $textContent = "Email: " . $emailContent['subject'] . "\n\n";
        $textContent .= "From: " . $emailContent['from'] . "\n";
        $textContent .= "To: " . $emailContent['to'] . "\n\n";
        $textContent .= $emailContent['body'];
        
        $tempTextFile = sys_get_temp_dir() . '/email_' . uniqid() . '.txt';
        file_put_contents($tempTextFile, $textContent);
        
        try {
            if ($this->hasImageMagick()) {
                $command = sprintf(
                    '%s -page A4 -pointsize 12 -font monospace label:@%s %s',
                    $this->convertCmd(),
                    escapeshellarg($tempTextFile),
                    escapeshellarg($outputPath)
                );
                
                exec($command, $output, $returnCode);
            }
        } finally {
            if (file_exists($tempTextFile)) {
                unlink($tempTextFile);
            }
        }
    }

    /**
     * Helper methods
     */
    private function getDocumentPath(Document $document): string
    {
        $disk = $document->storage_disk ?: 'local';
        return Storage::disk($disk)->path($document->file_path);
    }

    private function getConvertedPath(string $originalPath, string $newExtension): string
    {
        $info = pathinfo($originalPath);
        return $info['dirname'] . '/' . $info['filename'] . '-converted.' . $newExtension;
    }

    private function isEmlFile(Document $document): bool
    {
        return Str::endsWith(strtolower($document->filename), '.eml') || 
               $document->mime_type === 'message/rfc822';
    }

    private function isHeicFile(?string $mimeType): bool
    {
        return in_array($mimeType, ['image/heic', 'image/heif']);
    }

    private function isImage(?string $mimeType): bool
    {
        return $mimeType && Str::startsWith($mimeType, 'image/');
    }

    private function isPdf(?string $mimeType, ?string $path = null): bool
    {
        if ($mimeType === 'application/pdf') return true;
        if (is_string($mimeType) && stripos($mimeType, 'pdf') !== false) return true;
        if ($path && str_ends_with(strtolower($path), '.pdf')) return true;
        return false;
    }

    private function hasImageMagick(): bool
    {
        static $hasImageMagick = null;
        
        if ($hasImageMagick === null) {
            exec('which convert', $output, $returnCode);
            $hasImageMagick = $returnCode === 0;
        }
        
        return $hasImageMagick;
    }

    private function convertCmd(): string
    {
        return config('services.imagemagick.convert', 'convert');
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        
        try {
            $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
            $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($dir);
        } catch (\Throwable $e) {
            // Fallback to simple cleanup
            if (is_dir($dir)) {
                $files = glob($dir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($dir);
            }
        }
    }

    /**
     * Generate appropriate filename for upload
     */
    private function generateUploadFilename(Document $document, string $path): string
    {
        $originalName = pathinfo($document->filename, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // If this is a converted file, indicate that in the name
        if ($path !== $this->getDocumentPath($document)) {
            return $originalName . '-converted.' . $extension;
        }
        
        return $originalName . '.' . $extension;
    }

    /**
     * Determine the source type of the upload artifact
     */
    private function getSourceType(Document $document, string $path): string
    {
        $originalPath = $this->getDocumentPath($document);
        
        if ($path === $originalPath) {
            return 'original';
        }
        
        $originalMime = $document->mime_type ?? mime_content_type($originalPath);
        $convertedMime = mime_content_type($path);
        
        if ($this->isEmlFile($document)) {
            return 'converted:eml_to_pdf';
        } elseif ($this->isHeicFile($originalMime)) {
            return 'converted:heic_to_jpeg';
        } elseif ($this->isImage($originalMime) && $convertedMime === 'application/pdf') {
            return 'converted:image_to_pdf';
        } elseif ($this->isPdf($originalMime, $originalPath) && ($document->has_text_layer === 0)) {
            return 'original:pdf_no_text';
        }
        
        return 'processed';
    }
}
