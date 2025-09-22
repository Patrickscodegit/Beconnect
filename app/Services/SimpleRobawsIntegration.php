<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated This service is deprecated and will be removed in a future version.
 * Use EnhancedRobawsIntegrationService instead for all Robaws integration needs.
 * This service is kept temporarily for backward compatibility with console commands.
 */
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
                'robaws_quotation_data' => $robawsData,
                'robaws_quotation_id' => null, // Will be set when actually synced
                'robaws_sync_status' => 'ready', // ADD THIS
                'robaws_formatted_at' => now()   // ADD THIS
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
        // Extract vehicle info
        $vehicle = $extractedData['vehicle'] ?? $extractedData['vehicle_details'] ?? [];
        $contact = $extractedData['contact'] ?? $extractedData['contact_info'] ?? [];
        $shipment = $extractedData['shipment'] ?? [];
        
        // Build customer reference from vehicle and routing
        $customerRef = $this->buildCustomerReference($extractedData);
        
        // Calculate dimensions and volume
        $dimensions = $vehicle['dimensions'] ?? [];
        $dimBeforeDelivery = '';
        $volume = 0;
        
        if (!empty($dimensions)) {
            $length = floatval($dimensions['length_m'] ?? 0);
            $width = floatval($dimensions['width_m'] ?? 0);
            $height = floatval($dimensions['height_m'] ?? 0);
            
            if ($length > 0 && $width > 0 && $height > 0) {
                $volume = $length * $width * $height;
                $dimBeforeDelivery = sprintf('%.3f x %.2f x %.3f m // %.2f Cbm', 
                    $length, $width, $height, $volume);
            }
        }
        
        return [
            // Quotation Info fields (matching Robaws form)
            'customer' => $contact['name'] ?? 'Unknown Customer',
            'customer_reference' => $customerRef,
            'endcustomer' => $contact['name'] ?? null,
            'contact' => $contact['phone'] ?? $contact['phone_number'] ?? null,
            'client_email' => $contact['email'] ?? null,
            
            // Routing fields (matching Robaws form exactly)
            'por' => $shipment['origin'] ?? $this->extractOriginFromMessages($extractedData),
            'pol' => $this->mapPortOfLoading($shipment['origin'] ?? $this->extractOriginFromMessages($extractedData)),
            'pod' => $shipment['destination'] ?? $this->extractDestinationFromMessages($extractedData),
            'pot' => null, // Port of Transhipment - usually empty for direct routes
            'fdest' => null, // Final destination if different from POD
            'in_transit_to' => null,
            
            // Cargo Details (matching Robaws form)
            'cargo' => $this->buildCargoDescription($vehicle),
            'dim_bef_delivery' => $dimBeforeDelivery,
            'container_nr' => null, // Not applicable for RoRo
            
            // Service type
            'freight_type' => 'RoRo Vehicle Transport',
            'shipment_type' => 'RoRo',
            'container_type' => 'RoRo',
            'container_quantity' => 1,
            
            // Vehicle specifications
            'vehicle_brand' => $vehicle['brand'] ?? $vehicle['make'] ?? null,
            'vehicle_model' => $vehicle['model'] ?? null,
            'vehicle_year' => $vehicle['year'] ?? null,
            'vehicle_color' => $vehicle['color'] ?? null,
            'weight_kg' => $vehicle['weight_kg'] ?? null,
            'engine_cc' => $vehicle['engine_cc'] ?? null,
            'fuel_type' => $vehicle['fuel_type'] ?? null,
            'volume_m3' => $volume > 0 ? $volume : null,
            
            // Dates
            'departure_date' => $extractedData['dates']['pickup_date'] ?? null,
            'arrival_date' => $extractedData['dates']['delivery_date'] ?? null,
            'pickup_date' => $extractedData['dates']['pickup_date'] ?? null,
            'delivery_date' => $extractedData['dates']['delivery_date'] ?? null,
            
            // Trade terms
            'incoterms' => $extractedData['incoterms'] ?? 'CIF',
            'payment_terms' => $extractedData['payment_terms'] ?? null,
            
            // Email metadata (if from .eml file)
            'email_subject' => $extractedData['email_metadata']['subject'] ?? null,
            'email_from' => $extractedData['email_metadata']['from'] ?? null,
            'email_to' => $extractedData['email_metadata']['to'] ?? null,
            'email_date' => $extractedData['email_metadata']['date'] ?? null,
            
            // Additional information
            'special_requirements' => $extractedData['special_requirements'] ?? 
                                    $extractedData['special_instructions'] ?? null,
            'reference_number' => $extractedData['reference_number'] ?? 
                                $extractedData['invoice_number'] ?? null,
            'internal_remarks' => $this->buildInternalRemarks($extractedData),
            'notes' => isset($extractedData['email_metadata']['subject']) ? 
                      "Email: " . $extractedData['email_metadata']['subject'] : null,
            
            // Vehicle verification status
            'database_match' => $vehicle['database_match'] ?? false,
            'verified_specs' => $vehicle['verified_specs'] ?? false,
            'spec_id' => $vehicle['spec_id'] ?? null,
            
            // Original extracted data for reference
            'original_extraction' => $extractedData,
            
            // Metadata
            'extraction_confidence' => $extractedData['metadata']['confidence_score'] ?? 
                                     $extractedData['confidence_score'] ?? null,
            'formatted_at' => now()->toISOString(),
            'source' => 'bconnect_ai_extraction'
        ];
    }
    
    /**
     * Build customer reference in Robaws format
     */
    private function buildCustomerReference(array $extractedData): string
    {
        $parts = [];
        
        // Add export type
        $parts[] = 'EXP RORO';
        
        // Add route info
        $origin = $extractedData['shipment']['origin'] ?? $this->extractOriginFromMessages($extractedData);
        $destination = $extractedData['shipment']['destination'] ?? $this->extractDestinationFromMessages($extractedData);
        
        if ($origin && $destination) {
            // Simplify location names
            $originShort = $this->simplifyLocationName($origin);
            $destinationShort = $this->simplifyLocationName($destination);
            $parts[] = $originShort . ' - ' . $destinationShort;
        }
        
        // Add vehicle info
        $vehicle = $extractedData['vehicle'] ?? [];
        if (!empty($vehicle)) {
            $vehicleDesc = '1 x ';
            if (!empty($vehicle['condition'])) {
                $vehicleDesc .= ucfirst($vehicle['condition']) . ' ';
            }
            $vehicleDesc .= ($vehicle['brand'] ?? 'Vehicle') . ' ' . ($vehicle['model'] ?? '');
            $parts[] = $vehicleDesc;
        }
        
        return implode(' - ', array_filter($parts));
    }
    
    /**
     * Build cargo description for Robaws
     */
    private function buildCargoDescription(array $vehicle): string
    {
        if (empty($vehicle)) {
            return '1 x Vehicle';
        }
        
        $description = '1 x ';
        
        if (!empty($vehicle['condition'])) {
            $description .= ucfirst($vehicle['condition']) . ' ';
        }
        
        $description .= ($vehicle['brand'] ?? 'Vehicle');
        
        if (!empty($vehicle['model'])) {
            $description .= ' ' . $vehicle['model'];
        }
        
        return $description;
    }
    
    /**
     * Map Port of Receipt to appropriate Port of Loading
     */
    private function mapPortOfLoading(string $por): string
    {
        // Common mappings from city to actual port
        $portMappings = [
            'Brussels' => 'Antwerp',
            'Bruxelles' => 'Antwerp',
            'Antwerp' => 'Antwerp',
            'Anvers' => 'Antwerp',
            'Rotterdam' => 'Rotterdam',
            'Hamburg' => 'Hamburg',
            'Bremerhaven' => 'Bremerhaven',
        ];
        
        foreach ($portMappings as $city => $port) {
            if (stripos($por, $city) !== false) {
                return $port;
            }
        }
        
        return $por; // Return original if no mapping found
    }
    
    /**
     * Simplify location names for reference
     */
    private function simplifyLocationName(string $location): string
    {
        $simplifications = [
            'Brussels, Belgium' => 'BRU',
            'Bruxelles, Belgium' => 'BRU', 
            'Djeddah, Saudi Arabia' => 'JED',
            'Jeddah, Saudi Arabia' => 'JED',
            'Antwerp, Belgium' => 'ANR',
            'Rotterdam, Netherlands' => 'RTM',
        ];
        
        return $simplifications[$location] ?? $location;
    }
    
    /**
     * Build internal remarks from messages
     */
    private function buildInternalRemarks(array $extractedData): ?string
    {
        if (!isset($extractedData['messages']) || empty($extractedData['messages'])) {
            return null;
        }
        
        $messageTexts = [];
        foreach ($extractedData['messages'] as $message) {
            if (isset($message['text'])) {
                $sender = $message['sender'] ?? 'User';
                $timestamp = $message['timestamp'] ?? $message['time'] ?? '';
                $timePrefix = $timestamp ? '[' . date('H:i', strtotime($timestamp)) . '] ' : '';
                $messageTexts[] = $timePrefix . $sender . ': ' . $message['text'];
            }
        }
        
        return !empty($messageTexts) ? implode("\n", $messageTexts) : null;
    }
    
    /**
     * Extract origin from messages if not in shipment
     */
    private function extractOriginFromMessages(array $extractedData): ?string
    {
        if (!isset($extractedData['messages'])) {
            return null;
        }
        
        foreach ($extractedData['messages'] as $message) {
            if (isset($message['text'])) {
                if (preg_match('/from\s+([^to]+?)\s+to\s+/i', $message['text'], $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract destination from messages if not in shipment
     */
    private function extractDestinationFromMessages(array $extractedData): ?string
    {
        if (!isset($extractedData['messages'])) {
            return null;
        }
        
        foreach ($extractedData['messages'] as $message) {
            if (isset($message['text'])) {
                if (preg_match('/to\s+([^,\.\n]+)/i', $message['text'], $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return null;
    }

    /**
     * Get all documents ready for Robaws export
     */
    public function getDocumentsReadyForExport(): \Illuminate\Database\Eloquent\Collection
    {
        return Document::whereHas('extractions', function($q) {
                $q->where('status', 'completed');
            })
            ->whereNotNull('robaws_quotation_data')
            ->whereNull('robaws_quotation_id')
            ->get();
    }

    /**
     * Export document data in Robaws-compatible JSON format
     */
    public function exportDocumentForRobaws(Document $document): ?array
    {
        if (!$document->robaws_quotation_data) {
            return null;
        }

        return [
            'bconnect_document' => [
                'id' => $document->id,
                'filename' => $document->filename,
                'uploaded_at' => $document->created_at->toISOString(),
                'processed_at' => $document->extracted_at?->toISOString(),
            ],
            'robaws_quotation_data' => $document->robaws_quotation_data
        ];
    }

    /**
     * Mark document as manually synced to Robaws
     */
    public function markAsManuallySynced(Document $document, ?string $robawsQuotationId = null): bool
    {
        try {
            $document->update([
                'robaws_quotation_id' => $robawsQuotationId,
                'robaws_sync_status' => 'synced', // ADD THIS
                'robaws_synced_at' => now()      // ADD THIS
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
        $totalDocuments = Document::whereHas('extractions', function($q) {
            $q->where('status', 'completed');
        })->count();
        
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
