<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Intake;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser;
use Exception;

class PdfService
{
    private Parser $pdfParser;
    private string $popplerPath;

    public function __construct()
    {
        $this->pdfParser = new Parser();
        $this->popplerPath = config('services.poppler.path', '/opt/homebrew/bin');
    }

    public function extractText(string $pdfPath): string
    {
        try {
            // First try native PDF text extraction with pdfparser
            Log::info('Attempting PDF text extraction with pdfparser', [
                'pdf_path' => basename($pdfPath)
            ]);

            $pdf = $this->pdfParser->parseFile($pdfPath);
            $text = $pdf->getText();

            if (strlen(trim($text)) > 50) {
                Log::info('PDF text extraction successful with pdfparser', [
                    'text_length' => strlen($text)
                ]);
                return $this->cleanPdfText($text);
            }

            // Fallback to poppler-utils pdftotext
            Log::info('pdfparser yielded poor results, trying poppler pdftotext');
            return $this->extractTextWithPoppler($pdfPath);

        } catch (Exception $e) {
            Log::warning('PDF text extraction with pdfparser failed, trying poppler', [
                'error' => $e->getMessage()
            ]);
            
            // Fallback to poppler-utils
            try {
                return $this->extractTextWithPoppler($pdfPath);
            } catch (Exception $fallbackError) {
                Log::error('All PDF text extraction methods failed', [
                    'pdfparser_error' => $e->getMessage(),
                    'poppler_error' => $fallbackError->getMessage()
                ]);
                throw new Exception('PDF text extraction failed: ' . $fallbackError->getMessage());
            }
        }
    }

    private function extractTextWithPoppler(string $pdfPath): string
    {
        $pdfToTextPath = $this->popplerPath . '/pdftotext';
        
        if (!file_exists($pdfToTextPath)) {
            throw new Exception("pdftotext not found at: {$pdfToTextPath}");
        }

        $outputFile = $pdfPath . '_text.txt';

        try {
            $command = [
                $pdfToTextPath,
                '-enc', 'UTF-8',
                '-nopgbrk',
                '-q',
                escapeshellarg($pdfPath),
                escapeshellarg($outputFile)
            ];

            $result = Process::run(implode(' ', $command));

            if ($result->failed()) {
                throw new Exception("pdftotext failed: " . $result->errorOutput());
            }

            if (!file_exists($outputFile)) {
                throw new Exception("pdftotext output file not created");
            }

            $text = file_get_contents($outputFile);
            unlink($outputFile); // Clean up

            return $this->cleanPdfText($text);

        } catch (Exception $e) {
            // Clean up on error
            if (isset($outputFile) && file_exists($outputFile)) {
                unlink($outputFile);
            }
            throw $e;
        }
    }

    public function detectTextLayer(Document $document): bool
    {
        try {
            Log::info('Detecting PDF text layer', [
                'document_id' => $document->id,
                'filename' => $document->original_filename
            ]);

            // Get file from storage
            $fileContent = Storage::disk('s3')->get($document->storage_path);
            $tempPath = storage_path('app/temp/detect_' . $document->id . '_' . basename($document->storage_path));
            
            // Ensure temp directory exists
            if (!file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }
            
            file_put_contents($tempPath, $fileContent);

            // Try to extract text
            $text = $this->extractText($tempPath);
            $hasTextLayer = strlen(trim($text)) > 50;

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::info('PDF text layer detection completed', [
                'document_id' => $document->id,
                'has_text_layer' => $hasTextLayer,
                'text_length' => strlen($text)
            ]);

            return $hasTextLayer;

        } catch (Exception $e) {
            Log::error('PDF text layer detection failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            // Clean up temp file on error
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            return false; // Assume no text layer if detection fails
        }
    }

    public function getPageCount(string $pdfPath): int
    {
        try {
            $pdfInfoPath = $this->popplerPath . '/pdfinfo';
            
            if (!file_exists($pdfInfoPath)) {
                Log::warning("pdfinfo not found, estimating page count");
                return 1;
            }

            $result = Process::run("{$pdfInfoPath} " . escapeshellarg($pdfPath));

            if ($result->failed()) {
                Log::warning('pdfinfo failed, defaulting to 1 page');
                return 1;
            }

            // Parse pdfinfo output for "Pages:" line
            $lines = explode("\n", $result->output());
            foreach ($lines as $line) {
                if (preg_match('/^Pages:\s+(\d+)/', $line, $matches)) {
                    return (int) $matches[1];
                }
            }

            return 1;

        } catch (Exception $e) {
            Log::warning('Page count detection failed', [
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }

    public function classifyDocuments($documents): void
    {
        Log::info('Starting document classification', [
            'document_count' => is_countable($documents) ? count($documents) : 0
        ]);

        foreach ($documents as $document) {
            try {
                // Skip documents without valid file paths
                if (!$document->file_path) {
                    Log::warning('Document has no file path, skipping classification', [
                        'document_id' => $document->id
                    ]);
                    $document->update(['document_type' => 'unknown']);
                    continue;
                }

                // Get document text for classification
                $text = '';
                if ($document->mime_type === 'application/pdf') {
                    // Check if file exists in storage before trying to read
                    if (!Storage::disk('s3')->exists($document->file_path)) {
                        Log::warning('Document file not found in storage', [
                            'document_id' => $document->id,
                            'file_path' => $document->file_path
                        ]);
                        $document->update(['document_type' => 'unknown']);
                        continue;
                    }

                    // Get file from storage and extract text
                    $fileContent = Storage::disk('s3')->get($document->file_path);
                    $tempPath = storage_path('app/temp/classify_' . $document->id . '_' . basename($document->file_path));
                    
                    if (!file_exists(dirname($tempPath))) {
                        mkdir(dirname($tempPath), 0755, true);
                    }
                    
                    file_put_contents($tempPath, $fileContent);
                    $text = $this->extractText($tempPath);
                    
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                }

                $classification = $this->classifyByContent($text, $document->filename);
                
                $document->update(['document_type' => $classification]);
                
                Log::info('Document classified', [
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                    'classification' => $classification
                ]);

            } catch (Exception $e) {
                Log::error('Document classification failed', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);
                
                $document->update(['document_type' => 'unknown']);
            }
        }
    }

    private function classifyByContent(string $text, string $filename): string
    {
        $text = strtolower($text . ' ' . $filename);

        // Invoice patterns
        if (preg_match('/\b(invoice|bill|payment|amount|total|due)\b/', $text)) {
            return 'invoice';
        }

        // Bill of lading patterns
        if (preg_match('/\b(bill of lading|bol|shipper|consignee|cargo|freight)\b/', $text)) {
            return 'bill_of_lading';
        }

        // Vehicle registration patterns
        if (preg_match('/\b(registration|title|vin|vehicle|automobile|license)\b/', $text)) {
            return 'vehicle_registration';
        }

        // Customs/shipping documents
        if (preg_match('/\b(customs|declaration|export|import|tariff|duties)\b/', $text)) {
            return 'customs_document';
        }

        // Insurance documents
        if (preg_match('/\b(insurance|policy|coverage|premium|claim)\b/', $text)) {
            return 'insurance_document';
        }

        // Manifest patterns
        if (preg_match('/\b(manifest|packing list|container|booking)\b/', $text)) {
            return 'manifest';
        }

        return 'unknown';
    }

    public function collectTextForExtraction(Intake $intake): string
    {
        Log::info('Collecting text for LLM extraction', [
            'intake_id' => $intake->id,
            'document_count' => $intake->documents->count()
        ]);

        $combinedText = "";
        $maxTokens = config('services.openai.max_tokens_input', 120000); // ~30K words for GPT-4
        $currentLength = 0;
        
        foreach ($intake->documents as $document) {
            try {
                $documentText = "\n\n=== DOCUMENT: {$document->original_filename} ===\n";
                $documentText .= "Type: {$document->document_type}\n";
                $documentText .= "Size: {$document->file_size} bytes\n\n";

                if ($document->mime_type === 'application/pdf') {
                    // Extract PDF text
                    $fileContent = Storage::disk('s3')->get($document->storage_path);
                    $tempPath = storage_path('app/temp/extract_' . $document->id . '_' . basename($document->storage_path));
                    
                    if (!file_exists(dirname($tempPath))) {
                        mkdir(dirname($tempPath), 0755, true);
                    }
                    
                    file_put_contents($tempPath, $fileContent);
                    $pdfText = $this->extractText($tempPath);
                    
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }

                    $documentText .= $pdfText;
                } else {
                    // For non-PDF documents, include OCR results if available
                    $ocrPath = "ocr-results/{$document->intake_id}/{$document->id}.txt";
                    if (Storage::disk('s3')->exists($ocrPath)) {
                        $documentText .= Storage::disk('s3')->get($ocrPath);
                    } else {
                        $documentText .= "[OCR processing required for this document type]";
                    }
                }

                // Check token limit (rough estimate: 4 characters per token)
                $estimatedTokens = strlen($documentText) / 4;
                if ($currentLength + $estimatedTokens > $maxTokens) {
                    Log::warning('Text collection truncated due to token limit', [
                        'intake_id' => $intake->id,
                        'current_tokens' => $currentLength / 4,
                        'max_tokens' => $maxTokens
                    ]);
                    break;
                }

                $combinedText .= $documentText;
                $currentLength += strlen($documentText);

            } catch (Exception $e) {
                Log::error('Failed to collect text from document', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);

                $combinedText .= "\n\n=== DOCUMENT: {$document->original_filename} ===\n";
                $combinedText .= "[Error extracting text from this document]\n\n";
            }
        }
        
        Log::info('Text collection completed', [
            'intake_id' => $intake->id,
            'total_characters' => strlen($combinedText),
            'estimated_tokens' => strlen($combinedText) / 4
        ]);

        return $combinedText ?: "No text content available for extraction.";
    }

    public function collectDocumentsForExtraction(Intake $intake): array
    {
        Log::info('Collecting documents for LLM extraction', [
            'intake_id' => $intake->id,
            'document_count' => $intake->documents->count()
        ]);

        $documents = [];
        $maxTokens = config('services.openai.max_tokens_input', 120000);
        $currentLength = 0;
        
        foreach ($intake->documents as $document) {
            try {
                $documentText = '';

                if ($document->mime_type === 'application/pdf') {
                    // Extract PDF text
                    $fileContent = Storage::disk('s3')->get($document->storage_path);
                    $tempPath = storage_path('app/temp/extract_' . $document->id . '_' . basename($document->storage_path));
                    
                    if (!file_exists(dirname($tempPath))) {
                        mkdir(dirname($tempPath), 0755, true);
                    }
                    
                    file_put_contents($tempPath, $fileContent);
                    $documentText = $this->extractText($tempPath);
                    
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                } else {
                    // For non-PDF documents, include OCR results if available
                    $ocrPath = "ocr-results/{$document->intake_id}/{$document->id}.txt";
                    if (Storage::disk('s3')->exists($ocrPath)) {
                        $documentText = Storage::disk('s3')->get($ocrPath);
                    } else {
                        $documentText = "[OCR processing required for this document type]";
                    }
                }

                // Check token limit (rough estimate: 4 characters per token)
                $estimatedTokens = strlen($documentText) / 4;
                if ($currentLength + $estimatedTokens > $maxTokens) {
                    Log::warning('Document collection truncated due to token limit', [
                        'intake_id' => $intake->id,
                        'current_tokens' => $currentLength / 4,
                        'max_tokens' => $maxTokens
                    ]);
                    break;
                }

                $documents[] = [
                    'name' => $document->original_filename ?? $document->filename,
                    'mime' => $document->mime_type,
                    'text' => $documentText
                ];

                $currentLength += $estimatedTokens;

            } catch (Exception $e) {
                Log::error('Failed to collect text from document', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);

                $documents[] = [
                    'name' => $document->original_filename ?? $document->filename,
                    'mime' => $document->mime_type,
                    'text' => "[Error extracting text from this document: {$e->getMessage()}]"
                ];
            }
        }
        
        Log::info('Document collection completed', [
            'intake_id' => $intake->id,
            'document_count' => count($documents),
            'estimated_tokens' => $currentLength
        ]);

        return [
            'intake_id' => $intake->id,
            'documents' => $documents
        ];
    }

    private function cleanPdfText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove form feed and other control characters
        $text = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Fix common PDF extraction issues
        $text = str_replace(['�', 'ï¿½'], '', $text);
        
        return trim($text);
    }
}
