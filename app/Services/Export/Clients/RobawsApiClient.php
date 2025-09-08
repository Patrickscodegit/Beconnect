<?php

namespace App\Services\Export\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
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

    // === UTILITY METHODS FOR ROBUST RESOLUTION ===
    
    private function normEmail(?string $e): ?string {
        if (!$e) return null;
        $e = trim(mb_strtolower($e));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    private function normPhone(?string $p): ?string {
        if (!$p) return null;
        $digits = preg_replace('/\D+/', '', $p);
        return $digits ?: null;
    }

    private function lockKeyFor(?string $email): ?string {
        return $email ? 'robaws:resolve:' . sha1(mb_strtolower($email)) : null;
    }

    /** Search clients by email - robust method that searches through clients and their contacts */
    public function findContactByEmail(string $email): ?array
    {
        try {
            // First try the direct contacts API (but it doesn't seem to filter properly)
            $res = $this->getHttpClient()
                ->get('/api/v2/contacts', [
                    'email' => $email,
                    'include' => 'client',
                    'page' => 0,
                    'size' => 100  // Get more results since filtering doesn't work
                ])
                ->throw()
                ->json();

            $contacts = $res['items'] ?? [];
            
            // Manually filter since API filtering doesn't work
            foreach ($contacts as $contact) {
                if (!empty($contact['email']) && strcasecmp($contact['email'], $email) === 0) {
                    // Found a matching contact, get the client
                    if (!empty($contact['client'])) {
                        return $contact['client'];
                    } elseif (!empty($contact['clientId'])) {
                        return $this->getClientById($contact['clientId']);
                    }
                }
            }
            
            // If contacts search didn't work, fall back to comprehensive client search
            return $this->searchClientsByEmailThroughClients($email);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Error using contacts endpoint, falling back to client search', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);
            
            // Fallback to comprehensive client search
            return $this->searchClientsByEmailThroughClients($email);
        }
    }

    /** Comprehensive fallback: search through all clients and their contacts */
    private function searchClientsByEmailThroughClients(string $email): ?array
    {
        $page = 0;
        $size = 50; // Smaller pages for contact checking
        $maxPages = 20; // Reasonable limit
        
        do {
            $res = $this->getHttpClient()
                ->get('/api/v2/clients', [
                    'page' => $page, 
                    'size' => $size, 
                    'sort' => 'name:asc',
                    'include' => 'contacts'
                ])
                ->throw()
                ->json();

            $clients = $res['items'] ?? [];
            
            foreach ($clients as $client) {
                // Check client email first
                if (!empty($client['email']) && strcasecmp($client['email'], $email) === 0) {
                    return $client;
                }
                
                // Check contacts if included
                if (!empty($client['contacts'])) {
                    foreach ($client['contacts'] as $contact) {
                        if (!empty($contact['email']) && strcasecmp($contact['email'], $email) === 0) {
                            return $client;
                        }
                    }
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

    /**
     * Try very hard to find the owning client by contact e-mail.
     * Pages through ALL contacts, filters locally, follows clientId to get client.
     * Respects Robaws max page size of 100.
     */
    public function findClientByEmailRobust(string $email): ?array
    {
        $email = $this->normEmail($email);
        if (!$email) return null;

        $page = 0; $size = 100; // Robaws max size is 100
        $maxPages = 50;         // safety bound
        do {
            $res = $this->getHttpClient()->get('/api/v2/contacts', [
                'email'   => $email,        // some tenants ignore this filter; we filter locally anyway
                'include' => 'client',      // some tenants return it; some don't
                'page'    => $page,
                'size'    => $size,
            ])->throw()->json();

            foreach (($res['items'] ?? []) as $contact) {
                $cEmail = isset($contact['email']) ? mb_strtolower(trim($contact['email'])) : null;
                if ($cEmail !== $email) continue;

                // If expanded client present, use it; else follow clientId
                if (!empty($contact['client'])) return $contact['client'];
                if (!empty($contact['clientId'])) {
                    $client = $this->getClientById((string)$contact['clientId'], ['contacts']);
                    if ($client) return $client;
                }
            }

            $page++;
            $total = (int)($res['totalItems'] ?? 0);
        } while ($page * $size < $total && $page < $maxPages);

        return null;
    }

    /**
     * Scan clients across pages with contacts to find email match.
     * Includes contacts if possible, else fetches per-client contacts.
     * This is the fallback when findClientByEmailRobust doesn't find anything.
     */
    public function scanClientsForEmail(string $email): ?array
    {
        $email = $this->normEmail($email);
        if (!$email) return null;

        $candidates = []; // Collect all matching clients
        $page = 0; $size = 100; $maxPages = 50; // up to 5,000 clients
        do {
            $res = $this->getHttpClient()->get('/api/v2/clients', [
                'page' => $page, 'size' => $size, 'sort' => 'name:asc',
                'include' => 'contacts', // works on many tenants; harmless if ignored
            ])->throw()->json();

            foreach (($res['items'] ?? []) as $client) {
                $matched = false;
                
                // quick check: client-level email
                $cEmail = isset($client['email']) ? mb_strtolower(trim($client['email'])) : null;
                if ($cEmail === $email) {
                    $matched = true;
                }

                // if contacts already included, check locally
                if (!$matched) {
                    $contacts = $client['contacts'] ?? null;
                    if (is_array($contacts)) {
                        foreach ($contacts as $ct) {
                            $e = isset($ct['email']) ? mb_strtolower(trim($ct['email'])) : null;
                            if ($e === $email) {
                                $matched = true;
                                break;
                            }
                        }
                    } else {
                        // fallback: read client-scoped contacts (bounded)
                        try {
                            $list = $this->getHttpClient()
                                ->get("/api/v2/clients/{$client['id']}/contacts", ['page'=>0,'size'=>100])
                                ->throw()->json();
                            foreach (($list['items'] ?? $list ?? []) as $ct) {
                                $e = isset($ct['email']) ? mb_strtolower(trim($ct['email'])) : null;
                                if ($e === $email) {
                                    $matched = true;
                                    // Make sure we have contacts for chooseBest
                                    $client['contacts'] = $list['items'] ?? $list ?? [];
                                    break;
                                }
                            }
                        } catch (\Throwable $_) {
                            // Skip this client if contacts fetch fails
                        }
                    }
                }

                if ($matched) {
                    $candidates[] = $client;
                }
            }

            $page++;
            $total = (int)($res['totalItems'] ?? 0);
        } while ($page * $size < $total && $page < $maxPages);

        // Use chooseBest to select the best candidate
        return $this->chooseBest($candidates, $email);
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

    public function findClientByPhoneRobust(string $phone): ?array
    {
        $needle = $this->normPhone($phone);
        if (!$needle) return null;

        $res = $this->getHttpClient()->get('/api/v2/clients', [
            'page'=>0,'size'=>100,'sort'=>'name:asc','include'=>'contacts'
        ])->throw()->json();

        foreach (($res['items'] ?? []) as $client) {
            foreach (['tel','gsm'] as $k) {
                if (!empty($client[$k]) && $this->normPhone($client[$k]) === $needle) return $client;
            }
            foreach (($client['contacts'] ?? []) as $c) {
                foreach (['tel','gsm'] as $k) {
                    if (!empty($c[$k]) && $this->normPhone($c[$k]) === $needle) return $client;
                }
            }
        }
        return null;
    }

    public function findClientByNameFuzzy(string $name): ?array
    {
        $name = trim(mb_strtolower($name));
        if (!$name) return null;

        $best = null; $bestScore = 0.0;

        $res = $this->getHttpClient()->get('/api/v2/clients', [
            'page'=>0,'size'=>100,'sort'=>'name:asc'
        ])->throw()->json();

        foreach (($res['items'] ?? []) as $client) {
            $cand = mb_strtolower(trim($client['name'] ?? ''));
            if (!$cand) continue;

            // quick similarity (Levenshtein is fine, or simple ratio)
            similar_text($name, $cand, $pct);
            if ($pct > $bestScore) { $bestScore = $pct; $best = $client; }
        }

        return $bestScore >= 80.0 ? $best : null; // threshold
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
     * Update existing client with new data using proper merge-patch
     */
    public function updateClient(int $clientId, array $customerData): ?array
    {
        try {
            $update = [];

            // Map phone fields to Robaws field names
            if (!empty($customerData['phone'] ?? $customerData['tel'])) {
                $update['tel'] = $customerData['phone'] ?? $customerData['tel'];
            }
            if (!empty($customerData['mobile'] ?? $customerData['gsm'])) {
                $update['gsm'] = $customerData['mobile'] ?? $customerData['gsm'];
            }
            if (!empty($customerData['website'])) {
                $update['website'] = $customerData['website'];
            }
            if (!empty($customerData['vat_number'])) {
                $update['vatNumber'] = $customerData['vat_number'];
            }
            if (!empty($customerData['company_number'])) {
                $update['companyNumber'] = $customerData['company_number'];
            }

            // Handle address if provided
            $addressFields = array_filter([
                $customerData['street'] ?? null,
                $customerData['city'] ?? null,
                $customerData['postal_code'] ?? null,
                $customerData['country'] ?? null,
            ]);

            if (!empty($addressFields)) {
                $update['address'] = array_filter([
                    'street'       => $customerData['street'] ?? null,
                    'streetNumber' => $customerData['street_number'] ?? null,
                    'postalCode'   => $customerData['postal_code'] ?? null,
                    'city'         => $customerData['city'] ?? null,
                    'country'      => $customerData['country'] ?? null,
                    'countryCode'  => $customerData['country_code'] ?? null,
                ]);
            }

            if (empty($update)) return ['id' => $clientId];

            // Use proper JSON Merge Patch content type
            $response = $this->getHttpClient()
                ->withHeaders(['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/json'])
                ->patch("/api/v2/clients/{$clientId}", $update);

            if ($response->successful()) {
                $result = $response->json();
                \Illuminate\Support\Facades\Log::info('Updated Robaws client successfully', [
                    'client_id' => $clientId,
                    'updated_fields' => array_keys($update)
                ]);
                return $result ?? ['id' => $clientId];
            }

            \Illuminate\Support\Facades\Log::error('Error updating Robaws client', [
                'client_id' => $clientId,
                'status' => $response->status(),
                'body' => $response->body(),
                'update_data' => $update,
            ]);
            return ['id' => $clientId];

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Exception updating Robaws client', [
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
     * Create a contact under a client using the proper client-scoped endpoint
     */
    public function createClientContact(int $clientId, array $contact): ?array
    {
        // Map our internal fields → Robaws fields (based on Robaws documentation)
        // Robaws uses 'name' for first name and 'surname' for last name
        $payload = array_filter([
            'title'            => $contact['title'] ?? null,
            'name'             => $contact['first_name'] ?? $contact['firstName'] ?? null,  // Robaws uses 'name' for first name
            'surname'          => $contact['last_name'] ?? $contact['surname'] ?? null,
            'function'         => $contact['function'] ?? null,
            'email'            => $contact['email'] ?? null,
            'tel'              => $contact['tel'] ?? $contact['phone'] ?? null,
            'gsm'              => $contact['gsm'] ?? $contact['mobile'] ?? null,
            'isPrimary'        => $contact['is_primary'] ?? false,
            'receivesQuotes'   => $contact['receives_quotes'] ?? true,
            'receivesInvoices' => $contact['receives_invoices'] ?? false,
        ], fn($v) => $v !== null && $v !== '');

        try {
            // POST to the client-scoped contacts collection
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
            return null;
        }
    }

    /**
     * Update a contact using the proper client-scoped endpoint with merge-patch
     */
    public function updateClientContact(int|string $clientId, int|string $contactId, array $patch): ?array
    {
        $body = array_filter([
            'title'            => $patch['title'] ?? null,
            'name'             => $patch['first_name'] ?? $patch['firstName'] ?? null,  // Robaws uses 'name' for first name
            'surname'          => $patch['last_name'] ?? $patch['surname'] ?? null,
            'function'         => $patch['function'] ?? null,
            'email'            => $patch['email'] ?? null,
            'tel'              => $patch['phone'] ?? $patch['tel'] ?? null,
            'gsm'              => $patch['mobile'] ?? $patch['gsm'] ?? null,
            'isPrimary'        => $patch['is_primary'] ?? null,
            'receivesInvoices' => $patch['receives_invoices'] ?? null,
            'receivesQuotes'   => $patch['receives_quotes'] ?? null,
        ], fn($v) => $v !== null);

        try {
            $response = $this->getHttpClient()
                ->withHeaders(['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/json'])
                ->patch("/api/v2/clients/{$clientId}/contacts/{$contactId}", $body);

            if ($response->successful()) {
                $result = $response->json();
                \Illuminate\Support\Facades\Log::info('Updated contact via client-scoped endpoint', [
                    'client_id' => $clientId,
                    'contact_id' => $contactId,
                    'updated_fields' => array_keys($body)
                ]);
                return $result;
            }

            \Illuminate\Support\Facades\Log::error('Update contact failed', [
                'client_id' => $clientId,
                'contact_id' => $contactId,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Exception updating contact', [
                'client_id' => $clientId,
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }



    /**
     * Create or update a primary contact for a client
     */
    public function upsertPrimaryContact(int $clientId, array $contact): ?array
    {
        // Try creating the contact using the client-scoped endpoint
        $created = $this->createClientContact($clientId, $contact);
        if ($created) return $created;

        // If creation fails and we have email, try to find and update existing contact
        if (!empty($contact['email'])) {
            try {
                \Illuminate\Support\Facades\Log::info('Contact creation failed, attempting to find existing contact by email', [
                    'client_id' => $clientId,
                    'email' => $contact['email']
                ]);
                
                // Search for existing contact by email
                $res = $this->getHttpClient()->get('/api/v2/contacts', [
                    'email' => $contact['email'], 
                    'include' => 'client', 
                    'page' => 0, 
                    'size' => 1
                ])->throw()->json();

                $found = $res['items'][0] ?? null;
                if ($found && (int)($found['clientId'] ?? 0) === (int)$clientId) {
                    // Update the existing contact
                    return $this->updateClientContact($clientId, $found['id'], $contact);
                }

            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to find existing contact', [
                    'client_id' => $clientId,
                    'email' => $contact['email'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return null;
    }

    /**
     * Choose the best client when multiple candidates match.
     * Prefers client with more contacts matching the email, then older ID.
     */
    private function chooseBest(array $candidates, string $email): ?array
    {
        if (empty($candidates)) return null;
        if (count($candidates) === 1) return $candidates[0];
        
        $email = mb_strtolower($email);
        usort($candidates, function ($a, $b) use ($email) {
            $score = function ($cl) use ($email) {
                $hits = 0;
                foreach (($cl['contacts'] ?? []) as $ct) {
                    $e = isset($ct['email']) ? mb_strtolower(trim($ct['email'])) : null;
                    if ($e === $email) $hits++;
                }
                return [$hits, -(int)($cl['id'] ?? 0)]; // more hits, then smaller id
            };
            return $score($b) <=> $score($a);
        });
        return $candidates[0] ?? null;
    }

    /**
     * Resolves the correct client, ensures the contact exists,
     * and guarantees we *never* POST a duplicate client.
     */
    public function resolveOrCreateClientAndContact(array $hints): array
    {
        $email = $this->normEmail($hints['email'] ?? null);
        $phone = $this->normPhone($hints['phone'] ?? $hints['tel'] ?? $hints['gsm'] ?? null);
        $name  = trim($hints['client_name'] ?? $hints['name'] ?? '');

        $lockKey = $this->lockKeyFor($email);
        $ttl = 25; // seconds

        return Cache::lock($lockKey ?? Str::uuid()->toString(), $ttl)->block($ttl, function () use ($email, $phone, $name, $hints) {

            // 1) Resolve existing client (email > phone > fuzzy name)
            $client = null;
            // Email resolution (robust): try contacts first, then scan clients
            if ($email) {
                $client = $this->findClientByEmailRobust($email) ?: $this->scanClientsForEmail($email);
            }
            if (!$client && $phone) $client = $this->findClientByPhoneRobust($phone);
            if (!$client && $name)  $client = $this->findClientByNameFuzzy($name);

            // 2) If found → ensure contact exists on it, then return
            if ($client && !empty($client['id'])) {
                $clientId = (int)$client['id'];
                $this->ensureContactExists($clientId, $hints);
                return ['id' => $clientId, 'created' => false, 'source' => 'resolved'];
            }

            // 3) Otherwise create a client (idempotent), then add contact
            $idKey = 'client:create:' . sha1(($name ?: '') . '|' . ($email ?: ''));

            $resp = $this->getHttpClient()
                ->withHeaders(['Idempotency-Key' => $idKey, 'Content-Type'=>'application/json'])
                ->post('/api/v2/clients', array_filter([
                    'name'       => $name ?: ($email ?: 'Unknown'),
                    'email'      => $email,
                    'tel'        => $phone,
                    'language'   => $hints['language'] ?? null,
                    'currency'   => $hints['currency'] ?? 'EUR',
                    'vatNumber'  => $hints['vat_number'] ?? null,
                    'website'    => $hints['website'] ?? null,
                    'address'    => array_filter([
                        'street'       => $hints['street'] ?? null,
                        'streetNumber' => $hints['street_number'] ?? null,
                        'postalCode'   => $hints['postal_code'] ?? null,
                        'city'         => $hints['city'] ?? null,
                        'country'      => $hints['country'] ?? null,
                        'countryCode'  => $hints['country_code'] ?? null,
                    ]),
                ], fn($v) => $v !== null && $v !== ''))
                ->throw()->json();

            $clientId = (int)($resp['id'] ?? 0);
            if ($clientId > 0) {
                $this->ensureContactExists($clientId, $hints);
                return ['id' => $clientId, 'created' => true, 'source' => 'created'];
            }

            throw new \RuntimeException('Failed to create or resolve client');
        });
    }

    /**
     * Ensure the contact exists (email is the identity)
     */
    public function ensureContactExists(int $clientId, array $hints): void
    {
        $email = $this->normEmail($hints['contact_email'] ?? $hints['email'] ?? null);
        $first = $hints['first_name'] ?? $hints['firstName'] ?? null;
        $last  = $hints['last_name']  ?? $hints['surname']   ?? null;
        $tel   = $hints['contact_phone'] ?? $hints['phone'] ?? $hints['tel'] ?? null;
        $gsm   = $hints['contact_mobile'] ?? $hints['mobile'] ?? $hints['gsm'] ?? null;

        // Load existing contacts once
        $contacts = $this->getHttpClient()
            ->get("/api/v2/clients/{$clientId}/contacts", ['page'=>0,'size'=>100])
            ->throw()->json();

        $items = $contacts['items'] ?? $contacts ?? [];
        foreach ($items as $c) {
            $cEmail = isset($c['email']) ? mb_strtolower(trim($c['email'])) : null;
            if ($email && $cEmail === $email) {
                // Optional: update missing tel/gsm/function via merge-patch
                $patch = array_filter([
                    'function'  => $hints['function'] ?? null,
                    'tel'       => $tel ?: null,
                    'gsm'       => $gsm ?: null,
                ], fn($v) => $v !== null && $v !== '');
                if ($patch) {
                    $this->getHttpClient()
                        ->withHeaders(['Content-Type'=>'application/merge-patch+json'])
                        ->patch("/api/v2/clients/{$clientId}/contacts/{$c['id']}", $patch);
                }
                return; // already exists
            }
        }

        // Create new contact on this client (client-scoped endpoint)
        $payload = array_filter([
            'title'     => $hints['title'] ?? null,
            'firstName' => $first,              // if your tenant doesn't persist this, it'll be ignored
            'surname'   => $last ?? ($hints['contact_name'] ?? null),
            'function'  => $hints['function'] ?? null,
            'email'     => $email,
            'tel'       => $tel,
            'gsm'       => $gsm,
            'isPrimary' => $hints['is_primary'] ?? false,
            'receivesQuotes'   => $hints['receives_quotes']   ?? true,
            'receivesInvoices' => $hints['receives_invoices'] ?? false,
        ], fn($v) => $v !== null && $v !== '');

        $this->getHttpClient()
            ->withHeaders(['Content-Type'=>'application/json','Accept'=>'application/json'])
            ->post("/api/v2/clients/{$clientId}/contacts", $payload)
            ->throw();
    }
}
