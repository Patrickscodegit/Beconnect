<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;

class SimpleRobawsIntegration
{
    /**
     * Store extracted data in JSON format for later Robaws sync
     */
    public function storeExtractedDataForRobaws(Document $document, array $extractedData): bool
    {
        try {
            // Format the data for Robaws
            $robawsData = $this->formatForRobaws($extractedData);
            
            // Update the document with Robaws-ready JSON
            $document->update([
                'robaws_json_data' => $robawsData,
                'robaws_sync_status' => 'ready',
                'robaws_formatted_at' => now()
            ]);

            Log::info('Document data formatted for Robaws', [
                'document_id' => $document->id,
                'filename' => $document->filename
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to format data for Robaws', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Format extracted data into Robaws-compatible structure
     */
    private function formatForRobaws(array $extractedData): array
    {
        return [
            // Main quotation fields
            'freight_type' => $extractedData['shipment_type'] ?? 'General Freight',
            'origin_port' => $extractedData['origin_port'] ?? null,
            'destination_port' => $extractedData['destination_port'] ?? null,
            'cargo_description' => $extractedData['cargo_description'] ?? null,
            
            // Container details
            'container_type' => $extractedData['container_type'] ?? null,
            'container_quantity' => $extractedData['quantity'] ?? null,
            'weight_kg' => $extractedData['weight'] ?? null,
            'volume_m3' => $extractedData['volume'] ?? null,
            
            // Trade terms
            'incoterms' => $extractedData['incoterms'] ?? null,
            'payment_terms' => $extractedData['payment_terms'] ?? null,
            
            // Dates
            'departure_date' => $extractedData['departure_date'] ?? null,
            'arrival_date' => $extractedData['arrival_date'] ?? null,
            
            // Client information
            'client_name' => $extractedData['consignee']['name'] ?? null,
            'client_address' => $extractedData['consignee']['address'] ?? null,
            'client_contact' => $extractedData['consignee']['contact'] ?? null,
            
            // Additional information
            'special_requirements' => $extractedData['special_requirements'] ?? null,
            'reference_number' => $extractedData['reference_number'] ?? null,
            
            // Original extracted data for reference
            'original_extraction' => $extractedData,
            
            // Metadata
            'extraction_confidence' => $extractedData['confidence_score'] ?? null,
            'formatted_at' => now()->toISOString(),
            'source' => 'bconnect_ai_extraction'
        ];
    }

    /**
     * Get all documents ready for Robaws export
     */
    public function getDocumentsReadyForExport(): \Illuminate\Database\Eloquent\Collection
    {
        return Document::where('robaws_sync_status', 'ready')
                      ->whereNotNull('robaws_json_data')
                      ->where('extraction_status', 'completed')
                      ->orderBy('robaws_formatted_at', 'desc')
                      ->get();
    }

    /**
     * Export document data in Robaws-compatible JSON format
     */
    public function exportDocumentForRobaws(Document $document): ?array
    {
        if (!$document->robaws_json_data) {
            return null;
        }

        return [
            'bconnect_document' => [
                'id' => $document->id,
                'filename' => $document->filename,
                'uploaded_at' => $document->created_at->toISOString(),
                'processed_at' => $document->robaws_formatted_at?->toISOString(),
            ],
            'robaws_quotation_data' => $document->robaws_json_data
        ];
    }

    /**
     * Mark document as manually synced to Robaws
     */
    public function markAsManuallySynced(Document $document, ?string $robawsQuotationId = null): bool
    {
        try {
            $document->update([
                'robaws_sync_status' => 'synced',
                'robaws_quotation_id' => $robawsQuotationId,
                'robaws_synced_at' => now()
            ]);

            Log::info('Document marked as synced to Robaws', [
                'document_id' => $document->id,
                'robaws_quotation_id' => $robawsQuotationId
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to mark document as synced', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Get summary of Robaws integration status
     */
    public function getIntegrationSummary(): array
    {
        $totalDocuments = Document::where('extraction_status', 'completed')->count();
        $readyForSync = Document::where('robaws_sync_status', 'ready')->count();
        $synced = Document::where('robaws_sync_status', 'synced')->count();
        $pending = $totalDocuments - $readyForSync - $synced;

        return [
            'total_documents' => $totalDocuments,
            'ready_for_sync' => $readyForSync,
            'synced' => $synced,
            'pending_formatting' => $pending,
            'latest_ready' => Document::where('robaws_sync_status', 'ready')
                                    ->latest('robaws_formatted_at')
                                    ->first()?->robaws_formatted_at?->toDateTimeString()
        ];
    }

    /**
     * Generate a downloadable JSON file for manual Robaws import
     */
    public function generateExportFile(): array
    {
        $documents = $this->getDocumentsReadyForExport();
        $exportData = [];

        foreach ($documents as $document) {
            $exportData[] = $this->exportDocumentForRobaws($document);
        }

        return [
            'export_timestamp' => now()->toISOString(),
            'total_records' => count($exportData),
            'source_system' => 'bconnect_ai_extraction',
            'format_version' => '1.0',
            'documents' => $exportData
        ];
    }
}
