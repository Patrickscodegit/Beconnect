<?php

namespace App\Services\Export\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class RobawsApiClient
{
    private ?PendingRequest $http = null;

    public function __construct()
    {
        // Lazy initialization - don't create HTTP client in constructor
        // This prevents errors during composer autoload discovery
    }

    private function getHttpClient(): PendingRequest
    {
        if ($this->http === null) {
            $baseUrl = rtrim(config('services.robaws.base_url'), '/');
            $apiKey = config('services.robaws.api_key');
            
            // Validate required configuration
            if (empty($apiKey)) {
                throw new \InvalidArgumentException('Robaws API key is not configured. Set ROBAWS_API_KEY in your .env file.');
            }
            
            if (empty($baseUrl)) {
                throw new \InvalidArgumentException('Robaws base URL is not configured. Set ROBAWS_BASE_URL in your .env file.');
            }

            $this->http = $this->createHttpClient($baseUrl, $apiKey);
        }

        return $this->http;
    }

    /** Search clients directly by email using contacts endpoint with include */
    public function findContactByEmail(string $email): ?array
    {
        try {
            // Use the fast contacts endpoint with include=client parameter
            $res = $this->getHttpClient()
                ->get('/api/v2/contacts', [
                    'email' => $email,
                    'include' => 'client',
                    'page' => 0,
                    'size' => 1
                ])
                ->throw()
                ->json();

            $contacts = $res['items'] ?? [];
            
            if (!empty($contacts)) {
                $contact = $contacts[0];
                // Return the client data if included, or fetch it separately
                if (!empty($contact['client'])) {
                    return $contact['client'];
                } elseif (!empty($contact['clientId'])) {
                    return $this->getClientById($contact['clientId']);
                }
            }
            
            // Fallback to direct client search if contacts endpoint doesn't work
            return $this->searchClientsByEmail($email);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Error using contacts endpoint, falling back to direct search', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);
            
            // Fallback to direct client search
            return $this->searchClientsByEmail($email);
        }
    }

    /** Fallback method to search clients directly by email */
    private function searchClientsByEmail(string $email): ?array
    {
        // Search through clients directly as fallback
        $page = 0;
        $size = 100;
        $maxPages = 10; // reasonable limit
        
        do {
            $res = $this->getHttpClient()
                ->get('/api/v2/clients', ['page' => $page, 'size' => $size, 'sort' => 'name:asc'])
                ->throw()
                ->json();

            $clients = $res['items'] ?? [];
            
            foreach ($clients as $client) {
                if (!empty($client['email']) && strcasecmp($client['email'], $email) === 0) {
                    return $client;
                }
            }
            
            $page++;
            $totalItems = (int)($res['totalItems'] ?? 0);
            
        } while ($page < $maxPages && $page * $size < $totalItems);

        return null;
    }

    /** Direct client search by email - alias for findContactByEmail for compatibility */
    public function findClientByEmail(string $email): ?array
    {
        return $this->findContactByEmail($email);
    }

    public function findClientByPhone(string $phone): ?array
    {
        // Search through clients directly since contacts endpoint doesn't have reliable phone data
        $page = 0;
        $size = 100;
        $maxPages = 20; // smaller limit for phone search since it's more computationally expensive
        
        do {
            $res = $this->getHttpClient()
                ->get('/api/v2/clients', ['page' => $page, 'size' => $size, 'sort' => 'name:asc'])
                ->throw()
                ->json();

            $clients = $res['items'] ?? [];
            
            foreach ($clients as $client) {
                if (!empty($client['tel'])) {
                    // Normalize phone numbers for comparison
                    $clientPhone = preg_replace('/\D+/', '', $client['tel']);
                    $searchPhone = preg_replace('/\D+/', '', $phone);
                    
                    if ($clientPhone === $searchPhone) {
                        return $client; // Return the client directly
                    }
                }
            }
            
            $page++;
            $totalItems = (int)($res['totalItems'] ?? 0);
            
        } while ($page < $maxPages && $page * $size < $totalItems);

        return null;
    }

    /** Paged slice of clients for local matching */
    public function listClients(int $page = 0, int $size = 100): array
    {
        return $this->getHttpClient()
            ->get('/api/v2/clients', ['page' => $page, 'size' => min($size, 100), 'sort' => 'name:asc'])
            ->throw()
            ->json();
    }

    public function getClientById(string $id, array $include = []): ?array
    {
        $res = $this->getHttpClient()
            ->get("/api/v2/clients/{$id}", ['include' => implode(',', $include)])
            ->throw()
            ->json();
        return $res ?: null;
    }

    /**
     * Backward compatibility method that internally uses v2-only ClientResolver
     * This ensures existing code continues to work with the unified approach
     */
    public function findClientId(?string $name, ?string $email, ?string $phone = null): ?int
    {
        $resolver = app(\App\Services\Robaws\ClientResolver::class);
        
        $hints = array_filter([
            'email' => $email,
            'phone' => $phone,
            'name' => $name,
        ]);
        
        $result = $resolver->resolve($hints);
        
        return $result ? (int)$result['id'] : null;
    }

    /**
     * Find or create a client in Robaws with enhanced customer data and contact person
     */
    public function findOrCreateClient(array $customerData): ?array
    {
        try {
            // First, try to find existing client by email or name
            $existingClient = $this->findClientByEmailOrName(
                $customerData['email'] ?? null,
                $customerData['name'] ?? null
            );

            if ($existingClient) {
                // Update existing client with new data if needed
                return $this->updateClient($existingClient['id'], $customerData);
            }

            // Create new client with enhanced data
            return $this->createClient($customerData);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error finding or creating Robaws client', [
                'error' => $e->getMessage(),
                'customer_data' => $customerData
            ]);
            return null;
        }
    }

    /**
     * Find client by email or name
     */
    protected function findClientByEmailOrName(?string $email, ?string $name): ?array
    {
        // Email first via contacts (faster + correct owner client)
        if ($email) {
            if ($client = $this->findClientByEmail($email)) {
                return $client;
            }
        }

        // Fallback: small page scan by name (or use server-side filter if your tenant supports it)
        if ($name) {
            $res = $this->getHttpClient()->get('/api/v2/clients', [
                'page' => 0, 'size' => 50, 'sort' => 'name:asc',
            ])->throw()->json();
            foreach (($res['items'] ?? []) as $c) {
                if (!empty($c['name']) && strcasecmp($c['name'], $name) === 0) {
                    return $c;
                }
            }
        }
        return null;
    }

    /**
     * Create a new client with enhanced customer data
     */
    public function createClient(array $customerData): ?array
    {
        try {
            $clientData = $this->toRobawsClientPayload($customerData, false);

            // Ensure minimum fields
            $clientData['name'] = $clientData['name'] ?? 'Unknown Customer';
            $clientData['email'] = $clientData['email'] ?? 'noreply@bconnect.com';

            $response = $this->getHttpClient()
                ->post('/api/v2/clients', $clientData)
                ->throw()
                ->json();
            
            if ($response && isset($response['id'])) {
                $clientId = $response['id'];
                
                \Illuminate\Support\Facades\Log::info('Created new Robaws client with enhanced data', [
                    'client_id' => $clientId,
                    'name' => $clientData['name'],
                    'client_type' => $clientData['clientType'] ?? null
                ]);
                
                // Create contact person separately if provided
                if (!empty($customerData['contact_person'])) {
                    $contactResult = $this->createContact($clientId, $customerData['contact_person']);
                    \Illuminate\Support\Facades\Log::info('Contact person creation result', [
                        'client_id' => $clientId,
                        'contact_created' => $contactResult ? 'YES' : 'NO',
                        'contact_id' => $contactResult['id'] ?? null
                    ]);
                }
                
                // Create additional contact persons if provided
                if (!empty($customerData['contact_persons']) && is_array($customerData['contact_persons'])) {
                    foreach ($customerData['contact_persons'] as $person) {
                        $this->createContact($clientId, $person);
                    }
                }
                
                return $response;
            }
            
            return null;
            
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create Robaws client', [
                'error' => $e->getMessage(),
                'data' => $customerData
            ]);
            
            // Try with minimal data if the first attempt fails
            return ($customerData['name'] ?? null)
                ? $this->safeCreateMinimal($customerData)
                : null;
        }
    }

    /**
     * Safe fallback client creation with minimal data
     */
    private function safeCreateMinimal(array $in): ?array
    {
        try {
            $resp = $this->getHttpClient()
                ->post('/api/v2/clients', [
                    'name' => $in['name'],
                    'email' => $in['email'] ?? 'noreply@bconnect.com',
                ])->throw()->json();
            return $resp ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map internal field names to Robaws API field names
     */
    private function toRobawsClientPayload(array $data, bool $isUpdate = false): array
    {
        // Prefer explicit API keys if caller already used them; otherwise map internal names.
        $payload = [
            'name'         => $data['name']         ?? $data['client_name'] ?? null,
            'email'        => $data['email']        ?? null,
            'tel'          => $data['tel']          ?? $data['phone']       ?? $data['telephone'] ?? null,
            'gsm'          => $data['gsm']          ?? $data['mobile']      ?? $data['cell']      ?? null,
            'vatNumber'    => $data['vatNumber']    ?? $data['vat_number']  ?? $data['vat']       ?? null,
            'companyNumber'=> $data['companyNumber']?? $data['company_number'] ?? null,
            'language'     => $data['language']     ?? null,
            'currency'     => $data['currency']     ?? null,
            'website'      => $data['website']      ?? null,
            'notes'        => $data['notes']        ?? null,
            'clientType'   => $data['clientType']   ?? $data['client_type'] ?? null, // 'company'|'individual' depending on your tenant
            'status'       => $data['status']       ?? null,
        ];

        // Address normalization
        $addr = [
            'street'       => $data['street']        ?? $data['address']['street']        ?? null,
            'streetNumber' => $data['street_number'] ?? $data['address']['street_number'] ?? null,
            'postalCode'   => $data['postal_code']   ?? $data['address']['postal_code']   ?? null,
            'city'         => $data['city']          ?? $data['address']['city']          ?? null,
            'country'      => $data['country']       ?? $data['address']['country']       ?? null,
            'countryCode'  => $data['country_code']  ?? $data['address']['country_code']  ?? null,
        ];
        $addr = array_filter($addr, fn($v) => $v !== null && $v !== '');
        if (!empty($addr)) $payload['address'] = $addr;

        // Note: Contact persons are now handled separately via createContact() method
        // This prevents issues with replacing existing contacts when updating clients

        // Remove empties; on PATCH we only want the provided fields
        $payload = array_filter($payload, fn($v) => $v !== null && $v !== '' && $v !== []);
        return $payload;
    }

    /**
     * Update existing client with new data
     */
    public function updateClient(int $clientId, array $customerData): ?array
    {
        try {
            $updateData = $this->toRobawsClientPayload($customerData, true);
            if (empty($updateData)) return ['id' => $clientId];

            // Use proper JSON Merge Patch content type as required by Robaws API
            $json = json_encode($updateData, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            
            $response = $this->getHttpClient()
                ->withHeaders(['Accept' => 'application/json'])
                ->withBody($json, 'application/merge-patch+json')
                ->patch("/api/v2/clients/{$clientId}")
                ->throw()
                ->json();

            \Illuminate\Support\Facades\Log::info('Updated Robaws client successfully', [
                'client_id' => $clientId,
                'updated_fields' => array_keys($updateData)
            ]);

            return $response ?? ['id' => $clientId];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error updating Robaws client', [
                'error' => $e->getMessage(),
                'client_id' => $clientId,
                'update_data' => $customerData,
            ]);
            return ['id' => $clientId];
        }
    }

    /**
     * Prepare contact person data for API
     */
    protected function prepareContactPerson($contactPerson): array
    {
        if (is_string($contactPerson)) {
            // Simple string name provided
            return [
                'name' => $contactPerson,
                'is_primary' => true,
            ];
        }

        // Full contact person data
        return array_filter([
            'name' => $contactPerson['name'] ?? 'Unknown',
            'first_name' => $contactPerson['first_name'] ?? null,
            'last_name' => $contactPerson['last_name'] ?? null,
            'email' => $contactPerson['email'] ?? null,
            'phone' => $contactPerson['phone'] ?? null,
            'mobile' => $contactPerson['mobile'] ?? null,
            'function' => $contactPerson['function'] ?? $contactPerson['title'] ?? null,
            'department' => $contactPerson['department'] ?? null,
            'is_primary' => $contactPerson['is_primary'] ?? false,
            'receives_invoices' => $contactPerson['receives_invoices'] ?? false,
            'receives_quotes' => $contactPerson['receives_quotes'] ?? true,
        ]);
    }

    /**
     * Prepare multiple contact persons
     */
    protected function prepareContactPersons(array $customerData): array
    {
        $contactPersons = [];

        // Add single contact person if provided
        if (!empty($customerData['contact_person'])) {
            $contactPerson = $this->prepareContactPerson($customerData['contact_person']);
            $contactPerson['is_primary'] = true; // First one is primary
            $contactPersons[] = $contactPerson;
        }

        // Add additional contact persons
        if (!empty($customerData['contact_persons']) && is_array($customerData['contact_persons'])) {
            foreach ($customerData['contact_persons'] as $index => $person) {
                $contactPerson = $this->prepareContactPerson($person);
                // Only first one is primary if no other primary contact exists
                if (empty($contactPersons) && $index === 0) {
                    $contactPerson['is_primary'] = true;
                }
                $contactPersons[] = $contactPerson;
            }
        }

        return $contactPersons;
    }

    /**
     * Create a new quotation/offer in Robaws
     */
    public function createQuotation(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = $this->getHttpClient()
                ->withHeaders($headers)
                ->post('/api/v2/offers', $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'quotation_id' => $data['id'] ?? null,
                    'data' => $data,
                    'idempotency_key' => $idempotencyKey,
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    /**
     * Update an existing quotation/offer in Robaws
     */
    public function updateQuotation(string $quotationId, array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = $this->getHttpClient()
                ->withHeaders($headers)
                ->put("/api/v2/offers/{$quotationId}", $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'quotation_id' => $quotationId,
                    'data' => $data,
                    'idempotency_key' => $idempotencyKey,
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    /**
     * Get an offer by ID
     */
    public function getOffer(string $offerId, array $include = []): array
    {
        try {
            $query = [];
            if (!empty($include)) {
                $query['include'] = implode(',', $include);  // e.g. 'client'
            }

            $response = $this->getHttpClient()->get("/api/v2/offers/{$offerId}", $query);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    /**
     * Attach a file to an offer
     */
    public function attachFileToOffer(int $offerId, string $filePath, ?string $filename = null): array
    {
        try {
            $filename = $filename ?: basename($filePath);
            
            // Determine MIME type - prefer message/rfc822 for .eml files
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeType = match($ext) {
                'eml' => 'message/rfc822',
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                default => mime_content_type($filePath) ?: 'application/octet-stream'
            };

            // Create a FRESH HTTP client for multipart upload (avoid JSON Content-Type)
            $baseUrl = rtrim(config('services.robaws.base_url'), '/');
            $http = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->asMultipart()
                ->timeout(config('services.robaws.timeout', 30));

            // Configure authentication the same way as the main client
            if (config('services.robaws.auth') === 'basic') {
                $username = config('services.robaws.username');
                $password = config('services.robaws.password');
                
                // Handle null values gracefully for CI environments
                if ($username !== null && $password !== null) {
                    $http = $http->withBasicAuth($username, $password);
                }
            } else {
                // Bearer token authentication
                $token = config('services.robaws.token') ?? config('services.robaws.api_key');
                if ($token) {
                    $http = $http->withToken($token);
                }
            }

            $response = $http->attach('file', $this->getFileContent($filePath), $filename, ['Content-Type' => $mimeType])
                ->post("/api/v2/offers/{$offerId}/documents");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    /**
     * Test connection to Robaws API
     */
    public function testConnection(): array
    {
        try {
            $response = $this->getHttpClient()->get('/api/v2/health');
            
            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'response_time' => $response->transferStats?->getTransferTime(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    /**
     * Validate API configuration
     */
    public function validateConfig(): array
    {
        $issues = [];
        
        if (empty(config('services.robaws.api_key'))) {
            $issues[] = 'API key not configured';
        }
        
        if (empty(config('services.robaws.base_url'))) {
            $issues[] = 'Base URL not configured';
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues,
        ];
    }

    private function createHttpClient(string $baseUrl, string $apiKey): PendingRequest
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Bconnect/1.0 (Laravel)',
            'X-Request-ID' => Str::uuid()->toString(),
        ];

        $http = Http::baseUrl($baseUrl)
            ->withHeaders($headers)
            ->timeout(config('services.robaws.timeout', 30))
            ->connectTimeout(config('services.robaws.connect_timeout', 10))
            ->withOptions([
                'verify' => config('services.robaws.verify_ssl', true),
            ]);

        // Configure authentication based on config
        if (config('services.robaws.auth') === 'basic') {
            $username = config('services.robaws.username');
            $password = config('services.robaws.password');
            
            // Handle null values gracefully for CI environments
            if ($username !== null && $password !== null) {
                $http = $http->withBasicAuth($username, $password);
            }
        } else {
            // Bearer token authentication
            $token = config('services.robaws.token') ?? $apiKey;
            if ($token) {
                $http = $http->withToken($token);
            }
        }

        return $http;
    }

    /**
     * Get file content using Storage facade for cloud compatibility
     * Enhanced with smart path resolution to handle various path formats
     */
    private function getFileContent(string $filePath): string
    {
        try {
            // Log the attempt for debugging
            \Illuminate\Support\Facades\Log::info('Attempting to retrieve file', [
                'original_path' => $filePath,
                'is_absolute' => str_starts_with($filePath, '/'),
                'file_exists_check' => file_exists($filePath) ? 'yes' : 'no'
            ]);
            
            // First, try direct file access if it's an absolute path
            if (str_starts_with($filePath, '/') && file_exists($filePath)) {
                \Illuminate\Support\Facades\Log::info('File found via direct access', [
                    'path' => $filePath,
                    'size' => filesize($filePath)
                ]);
                return file_get_contents($filePath);
            }
            
            // If not absolute path or direct access failed, use Storage disk
            $disk = \Illuminate\Support\Facades\Storage::disk('documents');
            
            // Remove any leading 'documents/' prefix if present
            $normalizedPath = preg_replace('/^documents\//i', '', $filePath);
            
            // Also handle different storage path formats
            $documentsRoot = storage_path('app/documents');
            $publicDocumentsRoot = storage_path('app/public/documents');
            
            if (str_starts_with($filePath, $documentsRoot)) {
                $normalizedPath = str_replace($documentsRoot . '/', '', $filePath);
            } elseif (str_starts_with($filePath, $publicDocumentsRoot)) {
                $normalizedPath = str_replace($publicDocumentsRoot . '/', '', $filePath);
            }
            
            // Try different path variations
            $pathsToTry = [
                $normalizedPath,                    // Clean path without prefix
                basename($filePath),                // Just the filename
                $filePath,                          // Original path as-is (if relative)
            ];
            
            // Remove duplicates and empty paths
            $pathsToTry = array_unique(array_filter($pathsToTry));
            
            foreach ($pathsToTry as $tryPath) {
                if ($disk->exists($tryPath)) {
                    \Illuminate\Support\Facades\Log::info('File found via Storage disk', [
                        'path' => $tryPath,
                        'size' => $disk->size($tryPath)
                    ]);
                    return $disk->get($tryPath);
                }
            }
            
            // If we're in production, try S3/MinIO paths
            if (app()->environment('production')) {
                // Check if using S3/MinIO
                if (config('filesystems.disks.documents.driver') === 's3') {
                    $s3Path = trim($filePath, '/');
                    if ($disk->exists($s3Path)) {
                        return $disk->get($s3Path);
                    }
                }
            }
            
            // Try direct file access for absolute paths (fallback)
            if (file_exists($filePath)) {
                return file_get_contents($filePath);
            }
            
            // Try with the documents root prepended
            $fullPath = $documentsRoot . '/' . ltrim($normalizedPath, '/');
            if (file_exists($fullPath)) {
                return file_get_contents($fullPath);
            }
            
            // Log all attempted paths for debugging
            $attemptedPaths = array_map(function($path) use ($disk) {
                return [
                    'path' => $path,
                    'storage_exists' => $disk->exists($path) ? 'yes' : 'no',
                    'file_exists' => file_exists($path) ? 'yes' : 'no'
                ];
            }, $pathsToTry);
            
            // Additional fallback paths to try
            $fallbackPaths = [
                $documentsRoot . '/' . ltrim($normalizedPath, '/'),
                $publicDocumentsRoot . '/' . ltrim($normalizedPath, '/'),
            ];
            
            foreach ($fallbackPaths as $fallbackPath) {
                if (file_exists($fallbackPath)) {
                    \Illuminate\Support\Facades\Log::info('File found via fallback path', [
                        'path' => $fallbackPath,
                        'size' => filesize($fallbackPath)
                    ]);
                    return file_get_contents($fallbackPath);
                }
            }
            
            $attempts = array_merge(
                array_map(function($path) { return "Storage disk 'documents' with path: {$path}"; }, $pathsToTry),
                array_map(function($path) { return "Direct file access: {$path}"; }, array_merge([$filePath], $fallbackPaths))
            );
            
            throw new \Exception("File not found after trying multiple paths: " . implode(', ', $attempts));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to get file content', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'attempted_paths' => isset($attemptedPaths) ? $attemptedPaths : 'none'
            ]);
            throw $e;
        }
    }

    /**
     * Create or update contact person for a client
     */
    public function createOrUpdateContactPerson(string $clientId, $contactPersonData): ?array
    {
        try {
            // Use dedicated createContact method instead of updating client
            return $this->createContact($clientId, $contactPersonData);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create/update contact person', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'contact_data' => $contactPersonData
            ]);
            return null;
        }
    }

    /**
     * Create a contact person for a specific client (Method A: client-scoped creation)
     */
    public function createContact(string|int $clientId, array $contact): ?array
    {
        return $this->createClientContact((int)$clientId, $contact);
    }

    /**
     * Try client-scoped contact creation first, fallback to client patch if not supported
     */
    public function createClientContact(int $clientId, array $contact): ?array
    {
        $payload = array_filter([
            'title'            => $contact['title'] ?? null,
            'firstName'        => $contact['first_name'] ?? $contact['firstName'] ?? null,
            'surname'          => $contact['last_name']  ?? $contact['surname']   ?? null,
            'function'         => $contact['function'] ?? null,
            'email'            => $contact['email'] ?? null,
            'tel'              => $contact['tel'] ?? $contact['phone'] ?? null,
            'gsm'              => $contact['gsm'] ?? $contact['mobile'] ?? null,
            'isPrimary'        => $contact['is_primary'] ?? false,
            'receivesQuotes'   => $contact['receives_quotes'] ?? true,
            'receivesInvoices' => $contact['receives_invoices'] ?? false,
        ], fn($v) => $v !== null && $v !== '');

        try {
            // Try A: client-scoped contact creation
            $response = $this->getHttpClient()
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->post("/api/v2/clients/{$clientId}/contacts", $payload);

            if ($response->successful()) {
                $result = $response->json();
                \Illuminate\Support\Facades\Log::info('Created contact via client-scoped endpoint', [
                    'client_id' => $clientId,
                    'contact_id' => $result['id'] ?? 'unknown',
                    'method' => 'POST /clients/{id}/contacts'
                ]);
                return $result;
            }

            // If method/path not allowed on this tenant, fall back to B
            if (in_array($response->status(), [404, 405], true)) {
                \Illuminate\Support\Facades\Log::info('Client-scoped contact creation not supported, falling back to client patch', [
                    'client_id' => $clientId,
                    'status' => $response->status(),
                    'fallback_method' => 'PATCH client with contactPersons'
                ]);
                return $this->appendContactViaClientPatch($clientId, $payload);
            }

            \Illuminate\Support\Facades\Log::error('Create contact failed', [
                'client_id' => $clientId, 
                'status' => $response->status(), 
                'body' => $response->body(),
                'method' => 'POST /clients/{id}/contacts'
            ]);
            return null;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Exception creating contact', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'contact_data' => $contact
            ]);
            
            // Try fallback method on exception
            return $this->appendContactViaClientPatch($clientId, $payload);
        }
    }

    /**
     * Fallback B: merge existing contacts and patch client with complete list
     */
    private function appendContactViaClientPatch(int $clientId, array $payload): ?array
    {
        try {
            // 1) Read current contacts to avoid replacing existing ones
            $current = $this->getHttpClient()
                ->get("/api/v2/clients/{$clientId}", ['include' => 'contacts'])
                ->throw()->json();

            $existing = $current['contacts'] ?? [];

            // Normalize keys if the API returns slightly different casing
            $new = array_filter([
                'title'            => $payload['title']         ?? null,
                'firstName'        => $payload['firstName']     ?? null,
                'surname'          => $payload['surname']       ?? null,
                'function'         => $payload['function']      ?? null,
                'email'            => $payload['email']         ?? null,
                'tel'              => $payload['tel']           ?? null,
                'gsm'              => $payload['gsm']           ?? null,
                'isPrimary'        => $payload['isPrimary']     ?? false,
                'receivesQuotes'   => $payload['receivesQuotes']   ?? true,
                'receivesInvoices' => $payload['receivesInvoices'] ?? false,
            ], fn($v) => $v !== null && $v !== '');

            // 2) Merge and PATCH with correct content type
            $contactPersons = array_values(array_merge($existing, [$new]));
            $json = json_encode(['contactPersons' => $contactPersons], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

            $response = $this->getHttpClient()
                ->withHeaders(['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/json'])
                ->withBody($json, 'application/merge-patch+json')
                ->patch("/api/v2/clients/{$clientId}");

            if ($response->successful()) {
                $result = $response->json();
                \Illuminate\Support\Facades\Log::info('Created contact via client patch fallback', [
                    'client_id' => $clientId,
                    'existing_contacts' => count($existing),
                    'total_contacts_after' => count($contactPersons),
                    'method' => 'PATCH client with contactPersons'
                ]);
                return $result;
            }

            \Illuminate\Support\Facades\Log::error('Contact creation via client patch failed', [
                'client_id' => $clientId,
                'status' => $response->status(),
                'body' => $response->body(),
                'method' => 'PATCH client with contactPersons'
            ]);
            return null;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Exception in contact patch fallback', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            return null;
        }
    }

    /**
     * Smart upsert that uses the robust client-scoped approach with fallback
     */
    public function upsertPrimaryContact(int $clientId, array $contact): ?array
    {
        // Try creating the contact using the robust method A -> B fallback
        $created = $this->createClientContact($clientId, $contact);
        if ($created) return $created;

        // If all creation methods fail and we have email, try to find and update existing contact
        if (!empty($contact['email'])) {
            try {
                \Illuminate\Support\Facades\Log::info('Contact creation failed, attempting to find existing contact by email', [
                    'client_id' => $clientId,
                    'email' => $contact['email']
                ]);
                
                $res = $this->getHttpClient()->get('/api/v2/contacts', [
                    'email' => $contact['email'], 
                    'include' => 'client', 
                    'page' => 0, 
                    'size' => 1
                ])->throw()->json();

                $found = $res['items'][0] ?? null;
                if ($found && (int)($found['clientId'] ?? 0) === (int)$clientId) {
                    $patch = array_filter([
                        'title'     => $contact['title'] ?? null,
                        'firstName' => $contact['first_name'] ?? $contact['firstName'] ?? null,
                        'surname'   => $contact['last_name']  ?? $contact['surname']    ?? null,
                        'function'  => $contact['function'] ?? null,
                        'tel'       => $contact['phone']   ?? $contact['tel'] ?? null,
                        'gsm'       => $contact['mobile']  ?? $contact['gsm'] ?? null,
                        'isPrimary' => $contact['is_primary'] ?? null,
                    ], fn($v) => $v !== null && $v !== '');

                    $json = json_encode($patch, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                    $r = $this->getHttpClient()
                        ->withHeaders(['Content-Type'=>'application/merge-patch+json','Accept'=>'application/json'])
                        ->withBody($json, 'application/merge-patch+json')
                        ->patch("/api/v2/contacts/{$found['id']}");
                    
                    if ($r->successful()) {
                        \Illuminate\Support\Facades\Log::info('Updated existing contact person', [
                            'client_id' => $clientId,
                            'contact_id' => $found['id'],
                            'updated_fields' => array_keys($patch)
                        ]);
                        return $r->json();
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to update existing contact', [
                    'error' => $e->getMessage(),
                    'client_id' => $clientId,
                    'email' => $contact['email']
                ]);
            }
        }
        return null;
    }
}
