<?php

namespace App\Services\Robaws;

use App\Models\RobawsCustomerCache;
use App\Services\Export\Clients\RobawsApiClient;
use App\Support\CustomerNormalizer;
use Illuminate\Support\Facades\Log;

class RobawsCustomerSyncService
{
    protected RobawsApiClient $apiClient;
    protected CustomerNormalizer $normalizer;
    
    public function __construct(RobawsApiClient $apiClient, CustomerNormalizer $normalizer)
    {
        $this->apiClient = $apiClient;
        $this->normalizer = $normalizer;
    }
    
    /**
     * Sync all customers from Robaws
     */
    public function syncAllCustomers(bool $fullSync = false, bool $dryRun = false, ?int $limit = null): array
    {
        $stats = [
            'total_fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'sample_data' => [], // For dry-run inspection
        ];
        
        try {
            $page = 0;
            $size = 100; // Robaws max
            
            do {
                Log::info('Fetching customers page', ['page' => $page, 'size' => $size]);
                
                // Use existing RobawsApiClient::listClients method
                $response = $this->apiClient->listClients($page, $size);
                
                $customers = $response['items'] ?? [];
                $stats['total_fetched'] += count($customers);
                
                foreach ($customers as $customerData) {
                    try {
                        if ($dryRun) {
                            // Store first 10 samples for inspection
                            if (count($stats['sample_data']) < 10) {
                                $stats['sample_data'][] = [
                                    'id' => $customerData['id'] ?? 'unknown',
                                    'name' => $customerData['name'] ?? 'unknown',
                                    'structure' => $customerData, // Full structure
                                    'extracted_role' => $this->extractRole($customerData),
                                ];
                            }
                            $stats['skipped']++;
                        } else {
                            $customer = $this->processCustomer($customerData, $fullSync);
                            
                            if ($customer->wasRecentlyCreated) {
                                $stats['created']++;
                            } else {
                                $stats['updated']++;
                            }
                        }
                        
                    } catch (\Exception $e) {
                        Log::error('Failed to process customer', [
                            'customer_id' => $customerData['id'] ?? 'unknown',
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
                
            } while (count($customers) === $size && $stats['total_fetched'] < $totalItems);
            
            Log::info('Customer sync completed', $stats);
            
        } catch (\Exception $e) {
            Log::error('Customer sync failed', ['error' => $e->getMessage()]);
            throw $e;
        }
        
        return $stats;
    }
    
    /**
     * Process individual customer data
     */
    public function processCustomer(array $customerData, bool $fullSync = false): RobawsCustomerCache
    {
        $clientId = $customerData['id'];
        
        // Extract role from custom fields (like article's parent_item)
        $role = $this->extractRole($customerData);
        
        // Extract address fields safely
        $address = $customerData['address'] ?? [];
        $addressLine = is_array($address) ? ($address['addressLine1'] ?? null) : null;
        $city = is_array($address) ? ($address['city'] ?? null) : null;
        $postalCode = is_array($address) ? ($address['postalCode'] ?? null) : null;
        $country = is_array($address) ? ($address['country'] ?? null) : null;
        
        // Normalize phone numbers using CustomerNormalizer (for +32 prefix, etc.)
        try {
            $normalized = $this->normalizer->normalize($customerData);
            $normalizedPhone = $normalized['phone'] ?? null;
            $normalizedMobile = $normalized['mobile'] ?? null;
            $normalizedVat = $normalized['vat'] ?? null;
        } catch (\Exception $e) {
            // Fallback if normalizer fails
            $normalizedPhone = $customerData['tel'] ?? null;
            $normalizedMobile = $customerData['gsm'] ?? null;
            $normalizedVat = $customerData['vatNumber'] ?? null;
        }
        
        // Build normalized data array
        $normalizedData = [
            'robaws_client_id' => (string)$clientId,
            'name' => $customerData['name'] ?? 'Unknown',
            'role' => $role,
            'email' => $customerData['email'] ?? null,
            'phone' => $normalizedPhone,
            'mobile' => $normalizedMobile,
            'address' => $addressLine,
            'street' => $customerData['street'] ?? $addressLine ?? null,
            'street_number' => $customerData['streetNumber'] ?? null,
            'city' => $city,
            'postal_code' => $postalCode,
            'country' => $country,
            'country_code' => $customerData['countryCode'] ?? null,
            'vat_number' => $normalizedVat,
            'website' => $customerData['website'] ?? null,
            'language' => $customerData['language'] ?? null,
            'currency' => $customerData['currency'] ?? 'EUR',
            'client_type' => $customerData['clientType'] ?? 'company',
            'is_active' => $customerData['isActive'] ?? $customerData['is_active'] ?? true,
            'metadata' => $customerData, // Store full Robaws data
            'last_synced_at' => now(),
        ];
        
        return RobawsCustomerCache::updateOrCreate(
            ['robaws_client_id' => $clientId],
            $normalizedData
        );
    }
    
    /**
     * Extract role from customer custom fields
     * Similar to how we extract "Parent Item" from article custom fields
     * 
     * From dry-run: Role is in extraFields["Role"]["stringValue"]
     */
    protected function extractRole(array $customerData): ?string
    {
        // Try extraFields first (most common format from API)
        if (isset($customerData['extraFields'])) {
            // Direct check for "Role" field
            if (isset($customerData['extraFields']['Role']['stringValue'])) {
                return strtoupper(trim($customerData['extraFields']['Role']['stringValue']));
            }
            
            // Fallback: search through all extraFields
            foreach ($customerData['extraFields'] as $fieldName => $fieldData) {
                // Check if field name is exactly "Role" or contains "role"
                if (strcasecmp($fieldName, 'Role') === 0 || stripos($fieldName, 'role') !== false) {
                    $roleValue = $fieldData['stringValue'] ?? $fieldData['value'] ?? $fieldData['textValue'] ?? null;
                    if ($roleValue) {
                        return strtoupper(trim($roleValue));
                    }
                }
            }
        }
        
        // Try custom_fields format (API format, less common)
        if (isset($customerData['custom_fields'])) {
            // Try direct key
            if (isset($customerData['custom_fields']['role'])) {
                return strtoupper(trim($customerData['custom_fields']['role']));
            }
            
            // Try searching through custom fields array
            foreach ($customerData['custom_fields'] as $key => $value) {
                // Check if field name contains 'role'
                if (is_array($value) && isset($value['name']) && stripos($value['name'], 'role') !== false) {
                    $roleValue = $value['value'] ?? $value['textValue'] ?? $value['stringValue'] ?? null;
                    if ($roleValue) {
                        return strtoupper(trim($roleValue));
                    }
                }
            }
        }
        
        // Fallback: check clientType
        if (isset($customerData['clientType'])) {
            return strtoupper(trim($customerData['clientType']));
        }
        
        return null;
    }
    
    /**
     * Process customer from webhook
     */
    public function processCustomerFromWebhook(array $webhookData): RobawsCustomerCache
    {
        $customerData = $webhookData['data'] ?? $webhookData;
        
        return $this->processCustomer($customerData, false);
    }
    
    /**
     * Sync single customer by ID
     */
    public function syncSingleCustomer(string $clientId): RobawsCustomerCache
    {
        try {
            $customerData = $this->apiClient->getClientById($clientId);
            return $this->processCustomer($customerData, false);
        } catch (\Exception $e) {
            Log::error('Failed to sync single customer', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Push customer updates back to Robaws (bi-directional sync)
     */
    public function pushCustomerToRobaws(RobawsCustomerCache $customer): bool
    {
        try {
            $updateData = array_filter([
                'name' => $customer->name,
                'email' => $customer->email,
                'tel' => $customer->phone,
                'gsm' => $customer->mobile,
                'vatNumber' => $customer->vat_number,
                'website' => $customer->website,
                'language' => $customer->language,
                'currency' => $customer->currency,
                'address' => array_filter([
                    'street' => $customer->street,
                    'streetNumber' => $customer->street_number,
                    'postalCode' => $customer->postal_code,
                    'city' => $customer->city,
                    'country' => $customer->country,
                    'countryCode' => $customer->country_code,
                ]),
            ], fn($v) => $v !== null && $v !== '' && $v !== []);
            
            $result = $this->apiClient->updateClient(
                (int)$customer->robaws_client_id,
                $updateData
            );
            
            if ($result) {
                $customer->update(['last_pushed_to_robaws_at' => now()]);
                
                Log::info('Pushed customer to Robaws', [
                    'customer_id' => $customer->id,
                    'robaws_client_id' => $customer->robaws_client_id,
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('Failed to push customer to Robaws', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Push all pending customer updates to Robaws
     */
    public function pushAllPendingUpdates(): array
    {
        $stats = ['pushed' => 0, 'failed' => 0];
        
        // Find customers updated locally but not yet pushed
        $customers = RobawsCustomerCache::where('updated_at', '>', 'last_pushed_to_robaws_at')
            ->orWhereNull('last_pushed_to_robaws_at')
            ->where('is_active', true)
            ->get();
        
        foreach ($customers as $customer) {
            if ($this->pushCustomerToRobaws($customer)) {
                $stats['pushed']++;
            } else {
                $stats['failed']++;
            }
        }
        
        return $stats;
    }

    /**
     * Delete a customer from Robaws using the DELETE API endpoint
     * Uses the official Robaws DELETE /clients/{id} endpoint
     */
    public function deleteCustomerFromRobaws(string $robawsClientId): bool
    {
        try {
            // Use the official Robaws DELETE endpoint
            $response = $this->apiClient->deleteClient((int) $robawsClientId);

            if ($response) {
                Log::info('Customer deleted from Robaws', [
                    'robaws_client_id' => $robawsClientId,
                ]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to delete customer from Robaws', [
                'robaws_client_id' => $robawsClientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

