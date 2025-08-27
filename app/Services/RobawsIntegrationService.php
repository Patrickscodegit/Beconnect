<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Quotation;
use Illuminate\Support\Facades\Log;

class RobawsIntegrationService
{
    public function __construct(
        private RobawsClient $robawsClient
    ) {}

    /**
     * Create a Robaws offer from extracted document data
     */
    public function createOfferFromDocument(Document $document): ?array
    {
        try {
            $extractedData = $document->extraction_data;
            
            if (empty($extractedData)) {
                Log::warning('No extraction data available for document', ['document_id' => $document->id]);
                return null;
            }

            // First, find or create the client in Robaws
            $client = $this->findOrCreateClientFromExtraction($extractedData);
            
            if (!$client) {
                Log::error('Failed to create/find client in Robaws');
                return null;
            }

            // Prepare the offer payload
            $offerPayload = $this->buildOfferPayload($extractedData, $client['id']);
            
            // Create the offer in Robaws
            $offer = $this->robawsClient->createOffer($offerPayload);
            
            // Save the Robaws offer ID back to our database
            $this->saveRobawsOffer($document, $offer);
            
            Log::info('Successfully created Robaws offer', [
                'document_id' => $document->id,
                'robaws_offer_id' => $offer['id'] ?? null
            ]);
            
            return $offer;
            
        } catch (\Exception $e) {
            Log::error('Error creating Robaws offer', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Build the offer payload for Robaws API
     */
    private function buildOfferPayload(array $extractedData, int $clientId): array
    {
        // Serialize the entire extracted JSON for the custom field
        $jsonString = json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        // Build line items from extracted data
        $lineItems = $this->buildLineItems($extractedData);
        
        return [
            'clientId' => $clientId,
            'name' => $this->generateOfferTitle($extractedData),
            'currency' => $extractedData['invoice']['currency'] ?? $extractedData['currency'] ?? 'EUR',
            'status' => 'DRAFT',
            
            // Push extracted JSON into the custom "JSON" field
            'extraFields' => [
                'JSON' => ['stringValue' => $jsonString],
            ],
            
            // Include line items if any
            'lineItems' => $lineItems,
            
            // Additional fields from extraction
            'validityDays' => 30,
            'paymentTermDays' => 30,
            'notes' => $this->extractNotes($extractedData),
        ];
    }

    /**
     * Generate a descriptive title for the offer
     */
    private function generateOfferTitle(array $data): string
    {
        $parts = [];
        
        // Add freight type
        if (!empty($data['shipment_type'])) {
            $parts[] = $data['shipment_type'];
        } else {
            $parts[] = 'Freight';
        }
        
        // Add route
        if (!empty($data['ports']['origin']) && !empty($data['ports']['destination'])) {
            $parts[] = "{$data['ports']['origin']} → {$data['ports']['destination']}";
        } elseif (!empty($data['port_of_loading']) && !empty($data['port_of_discharge'])) {
            $parts[] = "{$data['port_of_loading']} → {$data['port_of_discharge']}";
        }
        
        // Add container info
        if (!empty($data['container']['number'])) {
            $parts[] = "Container: {$data['container']['number']}";
        } elseif (!empty($data['container']['type'])) {
            $parts[] = "Container: {$data['container']['type']}";
        }
        
        // Add reference
        if (!empty($data['invoice']['number'])) {
            $parts[] = "Ref: {$data['invoice']['number']}";
        }
        
        return !empty($parts) ? implode(' | ', $parts) : 'Freight Quotation ' . date('Y-m-d');
    }

    /**
     * Build line items from extracted data
     */
    private function buildLineItems(array $data): array
    {
        $lineItems = [];
        
        // Add freight charges if available
        if (!empty($data['charges'])) {
            foreach ($data['charges'] as $charge) {
                $lineItems[] = [
                    'type' => 'LINE',
                    'description' => $charge['description'] ?? 'Freight Charge',
                    'quantity' => $charge['quantity'] ?? 1,
                    'unitPrice' => $charge['amount'] ?? 0,
                    'taxRate' => $charge['tax_rate'] ?? 0,
                ];
            }
        }
        
        // Add basic freight line if no specific charges
        if (empty($lineItems)) {
            $description = 'Freight Transport';
            
            // Enhance description with container info
            if (!empty($data['container']['type'])) {
                $description .= " - {$data['container']['type']}";
            }
            
            // Add route to description
            if (!empty($data['ports']['origin']) && !empty($data['ports']['destination'])) {
                $description .= " ({$data['ports']['origin']} - {$data['ports']['destination']})";
            }
            
            $lineItems[] = [
                'type' => 'LINE',
                'description' => $description,
                'quantity' => 1,
                'unitPrice' => 0, // To be filled by user
                'taxRate' => 21, // Default VAT rate
            ];
        }
        
        return $lineItems;
    }

    /**
     * Extract notes/comments from the data
     */
    private function extractNotes(array $data): string
    {
        $notes = [];
        
        // Add consignee information
        if (!empty($data['consignee']['name'])) {
            $notes[] = "Consignee: {$data['consignee']['name']}";
            
            if (!empty($data['consignee']['address'])) {
                $notes[] = "Address: {$data['consignee']['address']}";
            }
            
            if (!empty($data['consignee']['contact'])) {
                $notes[] = "Contact: {$data['consignee']['contact']}";
            }
        }
        
        // Add cargo information
        if (!empty($data['cargo_description'])) {
            $notes[] = "Cargo: {$data['cargo_description']}";
        }
        
        // Add weight and volume
        if (!empty($data['weight'])) {
            $notes[] = "Weight: {$data['weight']} kg";
        }
        
        if (!empty($data['volume'])) {
            $notes[] = "Volume: {$data['volume']} m³";
        }
        
        // Add container details
        if (!empty($data['container']['number'])) {
            $notes[] = "Container: {$data['container']['number']}";
        }
        
        // Add invoice information
        if (!empty($data['invoice']['number'])) {
            $notes[] = "Invoice: {$data['invoice']['number']}";
        }
        
        if (!empty($data['invoice']['date'])) {
            $notes[] = "Invoice Date: {$data['invoice']['date']}";
        }
        
        // Add special instructions
        if (!empty($data['special_instructions'])) {
            $notes[] = "Special Instructions: {$data['special_instructions']}";
        }
        
        return implode("\n", $notes);
    }

    /**
     * Find or create client from extraction data
     */
    private function findOrCreateClientFromExtraction(array $data): ?array
    {
        $consignee = $data['consignee'] ?? [];
        
        // Parse address properly
        $addressString = $consignee['address'] ?? $data['client_address'] ?? '';
        $addressParts = $this->parseAddress($addressString);
        
        $clientData = [
            'name' => $consignee['name'] ?? $data['client_name'] ?? 'Unknown Client',
            'email' => $consignee['email'] ?? $data['client_email'] ?? '',
            'tel' => $consignee['contact'] ?? $consignee['phone'] ?? $data['client_phone'] ?? '',
            'address' => [
                'addressLine1' => $addressParts['street'] ?? '',
                'addressLine2' => '',
                'postalCode' => $addressParts['postal'] ?? '',
                'city' => $addressParts['city'] ?? '',
                'country' => $addressParts['country'] ?? 'BE',
            ],
            'language' => 'en',
            'currency' => 'EUR',
            'paymentConditionId' => 1, // Default payment condition
            'generalLedgerAccountId' => 1107, // Default account from existing client
            'vatTariffId' => 16, // Default VAT tariff
        ];
        
        // Skip if no name
        if (empty($clientData['name']) || $clientData['name'] === 'Unknown Client') {
            // Try to create a name from available data
            if (!empty($data['invoice']['number'])) {
                $clientData['name'] = "Client - Invoice {$data['invoice']['number']}";
            } else {
                $clientData['name'] = "Client - " . date('Y-m-d H:i:s');
            }
        }
        
        try {
            return $this->robawsClient->findOrCreateClient($clientData);
        } catch (\Exception $e) {
            Log::error('Failed to create/find client in Robaws', [
                'client_data' => $clientData,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Parse address string into components
     */
    private function parseAddress(string $address): array
    {
        if (empty($address)) {
            return [];
        }
        
        // Simple address parsing - can be enhanced
        $parts = explode(',', $address);
        $result = [];
        
        if (count($parts) >= 1) {
            $result['street'] = trim($parts[0]);
        }
        if (count($parts) >= 2) {
            $result['city'] = trim($parts[1]);
        }
        if (count($parts) >= 3) {
            $result['country'] = trim($parts[2]);
        }
        
        // Try to extract postal code (assuming format like "1234 AB" or "12345")
        if (preg_match('/\b(\d{4,5}\s?[A-Z]{0,2})\b/', $address, $matches)) {
            $result['postal'] = $matches[1];
        }
        
        return $result;
    }

    /**
     * Save Robaws offer reference in our database
     */
    private function saveRobawsOffer(Document $document, array $offer): void
    {
        try {
            // Update document with Robaws reference if it's a real document
            if (isset($document->exists) && $document->exists) {
                $document->update([
                    'robaws_quotation_id' => $offer['id'] ?? null,
                    'robaws_quotation_data' => $offer,
                ]);
            }
            
            // Try to create or update local quotation record
            try {
                Quotation::updateOrCreate(
                    ['robaws_id' => $offer['id']],
                    [
                        'user_id' => $document->user_id ?? 1, // Fallback to user 1
                        'document_id' => null, // Skip document_id for intake-based exports
                        'quotation_number' => $offer['logicId'] ?? $offer['id'], // Use logicId (O251069) instead of numeric ID
                        'status' => strtolower($offer['status'] ?? 'draft'),
                        'client_name' => $offer['client']['name'] ?? null,
                        'client_email' => $offer['client']['email'] ?? null,
                        'robaws_data' => $offer,
                        'auto_created' => true,
                        'created_from_document' => false, // Since this is from intake
                    ]
                );
            } catch (\Exception $e) {
                // Log the error but don't fail the whole process
                Log::warning('Failed to save quotation to local database', [
                    'offer_id' => $offer['id'] ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to save Robaws offer reference', [
                'offer_id' => $offer['id'] ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }
}
