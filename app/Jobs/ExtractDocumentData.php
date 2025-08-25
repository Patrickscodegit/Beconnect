<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Extraction;
use App\Services\DocumentService;
use App\Services\AiRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractDocumentData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Document $document
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DocumentService $documentService, AiRouter $aiRouter): void
    {
        try {
            Log::info('Starting extraction for document', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename
            ]);

            // Create extraction record
            $extraction = $this->document->extractions()->create([
                'intake_id' => $this->document->intake_id,
                'status' => 'processing',
                'confidence' => 0.0,
                'service_used' => 'llm',
                'extracted_data' => [],
                'raw_json' => '{}',
            ]);

            // Extract text from document
            $text = '';
            try {
                $text = $documentService->extractText($this->document);
            } catch (\Exception $e) {
                Log::warning('Text extraction failed, will use basic analysis', [
                    'document_id' => $this->document->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            if (empty($text)) {
                // Fallback to basic analysis if text extraction fails
                $documentData = $this->analyzeDocument($this->document);
                $extraction->update([
                    'status' => 'completed',
                    'extracted_data' => $documentData,
                    'confidence' => 0.6,
                    'raw_json' => json_encode($documentData),
                    'service_used' => 'basic_analyzer',
                ]);
                
                Log::info('Extraction completed with basic analysis (no text extracted)', [
                    'document_id' => $this->document->id,
                    'extraction_id' => $extraction->id
                ]);
                return;
            }

            // Classify document type and extract data
            try {
                $classification = $documentService->classifyDocument($this->document, $text);
                
                // Define extraction schema for freight forwarding documents
                $schema = [
                    'consignee' => [
                        'type' => 'object',
                        'description' => 'Consignee information',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Company or person name'],
                            'address' => ['type' => 'string', 'description' => 'Full address'],
                            'contact' => ['type' => 'string', 'description' => 'Phone or email']
                        ]
                    ],
                    'invoice' => [
                        'type' => 'object',
                        'description' => 'Invoice details',
                        'properties' => [
                            'number' => ['type' => 'string', 'description' => 'Invoice number'],
                            'amount' => ['type' => 'number', 'description' => 'Total amount'],
                            'currency' => ['type' => 'string', 'description' => 'Currency code'],
                            'date' => ['type' => 'string', 'description' => 'Invoice date']
                        ]
                    ],
                    'container' => [
                        'type' => 'object',
                        'description' => 'Container information',
                        'properties' => [
                            'number' => ['type' => 'string', 'description' => 'Container number'],
                            'size' => ['type' => 'string', 'description' => 'Container size'],
                            'type' => ['type' => 'string', 'description' => 'Container type']
                        ]
                    ],
                    'ports' => [
                        'type' => 'object',
                        'description' => 'Port information',
                        'properties' => [
                            'origin' => ['type' => 'string', 'description' => 'Port of origin'],
                            'destination' => ['type' => 'string', 'description' => 'Port of destination']
                        ]
                    ]
                ];
                
                // Extract structured data using AiRouter
                $extractedData = $aiRouter->extract($text, $schema);

                // Calculate confidence score
                $confidence = $this->calculateConfidence($extractedData ?? []);
                
                // Update extraction with results
                $extraction->update([
                    'status' => 'completed',
                    'extracted_data' => $extractedData ?? [],
                    'confidence' => $confidence,
                    'raw_json' => json_encode($extractedData),
                    'service_used' => 'ai_router',
                ]);

                Log::info('Extraction completed successfully with AiRouter', [
                    'document_id' => $this->document->id,
                    'extraction_id' => $extraction->id,
                    'confidence' => $confidence
                ]);
                
            } catch (\Exception $e) {
                Log::warning('AiRouter extraction failed, using basic analysis', [
                    'document_id' => $this->document->id,
                    'error' => $e->getMessage()
                ]);
                
                // Fallback to basic analysis
                $documentData = $this->analyzeDocument($this->document);
                $extraction->update([
                    'status' => 'completed',
                    'extracted_data' => $documentData,
                    'confidence' => 0.6,
                    'raw_json' => json_encode($documentData),
                    'service_used' => 'basic_analyzer',
                ]);
                
                Log::info('Extraction completed with basic analysis', [
                    'document_id' => $this->document->id,
                    'extraction_id' => $extraction->id
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Extraction failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update extraction status
            if (isset($extraction)) {
                $extraction->update([
                    'status' => 'failed',
                    'extracted_data' => ['error' => $e->getMessage()],
                ]);
            }

            throw $e;
        }
    }

    private function calculateConfidence(array $extractedData): float
    {
        if (empty($extractedData)) {
            return 0.0;
        }

        $totalFields = count($extractedData);
        $filledFields = 0;

        foreach ($extractedData as $value) {
            if (!empty($value) && $value !== null && $value !== '') {
                $filledFields++;
            }
        }

        return round(($filledFields / $totalFields) * 100, 2) / 100;
    }

    private function analyzeDocument(Document $document): array
    {
        // Basic document analysis without external dependencies
        return [
            'document_name' => $document->filename,
            'file_size' => $document->file_size,
            'mime_type' => $document->mime_type,
            'upload_date' => $document->created_at->toISOString(),
            'analysis_type' => 'basic',
            'extracted_fields' => [
                'document_type' => $this->guessDocumentType($document->filename),
                'file_extension' => pathinfo($document->filename, PATHINFO_EXTENSION),
                'estimated_pages' => $this->estimatePages($document->file_size),
            ],
            'processing_notes' => 'Basic extraction completed - advanced AI extraction pending service configuration'
        ];
    }

    private function guessDocumentType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return match($extension) {
            'pdf' => 'PDF Document',
            'doc', 'docx' => 'Word Document',
            'xls', 'xlsx' => 'Excel Spreadsheet',
            'txt' => 'Text Document',
            'jpg', 'jpeg', 'png' => 'Image Document',
            default => 'Unknown Document Type'
        };
    }

    private function estimatePages(int $fileSize): int
    {
        // Rough estimation: 50KB per page for PDF
        return max(1, intval($fileSize / 50000));
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Extraction job permanently failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage()
        ]);

        $this->document->extractions()
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'extracted_data' => ['error' => 'Job failed: ' . $exception->getMessage()],
            ]);
    }
}
