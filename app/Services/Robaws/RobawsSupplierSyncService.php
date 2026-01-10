<?php

namespace App\Services\Robaws;

use App\Models\RobawsSupplierCache;
use App\Models\RobawsSupplierContactCache;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

class RobawsSupplierSyncService
{
    protected RobawsApiClient $apiClient;
    
    public function __construct(RobawsApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }
    
    /**
     * Sync all suppliers from Robaws
     */
    public function syncAllSuppliers(bool $fullSync = false, bool $dryRun = false, ?int $limit = null, bool $includeContacts = false): array
    {
        $stats = [
            'total_fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'contacts_synced' => 0,
            'sample_data' => [], // For dry-run inspection
        ];
        
        try {
            $page = 0;
            $size = 100; // Robaws max
            
            do {
                Log::info('Fetching suppliers page', ['page' => $page, 'size' => $size]);
                
                // Use RobawsApiClient::listSuppliers method
                $response = $this->apiClient->listSuppliers($page, $size);
                
                $suppliers = $response['items'] ?? [];
                $stats['total_fetched'] += count($suppliers);
                
                foreach ($suppliers as $supplierData) {
                    try {
                        if ($dryRun) {
                            // Store first 10 samples for inspection
                            if (count($stats['sample_data']) < 10) {
                                $stats['sample_data'][] = [
                                    'id' => $supplierData['id'] ?? 'unknown',
                                    'name' => $supplierData['name'] ?? 'unknown',
                                    'structure' => $supplierData, // Full structure
                                    'extracted_type' => $this->extractSupplierType($supplierData),
                                    'extracted_code' => $this->extractCode($supplierData),
                                ];
                            }
                            $stats['skipped']++;
                        } else {
                            $supplier = $this->processSupplier($supplierData, $fullSync);
                            
                            if ($supplier->wasRecentlyCreated) {
                                $stats['created']++;
                            } else {
                                $stats['updated']++;
                            }
                            
                            // Sync contacts if requested (requires individual API call)
                            if ($includeContacts) {
                                try {
                                    $supplierWithContacts = $this->apiClient->getSupplier($supplierData['id'], ['contacts']);
                                    if ($supplierWithContacts && isset($supplierWithContacts['contacts']) && is_array($supplierWithContacts['contacts'])) {
                                        $this->syncSupplierContacts($supplierData['id'], $supplierWithContacts['contacts']);
                                        $stats['contacts_synced'] += count($supplierWithContacts['contacts']);
                                    }
                                } catch (\Exception $e) {
                                    Log::warning('Failed to sync contacts for supplier', [
                                        'supplier_id' => $supplierData['id'],
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                        
                    } catch (\Exception $e) {
                        Log::error('Failed to process supplier', [
                            'supplier_id' => $supplierData['id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                    }
                    
                    // Respect limit for testing
                    if ($limit && $stats['total_fetched'] >= $limit) {
                        break 2; // Break outer loop
                    }
                }
                
                $page++;
                $totalItems = (int)($response['totalItems'] ?? 0);
                
            } while (count($suppliers) === $size && $stats['total_fetched'] < $totalItems);
            
            Log::info('Supplier sync completed', $stats);
            
        } catch (\Exception $e) {
            Log::error('Supplier sync failed', ['error' => $e->getMessage()]);
            throw $e;
        }
        
        return $stats;
    }
    
    /**
     * Process individual supplier data
     */
    public function processSupplier(array $supplierData, bool $fullSync = false): RobawsSupplierCache
    {
        $supplierId = $supplierData['id'];
        
        // Extract supplier type and code from custom fields
        $supplierType = $this->extractSupplierType($supplierData);
        $code = $this->extractCode($supplierData);
        
        // Extract address fields safely - try invoiceAddress first, then address
        $address = $supplierData['invoiceAddress'] ?? $supplierData['address'] ?? [];
        $addressLine = is_array($address) ? ($address['addressLine1'] ?? $address['addressLine'] ?? null) : null;
        $city = is_array($address) ? ($address['city'] ?? null) : null;
        $postalCode = is_array($address) ? ($address['postalCode'] ?? $address['postal_code'] ?? null) : null;
        $country = is_array($address) ? ($address['country'] ?? null) : null;
        
        // Build normalized data array
        $normalizedData = [
            'robaws_supplier_id' => (string)$supplierId,
            'name' => $supplierData['name'] ?? 'Unknown',
            'code' => $code,
            'supplier_type' => $supplierType,
            'email' => $supplierData['email'] ?? null,
            'phone' => $supplierData['tel'] ?? $supplierData['phone'] ?? null,
            'mobile' => $supplierData['gsm'] ?? $supplierData['mobile'] ?? null,
            'address' => $addressLine,
            'street' => $supplierData['street'] ?? $addressLine ?? null,
            'street_number' => $supplierData['streetNumber'] ?? null,
            'city' => $city,
            'postal_code' => $postalCode,
            'country' => $country,
            'country_code' => $supplierData['countryCode'] ?? null,
            'vat_number' => $supplierData['vatIdNumber'] ?? $supplierData['vatNumber'] ?? null,
            'website' => $supplierData['website'] ?? null,
            'language' => $supplierData['language'] ?? null,
            'currency' => $supplierData['currency'] ?? 'EUR',
            'supplier_category' => $supplierData['supplierCategory'] ?? $supplierData['category'] ?? 'company',
            'is_active' => $supplierData['isActive'] ?? $supplierData['is_active'] ?? true,
            'metadata' => $supplierData, // Store full Robaws data
            'last_synced_at' => now(),
        ];
        
        return RobawsSupplierCache::updateOrCreate(
            ['robaws_supplier_id' => $supplierId],
            $normalizedData
        );
    }
    
    /**
     * Sync single supplier by ID
     */
    public function syncSingleSupplier(string $supplierId, bool $includeContacts = true): RobawsSupplierCache
    {
        $include = $includeContacts ? ['contacts'] : [];
        $supplierData = $this->apiClient->getSupplier($supplierId, $include);
        
        if (!$supplierData) {
            throw new \Exception("Supplier {$supplierId} not found in Robaws");
        }
        
        $supplier = $this->processSupplier($supplierData, true);
        
        // Sync contacts if included
        if ($includeContacts && isset($supplierData['contacts']) && is_array($supplierData['contacts'])) {
            $this->syncSupplierContacts($supplierId, $supplierData['contacts']);
        }
        
        return $supplier;
    }
    
    /**
     * Extract supplier type from supplier custom fields
     * Similar to how we extract "Role" from customer custom fields
     */
    protected function extractSupplierType(array $supplierData): ?string
    {
        // Try direct type field first
        if (isset($supplierData['type']) && !empty($supplierData['type'])) {
            return strtolower(trim($supplierData['type']));
        }
        
        // Try extraFields (most common format from API)
        if (isset($supplierData['extraFields'])) {
            // Direct check for "Supplier Type" or "Type" field
            if (isset($supplierData['extraFields']['Supplier Type']['stringValue'])) {
                return strtolower(trim($supplierData['extraFields']['Supplier Type']['stringValue']));
            }
            if (isset($supplierData['extraFields']['Type']['stringValue'])) {
                return strtolower(trim($supplierData['extraFields']['Type']['stringValue']));
            }
            
            // Fallback: search through all extraFields
            foreach ($supplierData['extraFields'] as $fieldName => $fieldData) {
                if (stripos($fieldName, 'type') !== false || stripos($fieldName, 'supplier type') !== false) {
                    $typeValue = $fieldData['stringValue'] ?? $fieldData['value'] ?? $fieldData['textValue'] ?? null;
                    if ($typeValue) {
                        return strtolower(trim($typeValue));
                    }
                }
            }
        }
        
        // Try custom_fields format
        if (isset($supplierData['custom_fields'])) {
            if (isset($supplierData['custom_fields']['supplier_type'])) {
                return strtolower(trim($supplierData['custom_fields']['supplier_type']));
            }
            if (isset($supplierData['custom_fields']['type'])) {
                return strtolower(trim($supplierData['custom_fields']['type']));
            }
        }
        
        // Try supplierType field
        if (isset($supplierData['supplierType'])) {
            return strtolower(trim($supplierData['supplierType']));
        }
        
        // Infer from name patterns (e.g., "RORO Bahri" suggests shipping_line)
        $name = $supplierData['name'] ?? '';
        if (stripos($name, 'RORO') !== false || stripos($name, 'shipping') !== false || stripos($name, 'line') !== false) {
            return 'shipping_line';
        }
        if (stripos($name, 'forwarder') !== false) {
            return 'forwarder';
        }
        if (stripos($name, 'broker') !== false) {
            return 'broker';
        }
        
        return null;
    }
    
    /**
     * Extract supplier code from supplier data
     */
    protected function extractCode(array $supplierData): ?string
    {
        // Try direct code field
        if (isset($supplierData['code']) && !empty($supplierData['code'])) {
            return strtoupper(trim($supplierData['code']));
        }
        
        // Try extraFields
        if (isset($supplierData['extraFields'])) {
            if (isset($supplierData['extraFields']['Code']['stringValue'])) {
                return strtoupper(trim($supplierData['extraFields']['Code']['stringValue']));
            }
            
            foreach ($supplierData['extraFields'] as $fieldName => $fieldData) {
                if (stripos($fieldName, 'code') !== false) {
                    $codeValue = $fieldData['stringValue'] ?? $fieldData['value'] ?? $fieldData['textValue'] ?? null;
                    if ($codeValue) {
                        return strtoupper(trim($codeValue));
                    }
                }
            }
        }
        
        // Extract code from name pattern: "NUMBER - Name" or "NUMBER Name"
        $name = $supplierData['name'] ?? '';
        if (preg_match('/^(\d+)\s*-\s*(.+)/', $name, $matches)) {
            // Pattern: "1227 - Katoennatie" -> code is "1227"
            return strtoupper(trim($matches[1]));
        }
        if (preg_match('/^(\d+)\s+(.+)/', $name, $matches)) {
            // Pattern: "1227 Katoennatie" -> code is "1227"
            return strtoupper(trim($matches[1]));
        }
        
        return null;
    }
    
    /**
     * Process supplier from webhook event
     */
    public function processSupplierFromWebhook(array $webhookData): RobawsSupplierCache
    {
        $event = $webhookData['event'] ?? null;
        $supplierData = $webhookData['data'] ?? $webhookData;
        
        if (!$supplierData || !isset($supplierData['id'])) {
            throw new \InvalidArgumentException('Invalid webhook data: missing supplier ID');
        }
        
        Log::info('Processing supplier from webhook', [
            'event' => $event,
            'supplier_id' => $supplierData['id'],
        ]);
        
        return $this->processSupplier($supplierData, true);
    }
    
    /**
     * Push supplier to Robaws (bi-directional sync)
     */
    public function pushSupplierToRobaws(RobawsSupplierCache $supplier): bool
    {
        try {
            $supplierData = $this->toRobawsSupplierPayload($supplier);
            
            if ($supplier->robaws_supplier_id) {
                // Update existing
                $this->apiClient->updateSupplier($supplier->robaws_supplier_id, $supplierData);
            } else {
                // Create new
                $result = $this->apiClient->createSupplier($supplierData);
                if ($result && isset($result['id'])) {
                    $supplier->update(['robaws_supplier_id' => $result['id']]);
                }
            }
            
            $supplier->update(['last_pushed_to_robaws_at' => now()]);
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to push supplier to Robaws', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Convert supplier model to Robaws API payload
     */
    protected function toRobawsSupplierPayload(RobawsSupplierCache $supplier): array
    {
        return [
            'name' => $supplier->name,
            'code' => $supplier->code,
            'email' => $supplier->email,
            'tel' => $supplier->phone,
            'gsm' => $supplier->mobile,
            'website' => $supplier->website,
            'vatNumber' => $supplier->vat_number,
            'currency' => $supplier->currency,
            'language' => $supplier->language,
            'address' => [
                'addressLine1' => $supplier->address,
                'city' => $supplier->city,
                'postalCode' => $supplier->postal_code,
                'country' => $supplier->country,
            ],
        ];
    }
    
    /**
     * Push all pending supplier updates to Robaws
     */
    public function pushAllPendingUpdates(): array
    {
        $stats = ['pushed' => 0, 'failed' => 0];
        
        // Find suppliers that have been updated locally but not pushed
        $suppliers = RobawsSupplierCache::where(function ($query) {
            $query->whereNull('last_pushed_to_robaws_at')
                  ->orWhereColumn('updated_at', '>', 'last_pushed_to_robaws_at');
        })->get();
        
        foreach ($suppliers as $supplier) {
            if ($this->pushSupplierToRobaws($supplier)) {
                $stats['pushed']++;
            } else {
                $stats['failed']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Sync supplier contacts
     */
    public function syncSupplierContacts(string $supplierId, array $contacts): void
    {
        foreach ($contacts as $contactData) {
            try {
                $this->processContact($contactData, $supplierId);
            } catch (\Exception $e) {
                Log::error('Failed to process supplier contact', [
                    'supplier_id' => $supplierId,
                    'contact_id' => $contactData['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
    
    /**
     * Process individual contact data
     */
    public function processContact(array $contactData, string $supplierId): RobawsSupplierContactCache
    {
        $contactId = $contactData['id'];
        
        // Build normalized contact data
        $normalizedData = [
            'robaws_contact_id' => (string)$contactId,
            'robaws_supplier_id' => $supplierId,
            'name' => $contactData['name'] ?? null,
            'surname' => $contactData['surname'] ?? null,
            'email' => $contactData['email'] ?? null,
            'phone' => $contactData['tel'] ?? $contactData['phone'] ?? null,
            'mobile' => $contactData['gsm'] ?? $contactData['mobile'] ?? null,
            'position' => $contactData['position'] ?? null,
            'title' => $contactData['title'] ?? null,
            'is_primary' => $contactData['isPrimary'] ?? $contactData['is_primary'] ?? false,
            'receives_quotes' => $contactData['receivesQuotes'] ?? $contactData['receives_quotes'] ?? false,
            'receives_invoices' => $contactData['receivesInvoices'] ?? $contactData['receives_invoices'] ?? false,
            'metadata' => $contactData, // Store full Robaws contact data
            'last_synced_at' => now(),
        ];
        
        // Full name will be set automatically by model boot method
        $parts = array_filter([$normalizedData['name'], $normalizedData['surname']]);
        $normalizedData['full_name'] = !empty($parts) ? implode(' ', $parts) : null;
        
        return RobawsSupplierContactCache::updateOrCreate(
            ['robaws_contact_id' => $contactId],
            $normalizedData
        );
    }
}
