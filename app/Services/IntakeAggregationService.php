<?php

namespace App\Services;

use App\Models\Intake;
use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class IntakeAggregationService
{
    public function __construct(
        private EnhancedRobawsIntegrationService $robawsService
    ) {}

    /**
     * Aggregate extraction data from all documents in an intake
     * Priority: Email > PDF > Image
     *
     * @param Intake $intake
     * @return array Aggregated extraction data
     */
    public function aggregateExtractionData(Intake $intake): array
    {
        Log::info('Starting intake data aggregation', [
            'intake_id' => $intake->id,
            'total_documents' => $intake->total_documents,
            'is_multi_document' => $intake->is_multi_document
        ]);

        $documents = $intake->documents()->get();
        
        if ($documents->isEmpty()) {
            Log::warning('No documents found for aggregation', ['intake_id' => $intake->id]);
            return [];
        }

        // Initialize aggregated data structure
        $aggregated = [
            'contact' => [],
            'shipment' => [],
            'vehicle' => [],
            'cargo' => [],
            'route' => [],
            'metadata' => [
                'sources' => [],
                'confidence' => 0,
                'aggregation_time' => now()->toIso8601String()
            ]
        ];

        // Sort files by priority (email > PDF > image)
        $sortedFiles = $intake->files->sortByDesc(function ($file) {
            return $this->getFilePriority($file);
        });

        // Merge data from all files
        foreach ($sortedFiles as $file) {
            // Find the corresponding Document model with extraction data
            $document = $intake->documents()->where(function($query) use ($file) {
                $query->where('file_path', $file->storage_path)
                      ->orWhere('filepath', $file->storage_path);
            })->first();
            
            if (!$document || empty($document->extraction_data)) {
                Log::debug('No extraction data found for file', [
                    'file_id' => $file->id,
                    'file_path' => $file->storage_path,
                    'has_document' => $document ? 'yes' : 'no',
                    'document_extraction_data' => $document ? (!empty($document->extraction_data) ? 'yes' : 'no') : 'n/a'
                ]);
                continue;
            }
            
            $extractionData = $document->extraction_data;
            $documentType = $this->getDocumentTypeFromMimeType($file->mime_type);

            Log::info('Merging file data', [
                'file_id' => $file->id,
                'document_type' => $documentType,
                'has_contact' => !empty($extractionData['contact']),
                'has_shipment' => !empty($extractionData['shipment']),
                'has_vehicle' => !empty($extractionData['vehicle'])
            ]);

            // Merge each section (only fill in missing data, don't overwrite)
            $aggregated['contact'] = $this->mergeData($aggregated['contact'], $extractionData['contact'] ?? []);
            $aggregated['shipment'] = $this->mergeData($aggregated['shipment'], $extractionData['shipment'] ?? []);
            $aggregated['vehicle'] = $this->mergeData($aggregated['vehicle'], $extractionData['vehicle'] ?? []);
            $aggregated['cargo'] = $this->mergeData($aggregated['cargo'], $extractionData['cargo'] ?? []);
            $aggregated['route'] = $this->mergeData($aggregated['route'], $extractionData['route'] ?? []);

            // Track source
            $aggregated['metadata']['sources'][] = [
                'file_id' => $file->id,
                'filename' => $file->filename,
                'type' => $documentType,
                'priority' => $this->getFilePriority($file)
            ];
        }

        // Calculate overall confidence
        $aggregated['metadata']['confidence'] = $this->calculateAggregatedConfidence($sortedFiles);

        // Store aggregated data on intake
        $intake->update(['aggregated_extraction_data' => $aggregated]);

        Log::info('Aggregation completed', [
            'intake_id' => $intake->id,
            'sources_merged' => count($aggregated['metadata']['sources']),
            'has_contact' => !empty($aggregated['contact']),
            'has_vehicle' => !empty($aggregated['vehicle']),
            'confidence' => $aggregated['metadata']['confidence']
        ]);

        return $aggregated;
    }

    /**
     * Create a single Robaws offer using aggregated data from all documents
     *
     * @param Intake $intake
     * @return int Robaws offer ID
     */
    public function createSingleOffer(Intake $intake): int
    {
        Log::info('Creating single Robaws offer for multi-document intake', [
            'intake_id' => $intake->id,
            'total_documents' => $intake->total_documents
        ]);

        // Get aggregated data (aggregate if not already done)
        $aggregatedData = $intake->aggregated_extraction_data;
        if (empty($aggregatedData)) {
            $aggregatedData = $this->aggregateExtractionData($intake);
        }

        // Select the primary file (highest priority) to create the offer
        $files = $intake->files;
        $primaryFile = $files->sortByDesc(function ($file) {
            return $this->getFilePriority($file);
        })->first();

        if (!$primaryFile) {
            throw new \RuntimeException('No primary file found for offer creation');
        }

        // Create a temporary Document model for Robaws processing
        $tempDocument = $this->createTempDocumentFromFile($intake, $primaryFile, $aggregatedData);

        // Log aggregated data before processing
        Log::info('Processing aggregated data for multi-document offer', [
            'intake_id' => $intake->id,
            'primary_file' => $primaryFile->filename,
            'aggregated_data_keys' => array_keys($aggregatedData),
            'has_contact' => !empty($aggregatedData['contact']),
            'has_vehicle' => !empty($aggregatedData['vehicle']),
            'has_shipment' => !empty($aggregatedData['shipment']),
        ]);

        // Process document for Robaws with aggregated extraction data
        $this->robawsService->processDocument($tempDocument, $aggregatedData);
        // Note: No refresh() needed since tempDocument is not persisted

        // Create the offer using the temporary document
        $offerResult = $this->robawsService->createOfferFromDocument($tempDocument);
        $offerId = $offerResult['id'] ?? null;

        if (!$offerId) {
            throw new \RuntimeException('Failed to create Robaws offer');
        }

        // Update intake with offer ID
        $intake->update(['robaws_offer_id' => $offerId]);

        // Link ALL documents to the same offer ID
        foreach ($intake->documents as $document) {
            $document->update(['robaws_quotation_id' => $offerId]);
        }

        Log::info('Single offer created and linked to all documents', [
            'intake_id' => $intake->id,
            'offer_id' => $offerId,
            'documents_linked' => $intake->documents->count()
        ]);

        // Attach all documents to the Robaws offer
        try {
            // Debug: Check what files we have before attaching
            Log::info('Debug: Files available for attachment', [
                'intake_id' => $intake->id,
                'offer_id' => $offerId,
                'documents_count' => $intake->documents->count(),
                'files_count' => $intake->files->count(),
                'documents' => $intake->documents->map(function($doc) {
                    return [
                        'id' => $doc->id,
                        'filename' => $doc->filename,
                        'file_path' => $doc->file_path,
                        'filepath' => $doc->filepath ?? 'null',
                        'status' => $doc->status
                    ];
                })->toArray(),
                'files' => $intake->files->map(function($file) {
                    return [
                        'id' => $file->id,
                        'filename' => $file->filename,
                        'storage_path' => $file->storage_path,
                        'storage_disk' => $file->storage_disk,
                        'mime_type' => $file->mime_type
                    ];
                })->toArray()
            ]);
            
            $exportService = app(\App\Services\Robaws\RobawsExportService::class);
            $exportService->attachDocumentsToOffer($intake, $offerId, 'aggregated_' . $intake->id);
            
            Log::info('All documents attached to Robaws offer', [
                'intake_id' => $intake->id,
                'offer_id' => $offerId,
                'total_files' => $intake->documents->count() + $intake->files->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to attach documents to offer', [
                'intake_id' => $intake->id,
                'offer_id' => $offerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw - offer was created successfully, attachment is secondary
        }

        return $offerId;
    }

    /**
     * Get document type from mime type
     */
    private function getDocumentType(Document $document): string
    {
        $mimeType = $document->mime_type;

        if (str_contains($mimeType, 'message/') || str_contains($mimeType, 'email')) {
            return 'email';
        }

        if (str_contains($mimeType, 'pdf')) {
            return 'pdf';
        }

        if (str_contains($mimeType, 'image/')) {
            return 'image';
        }

        return 'unknown';
    }

    /**
     * Get priority score for file type (higher = more important)
     */
    private function getFilePriority(\App\Models\IntakeFile $file): int
    {
        $mimeType = $file->mime_type;

        return match ($mimeType) {
            'message/rfc822' => 100,  // Email has highest priority (contains contact info, intent)
            'application/pdf' => 50,  // PDF has medium priority (detailed specs)
            'image/jpeg', 'image/png', 'image/gif', 'image/webp' => 25,  // Images have lowest priority
            default => 0
        };
    }

    /**
     * Get document type from MIME type
     */
    private function getDocumentTypeFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'message/rfc822' => 'email',
            'application/pdf' => 'pdf',
            'image/jpeg', 'image/png', 'image/gif', 'image/webp' => 'image',
            default => 'unknown'
        };
    }

    /**
     * Create a temporary Document model from IntakeFile for Robaws processing
     */
    private function createTempDocumentFromFile(Intake $intake, \App\Models\IntakeFile $file, array $aggregatedData): Document
    {
        return new Document([
            'intake_id' => $intake->id,
            'filename' => $file->filename,
            'original_filename' => $file->filename,
            'file_path' => $file->storage_path,
            'filepath' => $file->storage_path,
            'mime_type' => $file->mime_type,
            'file_size' => $file->file_size,
            'storage_disk' => $file->storage_disk,
            'status' => 'pending',
            'extraction_status' => 'completed',
            'extraction_confidence' => 0.8,
            'extraction_data' => $aggregatedData,
        ]);
    }

    /**
     * Merge two data arrays (only fill in missing fields, don't overwrite)
     */
    private function mergeData(array $existing, array $new): array
    {
        foreach ($new as $key => $value) {
            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                // Recursively merge arrays
                $existing[$key] = $this->mergeData($existing[$key], $value);
            } elseif (!isset($existing[$key]) || empty($existing[$key])) {
                // Only set if not already set
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    /**
     * Calculate overall confidence from all documents
     */
    private function calculateAggregatedConfidence($documents): float
    {
        if ($documents->isEmpty()) {
            return 0;
        }

        $totalConfidence = 0;
        $count = 0;

        foreach ($documents as $document) {
            if (!empty($document->extraction_confidence)) {
                $totalConfidence += $document->extraction_confidence;
                $count++;
            }
        }

        return $count > 0 ? round($totalConfidence / $count, 2) : 0;
    }
}

