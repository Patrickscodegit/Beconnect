<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Quotation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            
            // Attach the document file to the offer if it exists
            $this->attachDocumentToOffer($offer['id'], $document);
            
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
        // Format the extracted data as readable text instead of JSON
        $formattedText = $this->formatExtractedDataAsText($extractedData);
        
        // Build line items from extracted data
        $lineItems = $this->buildLineItems($extractedData);
        
        return [
            'clientId' => $clientId,
            'name' => $this->generateOfferTitle($extractedData),
            'currency' => $extractedData['invoice']['currency'] ?? $extractedData['currency'] ?? 'EUR',
            'status' => 'DRAFT',
            
            // Push formatted text into the custom "Extracted Information" field
            'extraFields' => [
                'Extracted Information' => ['stringValue' => $formattedText],
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
     * Format extracted data as readable text instead of JSON
     */
    private function formatExtractedDataAsText(array $data): string
    {
        $output = [];
        
        // Header
        $output[] = "=== AI EXTRACTED SHIPPING DATA ===";
        $output[] = "Generated: " . ($data['extracted_at'] ?? date('Y-m-d H:i:s'));
        $output[] = "Source: " . ($data['extraction_source'] ?? 'AI Extraction');
        $output[] = "";
        
        // Contact Information
        if (isset($data['consignee']) || isset($data['client_name'])) {
            $output[] = "CONTACT INFORMATION";
            $output[] = "==================";
            
            $name = $data['consignee']['name'] ?? $data['client_name'] ?? '';
            $phone = $data['consignee']['contact'] ?? $data['client_phone'] ?? '';
            $email = $data['consignee']['email'] ?? $data['client_email'] ?? '';
            $address = $data['consignee']['address'] ?? '';
            
            if ($name) $output[] = "Name: " . $name;
            if ($phone) $output[] = "Phone: " . $phone;
            if ($email) $output[] = "Email: " . $email;
            if ($address) $output[] = "Address: " . $address;
            $output[] = "";
        }
        
        // Shipping Details
        if (isset($data['ports']) || isset($data['port_of_loading'])) {
            $output[] = "SHIPPING DETAILS";
            $output[] = "================";
            
            $origin = $data['ports']['origin'] ?? $data['port_of_loading'] ?? '';
            $destination = $data['ports']['destination'] ?? $data['port_of_discharge'] ?? '';
            
            if ($origin) $output[] = "Origin: " . $origin;
            if ($destination) $output[] = "Destination: " . $destination;
            
            if (!empty($data['shipment_type'])) {
                $output[] = "Shipment Type: " . $data['shipment_type'];
            }
            $output[] = "";
        }
        
        // Vehicle/Cargo Information
        // Check multiple possible vehicle data structures
        $vehicle = null;
        if (isset($data['original_extraction']['vehicle'])) {
            $vehicle = $data['original_extraction']['vehicle'];
        } elseif (isset($data['original_extraction']['shipment']['vehicle'])) {
            $vehicle = $data['original_extraction']['shipment']['vehicle'];
        } elseif (isset($data['original_extraction']['vehicle_details'])) {
            $vehicle = $data['original_extraction']['vehicle_details'];
        }
        
        if ($vehicle) {
            $output[] = "VEHICLE INFORMATION";
            $output[] = "===================";
            
            // Basic vehicle information (only show non-empty values)
            if (!empty($vehicle['brand']) && trim($vehicle['brand']) !== '') $output[] = "Brand: " . $vehicle['brand'];
            if (!empty($vehicle['full_name']) && trim($vehicle['full_name']) !== '') {
                $output[] = "Vehicle: " . $vehicle['full_name'];
            } else {
                if (!empty($vehicle['make']) && trim($vehicle['make']) !== '') $output[] = "Make: " . $vehicle['make'];
                if (!empty($vehicle['model']) && trim($vehicle['model']) !== '') $output[] = "Model: " . $vehicle['model'];
            }
            if (!empty($vehicle['year']) && trim($vehicle['year']) !== '') $output[] = "Year: " . $vehicle['year'];
            if (!empty($vehicle['type']) && trim($vehicle['type']) !== '') $output[] = "Type: " . $vehicle['type'];
            if (!empty($vehicle['condition']) && trim($vehicle['condition']) !== '') $output[] = "Condition: " . $vehicle['condition'];
            if (!empty($vehicle['color']) && trim($vehicle['color']) !== '') $output[] = "Color: " . $vehicle['color'];
            if (!empty($vehicle['vin']) && trim($vehicle['vin']) !== '') $output[] = "VIN: " . $vehicle['vin'];
            if (!empty($vehicle['specifications']) && trim($vehicle['specifications']) !== '') $output[] = "Specifications: " . $vehicle['specifications'];
            
            // Enhanced dimensions display (European format)
            if (isset($vehicle['dimensions']) && is_array($vehicle['dimensions'])) {
                $dims = $vehicle['dimensions'];
                // Only show dimensions if all three values are present
                if (!empty($dims['length_m']) && !empty($dims['width_m']) && !empty($dims['height_m'])) {
                    $length = str_replace('.', ',', $dims['length_m']);
                    $width = str_replace('.', ',', $dims['width_m']);
                    $height = str_replace('.', ',', $dims['height_m']);
                    $output[] = "Dimensions: LxWxH = {$length} x {$width} x {$height}m";
                }
                // Show wheelbase if available
                if (!empty($dims['wheelbase_m'])) {
                    $wheelbase = str_replace('.', ',', $dims['wheelbase_m']);
                    $output[] = "Wheelbase: {$wheelbase}m";
                }
            }
            
            // Enhanced weight display (European format)
            if (!empty($vehicle['weight_kg'])) {
                $weight = number_format($vehicle['weight_kg'], 0, ',', '.');
                $output[] = "Weight: {$weight} kg";
            }
            
            // Enhanced fuel type display
            if (!empty($vehicle['fuel_type'])) {
                $output[] = "Fuel: " . $vehicle['fuel_type'];
            }
            
            // Enhanced engine display
            if (!empty($vehicle['engine_cc'])) {
                $engine = number_format($vehicle['engine_cc'], 0, ',', '.');
                $output[] = "Engine: {$engine} cc";
            }
            
            if (!empty($vehicle['price'])) $output[] = "Price: " . $vehicle['price'];
            if (!empty($vehicle['details'])) $output[] = "Details: " . $vehicle['details'];
            $output[] = "";
        }
        
        // Pricing Information
        if (isset($data['charges']) || isset($data['original_extraction']['pricing'])) {
            $output[] = "PRICING INFORMATION";
            $output[] = "===================";
            
            // From charges array
            if (isset($data['charges']) && is_array($data['charges'])) {
                foreach ($data['charges'] as $charge) {
                    $desc = $charge['description'] ?? 'Service';
                    $amount = $charge['amount'] ?? 0;
                    $currency = $charge['currency'] ?? 'EUR';
                    $output[] = "$desc: $amount $currency";
                }
            }
            
            // From original extraction pricing
            if (isset($data['original_extraction']['pricing'])) {
                $pricing = $data['original_extraction']['pricing'];
                if (!empty($pricing['amount'])) {
                    $output[] = "Quoted Amount: " . $pricing['amount'];
                }
                if (!empty($pricing['notes'])) {
                    $output[] = "Pricing Notes: " . $pricing['notes'];
                }
            }
            $output[] = "";
        }
        
        // Messages/Communication
        if (isset($data['original_extraction']['extracted_text'])) {
            $output[] = "ORIGINAL MESSAGE";
            $output[] = "================";
            $output[] = $data['original_extraction']['extracted_text'];
            $output[] = "";
        }
        
        // Invoice Information
        if (isset($data['invoice'])) {
            $output[] = "INVOICE DETAILS";
            $output[] = "===============";
            $invoice = $data['invoice'];
            
            if (!empty($invoice['number'])) $output[] = "Invoice Number: " . $invoice['number'];
            if (!empty($invoice['date'])) $output[] = "Date: " . $invoice['date'];
            if (!empty($invoice['currency'])) $output[] = "Currency: " . $invoice['currency'];
            $output[] = "";
        }
        
        // Metadata
        if (isset($data['original_extraction']['metadata'])) {
            $output[] = "EXTRACTION METADATA";
            $output[] = "===================";
            $metadata = $data['original_extraction']['metadata'];
            
            if (!empty($metadata['confidence'])) {
                $confidence = round($metadata['confidence'] * 100, 1);
                $output[] = "AI Confidence: {$confidence}%";
            }
            if (!empty($metadata['service_used'])) $output[] = "Service Used: " . $metadata['service_used'];
            if (!empty($metadata['processed_at'])) $output[] = "Processed At: " . $metadata['processed_at'];
        }
        
        return implode("\n", $output);
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
    
    /**
     * Attach document file to Robaws offer
     */
    protected function attachDocumentToOffer(int $offerId, Document $document): void
    {
        try {
            // Get the file from storage
            $filePath = $document->path ?? $document->file_path;
            if (!$filePath) {
                Log::warning('No file path found for document', ['document_id' => $document->id]);
                return;
            }

            $disk = Storage::disk($document->disk ?? config('filesystems.default', 'local'));
            
            if (!$disk->exists($filePath)) {
                Log::warning('File not found in storage', [
                    'document_id' => $document->id,
                    'file_path' => $filePath,
                    'disk' => $document->disk
                ]);
                return;
            }

            // Get file details
            $filename = $document->filename ?? basename($filePath);
            $mimeType = $document->mime_type ?? $disk->mimeType($filePath) ?? 'application/octet-stream';
            $fileSize = $disk->size($filePath);

            Log::info('Attaching document to Robaws offer', [
                'offer_id' => $offerId,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
            ]);

            // For files <= 6MB, use direct upload
            if ($fileSize <= 6 * 1024 * 1024) {
                // Use base64 upload for now (more reliable)
                $content = $disk->get($filePath);
                $uploadResult = $this->robawsClient->addOfferDocument($offerId, $filename, $mimeType, $content);

                Log::info('Document uploaded to Robaws offer', [
                    'offer_id' => $offerId,
                    'document_id' => $uploadResult['id'] ?? null,
                    'filename' => $filename,
                ]);
            } else {
                // For files > 6MB, use chunked upload
                $this->uploadLargeDocument($offerId, $disk, $filePath, $filename, $mimeType, $fileSize);
            }

        } catch (\Exception $e) {
            // Log the error but don't fail the entire export
            Log::error('Failed to attach document to Robaws offer', [
                'offer_id' => $offerId,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
            
            // Don't throw - we want the offer creation to succeed even if file upload fails
        }
    }

    /**
     * Upload large document using chunked upload
     */
    protected function uploadLargeDocument(int $offerId, $disk, string $filePath, string $filename, string $mimeType, int $fileSize): void
    {
        // Create upload session
        $session = $this->robawsClient->createOfferDocumentUploadSession($offerId, $filename, $mimeType, $fileSize);
        $sessionId = $session['id'];

        Log::info('Created document upload session', [
            'session_id' => $sessionId,
            'offer_id' => $offerId,
            'file_size' => $fileSize,
        ]);

        // Read and upload file in chunks of 6MB
        $chunkSize = 6 * 1024 * 1024; // 6MB
        $handle = $disk->readStream($filePath);
        $partNumber = 0;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false || strlen($chunk) === 0) {
                    break;
                }

                $base64Chunk = base64_encode($chunk);
                $result = $this->robawsClient->uploadDocumentChunk($sessionId, $base64Chunk, $partNumber);

                Log::info('Uploaded document chunk', [
                    'session_id' => $sessionId,
                    'part_number' => $partNumber,
                    'chunk_size' => strlen($chunk),
                ]);

                $partNumber++;
            }

            Log::info('Large document upload completed', [
                'offer_id' => $offerId,
                'filename' => $filename,
                'total_parts' => $partNumber,
            ]);

        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}