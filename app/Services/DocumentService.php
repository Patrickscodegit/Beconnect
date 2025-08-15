<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Intake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class DocumentService
{
    private OcrService $ocrService;
    private PdfService $pdfService;
    private LlmExtractor $llmExtractor;

    public function __construct(
        OcrService $ocrService,
        PdfService $pdfService,
        LlmExtractor $llmExtractor
    ) {
        $this->ocrService = $ocrService;
        $this->pdfService = $pdfService;
        $this->llmExtractor = $llmExtractor;
    }

    public function processUpload(UploadedFile $file, string $type, string $source): Document
    {
        // Validate file size
        $maxSizeBytes = config('app.max_file_size_mb', 50) * 1024 * 1024;
        if ($file->getSize() > $maxSizeBytes) {
            throw new Exception("File size exceeds maximum allowed size of " . config('app.max_file_size_mb', 50) . "MB");
        }

        // Validate file type
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'];
        if (!in_array($file->getClientMimeType(), $allowedTypes)) {
            throw new Exception("File type not supported: " . $file->getClientMimeType());
        }

        try {
            // Store file in MinIO S3
            $path = Storage::disk('s3')->put('documents', $file);
            
            // Create intake record
            $intake = Intake::create([
                'source' => $source,
                'status' => 'uploaded'
            ]);

            // Create document record
            $document = Document::create([
                'intake_id' => $intake->id,
                'filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'document_type' => $type,
            ]);

            Log::info('Document uploaded successfully', [
                'document_id' => $document->id,
                'filename' => $file->getClientOriginalName(),
                'type' => $type,
                'size' => $file->getSize()
            ]);

            return $document;

        } catch (Exception $e) {
            Log::error('Document upload failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function extractText(Document $document): string
    {
        $cacheKey = "document_text_{$document->id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($document) {
            try {
                // Get file from storage
                $fileContent = Storage::disk('s3')->get($document->file_path);
                $tempPath = storage_path('app/temp/' . $document->id . '_' . basename($document->file_path));
                
                // Ensure temp directory exists
                if (!file_exists(dirname($tempPath))) {
                    mkdir(dirname($tempPath), 0755, true);
                }
                
                file_put_contents($tempPath, $fileContent);

                $extractedText = '';

                if ($document->mime_type === 'application/pdf') {
                    // Extract text from PDF
                    $extractedText = $this->pdfService->extractText($tempPath);
                    
                    // If PDF text extraction yields poor results, try OCR
                    if (strlen(trim($extractedText)) < 50) {
                        Log::info('PDF text extraction yielded poor results, trying OCR', [
                            'document_id' => $document->id
                        ]);
                        $extractedText = $this->ocrService->extractFromPdf($tempPath);
                    }
                } elseif (in_array($document->mime_type, ['image/jpeg', 'image/png', 'image/tiff'])) {
                    // Extract text from image using OCR
                    $extractedText = $this->ocrService->extractFromImage($tempPath);
                }

                // Clean up temp file
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }

                Log::info('Text extracted successfully', [
                    'document_id' => $document->id,
                    'text_length' => strlen($extractedText)
                ]);

                return $extractedText;

            } catch (Exception $e) {
                Log::error('Text extraction failed', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);
                
                // Clean up temp file on error
                if (isset($tempPath) && file_exists($tempPath)) {
                    unlink($tempPath);
                }
                
                throw $e;
            }
        });
    }

    public function classifyDocument(Document $document, string $text): string
    {
        $cacheKey = "document_classification_{$document->id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($document, $text) {
            try {
                // Use LLM for intelligent classification
                $classification = $this->llmExtractor->classifyDocument($text, $document->filename);
                
                Log::info('Document classified', [
                    'document_id' => $document->id,
                    'classification' => $classification
                ]);

                return $classification;

            } catch (Exception $e) {
                Log::warning('LLM classification failed, falling back to keyword matching', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);

                // Fallback to keyword-based classification
                return $this->keywordBasedClassification($text, $document->filename);
            }
        });
    }

    private function keywordBasedClassification(string $text, string $filename): string
    {
        $text = strtolower($text . ' ' . $filename);
        
        // Vehicle-related keywords
        $vehicleKeywords = ['vehicle', 'car', 'truck', 'vin', 'registration', 'title', 'automobile', 'motor'];
        foreach ($vehicleKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'vehicle_document';
            }
        }

        // Shipping/freight keywords
        $shippingKeywords = ['shipping', 'freight', 'cargo', 'shipment', 'transport', 'delivery', 'logistics'];
        foreach ($shippingKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'shipping_document';
            }
        }

        // Invoice/financial keywords
        $financialKeywords = ['invoice', 'bill', 'payment', 'cost', 'price', 'amount', 'total'];
        foreach ($financialKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'financial_document';
            }
        }

        return 'unknown';
    }

    public function extractVehicleData(Document $document, string $text): array
    {
        try {
            // Use LLM for structured vehicle data extraction
            $extractedData = $this->llmExtractor->extractVehicleData($text);
            
            Log::info('Vehicle data extracted via LLM', [
                'document_id' => $document->id,
                'extracted_fields' => array_keys($extractedData)
            ]);

            return $extractedData;

        } catch (Exception $e) {
            Log::warning('LLM extraction failed, falling back to pattern matching', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            // Fallback to pattern matching
            return $this->patternBasedExtraction($text);
        }
    }

    private function patternBasedExtraction(string $text): array
    {
        $extracted = [];

        // VIN pattern (17 characters, excluding I, O, Q)
        if (preg_match('/\b[A-HJ-NPR-Z0-9]{17}\b/', $text, $matches)) {
            $extracted['vin'] = $matches[0];
        }

        // Year pattern (4 digits between 1900-2030)
        if (preg_match('/\b(19|20)\d{2}\b/', $text, $matches)) {
            $extracted['year'] = (int) $matches[0];
        }

        // Make patterns (common vehicle manufacturers)
        $makes = ['Toyota', 'Honda', 'Ford', 'Chevrolet', 'BMW', 'Mercedes', 'Audi', 'Nissan', 'Hyundai'];
        foreach ($makes as $make) {
            if (stripos($text, $make) !== false) {
                $extracted['make'] = $make;
                break;
            }
        }

        return $extracted;
    }
}
