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

        // Sort documents by priority (email > PDF > image)
        $sortedDocuments = $documents->sortByDesc(function ($document) {
            return $this->getDocumentPriority($document);
        });

        // Merge data from all documents
        foreach ($sortedDocuments as $document) {
            if (empty($document->extraction_data)) {
                continue;
            }

            $extractionData = $document->extraction_data;
            $documentType = $this->getDocumentType($document);

            Log::info('Merging document data', [
                'document_id' => $document->id,
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
                'document_id' => $document->id,
                'filename' => $document->filename,
                'type' => $documentType,
                'priority' => $this->getDocumentPriority($document)
            ];
        }

        // Calculate overall confidence
        $aggregated['metadata']['confidence'] = $this->calculateAggregatedConfidence($documents);

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

        // Select the primary document (highest priority) to create the offer
        $primaryDocument = $intake->documents()
            ->orderByDesc(function ($query) {
                // Order by mime type priority
                return $this->getDocumentPriority($query);
            })
            ->first();

        if (!$primaryDocument) {
            throw new \RuntimeException('No primary document found for offer creation');
        }

        // Update primary document with aggregated data for Robaws processing
        $primaryDocument->update([
            'extraction_data' => $aggregatedData
        ]);

        // Process document for Robaws (this creates robaws_quotation_data)
        $this->robawsService->processDocumentFromExtraction($primaryDocument);
        $primaryDocument->refresh();

        // Create the offer using the primary document
        $offerResult = $this->robawsService->createOfferFromDocument($primaryDocument);
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
     * Get priority score for document type (higher = more important)
     */
    private function getDocumentPriority(Document $document): int
    {
        $type = $this->getDocumentType($document);

        return match ($type) {
            'email' => 100,  // Email has highest priority (contains contact info, intent)
            'pdf' => 50,     // PDF has medium priority (detailed specs)
            'image' => 25,   // Image has lowest priority (visual confirmation)
            default => 0
        };
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

