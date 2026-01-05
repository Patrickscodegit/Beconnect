<?php

namespace App\Services\Export\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class RobawsApiClient
{
    private ?PendingRequest $http = null;
    
    // Rate limiting properties
    private int $dailyLimit = 10000;
    private int $dailyRemaining = 10000;
    private int $perSecondLimit = 15;
    private int $requestsThisSecond = 0;
    private float $currentSecondStart;
    private int $dailyMinBuffer = 500; // Reserve 500 requests for other operations

    public function __construct()
    {
        // Lazy initialization - don't create HTTP client in constructor
        // This prevents errors during composer autoload discovery
        $this->currentSecondStart = microtime(true);
    }

    /**
     * Execute HTTP request with automatic retry for rate limiting (HTTP 429)
     */
    private function executeWithRateLimitRetry(callable $requestCallback, int $maxRetries = 3): mixed
    {
        $attempt = 0;
        
        while ($attempt <= $maxRetries) {
            try {
                return $requestCallback();
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $statusCode = $e->response?->status();
                
                // Handle rate limiting (HTTP 429)
                if ($statusCode === 429) {
                    $attempt++;
                    
                    if ($attempt <= $maxRetries) {
                        // Exponential backoff: 2^attempt seconds
                        $delay = pow(2, $attempt);
                        
                        \Illuminate\Support\Facades\Log::warning('Robaws API rate limited, retrying with exponential backoff', [
                            'attempt' => $attempt,
                            'max_retries' => $maxRetries,
                            'delay_seconds' => $delay,
                            'status_code' => $statusCode,
                            'response_body' => $e->response?->body()
                        ]);
                        
                        sleep($delay);
                        continue;
                    } else {
                        \Illuminate\Support\Facades\Log::error('Robaws API rate limit exceeded after all retries', [
                            'max_retries' => $maxRetries,
                            'status_code' => $statusCode,
                            'response_body' => $e->response?->body()
                        ]);
                        
                        throw $e;
                    }
                } else {
                    // Re-throw non-rate-limit errors immediately
                    throw $e;
                }
            }
        }
        
        throw new \RuntimeException('Rate limit retry logic failed unexpectedly');
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

        // Try API filtering first (most efficient)
        $result = $this->findClientByNameFiltered($name);
        if ($result) {
            return $result;
        }

        // Fallback to pagination if filtering fails
        return $this->findClientByNamePagination($name);
    }

    /**
     * Enhanced client search using API filtering
     */
    private function findClientByNameFiltered(string $name): ?array
    {
        $name = trim(mb_strtolower($name));
        if (!$name) return null;

        try {
            // Try different filter syntaxes that Robaws API might support
            $filterOptions = [
                "name:like:{$name}",
                "name:contains:{$name}",
                "name:ilike:{$name}",
                "search:{$name}"
            ];

            foreach ($filterOptions as $filter) {
                try {
                    $res = $this->getHttpClient()->get('/api/v2/clients', [
                        'page' => 0,
                        'size' => 100,
                        'filter' => $filter
                    ])->throw()->json();

                    $items = $res['items'] ?? [];
                    if (!empty($items)) {
                        \Log::info('Client filtering successful', [
                            'filter' => $filter,
                            'found_clients' => count($items),
                            'search_name' => $name
                        ]);
                        
                        $match = $this->findBestMatch($name, $items);
                        if ($match && $match['score'] >= 80.0) {
                            return $match['client'];
                        }
                    }
                } catch (\Exception $e) {
                    \Log::debug('Filter failed', ['filter' => $filter, 'error' => $e->getMessage()]);
                    continue;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('All filtering attempts failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fallback client search using pagination
     */
    private function findClientByNamePagination(string $name): ?array
    {
        $name = trim(mb_strtolower($name));
        if (!$name) return null;

        $best = null; $bestScore = 0.0;
        $page = 0;

        \Log::info('Starting pagination search for client', ['name' => $name]);

        do {
            try {
                $res = $this->getHttpClient()->get('/api/v2/clients', [
                    'page' => $page,
                    'size' => 100,
                    'sort' => 'name:asc'
                ])->throw()->json();

                $items = $res['items'] ?? [];
                if (empty($items)) break;

                $match = $this->findBestMatch($name, $items);
                if ($match && $match['score'] > $bestScore) {
                    $best = $match['client'];
                    $bestScore = $match['score'];
                }

                \Log::debug('Pagination search page', [
                    'page' => $page,
                    'items_count' => count($items),
                    'best_score' => $bestScore
                ]);

                $page++;
            } catch (\Exception $e) {
                \Log::error('Pagination search failed', [
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        } while (count($items) === 100); // Continue if full page

        \Log::info('Pagination search completed', [
            'name' => $name,
            'pages_searched' => $page,
            'best_score' => $bestScore,
            'found' => $bestScore >= 80.0
        ]);

        return $bestScore >= 80.0 ? $best : null;
    }

    /**
     * Find the best matching client from a list of clients
     */
    private function findBestMatch(string $searchName, array $clients): ?array
    {
        $best = null; $bestScore = 0.0;

        foreach ($clients as $client) {
            $cand = mb_strtolower(trim($client['name'] ?? ''));
            if (!$cand) continue;

            // Calculate similarity score
            similar_text($searchName, $cand, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $client;
            }
        }

        return $best ? ['client' => $best, 'score' => $bestScore] : null;
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
            // Use the same payload conversion as createClient for consistency
            $update = $this->toRobawsClientPayload($customerData, true);
            
            if (empty($update)) return ['id' => $clientId];

            // Use regular JSON content type like other operations
            $response = $this->getHttpClient()
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->patch("/api/v2/clients/{$clientId}", $update);

            if ($response->successful()) {
                $result = $response->json();
                \Illuminate\Support\Facades\Log::info('Updated Robaws client successfully', [
                    'client_id' => $clientId,
                    'updated_fields' => array_keys($update),
                    'update_data' => $update
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
     * Delete a client from Robaws using the DELETE API endpoint
     * Reference: https://app.robaws.com/public/api-docs/robaws#/operations/delete_89
     */
    public function deleteClient(int $clientId): bool
    {
        try {
            $response = $this->getHttpClient()
                ->delete("/api/v2/clients/{$clientId}");

            if ($response->successful()) {
                \Illuminate\Support\Facades\Log::info('Client deleted from Robaws', [
                    'client_id' => $clientId,
                ]);
                return true;
            }

            \Illuminate\Support\Facades\Log::error('Failed to delete client from Robaws', [
                'client_id' => $clientId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Exception deleting client from Robaws', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            return false;
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

            // Log the response for debugging
            \Log::channel('robaws')->info('Robaws CREATE offer response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body(),
                'headers' => $response->headers(),
            ]);

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
                ->timeout(config('services.robaws.timeout', 10))
                ->connectTimeout(config('services.robaws.connect_timeout', 5));

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
            $response = $this->getHttpClient()->get('/api/v2/clients', ['page'=>0,'size'=>1]);
            
            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'kind' => 'clients-ping',
                'response_time' => $response->transferStats?->getTransferTime(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => method_exists($e, 'getCode') ? (int)$e->getCode() : 0,
                'kind' => 'clients-ping',
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

    /**
     * Enforce API rate limits before making a request
     * Respects both per-second (15 req/sec) and daily (10,000 req/day) limits
     */
    private function enforceRateLimit(): void
    {
        // Check daily quota (stop if below buffer)
        if ($this->dailyRemaining <= $this->dailyMinBuffer) {
            \Illuminate\Support\Facades\Log::critical('Approaching daily API quota limit', [
                'remaining' => $this->dailyRemaining,
                'buffer' => $this->dailyMinBuffer
            ]);
            throw new \RuntimeException("Daily API quota nearly exhausted. Remaining: {$this->dailyRemaining}. Reserved buffer: {$this->dailyMinBuffer}");
        }
        
        // Check per-second limit
        $now = microtime(true);
        if (!isset($this->currentSecondStart)) {
            $this->currentSecondStart = $now;
            $this->requestsThisSecond = 0;
        }
        
        // Reset counter if new second
        if ($now - $this->currentSecondStart >= 1.0) {
            $this->currentSecondStart = $now;
            $this->requestsThisSecond = 0;
        }
        
        // Throttle if at per-second limit
        if ($this->requestsThisSecond >= $this->perSecondLimit) {
            $sleepTime = 1.0 - ($now - $this->currentSecondStart);
            if ($sleepTime > 0) {
                usleep((int)($sleepTime * 1000000));
                $this->currentSecondStart = microtime(true);
                $this->requestsThisSecond = 0;
            }
        }
        
        $this->requestsThisSecond++;
    }

    /**
     * Update rate limit tracking from API response headers
     */
    private function updateRateLimitsFromResponse($response): void
    {
        $headers = $response->headers();
        
        // Debug: Log all headers to see what Robaws actually sends
        // Handle both array and collection types
        $allHeaders = is_array($headers) ? $headers : $headers->all();
        
        \Illuminate\Support\Facades\Log::info('Robaws API response headers', [
            'all_headers' => $allHeaders,
            'ratelimit_headers' => array_filter($allHeaders, function($key) {
                return str_contains(strtolower($key), 'ratelimit');
            }, ARRAY_FILTER_USE_KEY)
        ]);
        
        // Check for exact header names from Robaws documentation (lowercase!)
        if (isset($headers['x-ratelimit-daily-remaining'][0])) {
            $this->dailyRemaining = (int)$headers['x-ratelimit-daily-remaining'][0];
            Cache::put('robaws_daily_remaining', $this->dailyRemaining, now()->endOfDay());
            \Illuminate\Support\Facades\Log::info('Updated daily remaining from API', ['remaining' => $this->dailyRemaining]);
        }
        
        if (isset($headers['x-ratelimit-daily-limit'][0])) {
            $this->dailyLimit = (int)$headers['x-ratelimit-daily-limit'][0];
            Cache::put('robaws_daily_limit', $this->dailyLimit, now()->endOfDay());
            \Illuminate\Support\Facades\Log::info('Updated daily limit from API', ['limit' => $this->dailyLimit]);
        }
        
        // Log warning if getting close to daily limit
        $percentRemaining = ($this->dailyRemaining / $this->dailyLimit) * 100;
        if ($percentRemaining < 10) {
            \Illuminate\Support\Facades\Log::warning('API daily quota running low', [
                'remaining' => $this->dailyRemaining,
                'limit' => $this->dailyLimit,
                'percent' => round($percentRemaining, 2)
            ]);
        }
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
            ->timeout(config('services.robaws.timeout', 10))
            ->connectTimeout(config('services.robaws.connect_timeout', 5))
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
            $response = $this->executeWithRateLimitRetry(function() use ($clientId, $payload) {
                return $this->getHttpClient()
                    ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                    ->post("/api/v2/clients/{$clientId}/contacts", $payload);
            });

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
        
        // Filter out deleted clients first
        $activeCandidates = array_filter($candidates, function ($client) {
            // Check if client is deleted - look for common deletion indicators
            $status = $client['status'] ?? null;
            $deleted = $client['deleted'] ?? false;
            $deletedAt = $client['deleted_at'] ?? null;
            
            // Skip if explicitly marked as deleted
            if ($deleted || $deletedAt || $status === 'deleted' || $status === 'DELETED') {
                \Log::info('Skipping deleted client', [
                    'client_id' => $client['id'] ?? 'unknown',
                    'client_name' => $client['name'] ?? 'unknown',
                    'status' => $status,
                    'deleted' => $deleted,
                    'deleted_at' => $deletedAt
                ]);
                return false;
            }
            
            return true;
        });
        
        // If no active candidates, return null (will create new client)
        if (empty($activeCandidates)) {
            \Log::info('No active clients found, all candidates were deleted', [
                'total_candidates' => count($candidates),
                'email' => $email
            ]);
            return null;
        }
        
        // If only one active candidate, return it
        if (count($activeCandidates) === 1) {
            $client = reset($activeCandidates);
            \Log::info('Found single active client', [
                'client_id' => $client['id'] ?? 'unknown',
                'client_name' => $client['name'] ?? 'unknown',
                'email' => $email
            ]);
            return $client;
        }
        
        // Sort active candidates by best match
        $email = mb_strtolower($email);
        usort($activeCandidates, function ($a, $b) use ($email) {
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
        
        $bestClient = $activeCandidates[0] ?? null;
        if ($bestClient) {
            \Log::info('Selected best active client', [
                'client_id' => $bestClient['id'] ?? 'unknown',
                'client_name' => $bestClient['name'] ?? 'unknown',
                'email' => $email,
                'total_active_candidates' => count($activeCandidates)
            ]);
        }
        
        return $bestClient;
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

        // Log the search parameters for debugging
        \Log::info('Client resolution attempt', [
            'email' => $email,
            'phone' => $phone,
            'name' => $name,
            'hints' => $hints
        ]);

        $lockKey = $this->lockKeyFor($email);
        $ttl = 25; // seconds

        return Cache::lock($lockKey ?? Str::uuid()->toString(), $ttl)->block($ttl, function () use ($email, $phone, $name, $hints) {

            // 1) Resolve existing client (email > phone > fuzzy name)
            $client = null;
            // Email resolution (robust): try contacts first, then scan clients
            if ($email) {
                \Log::info('Searching for client by email', ['email' => $email]);
                $client = $this->findClientByEmailRobust($email);
                if ($client) {
                    \Log::info('Found client by email (robust)', ['client_id' => $client['id'] ?? 'unknown', 'client_name' => $client['name'] ?? 'unknown']);
                } else {
                    \Log::info('No client found by email (robust), trying scan', ['email' => $email]);
                    $client = $this->scanClientsForEmail($email);
                    if ($client) {
                        \Log::info('Found client by email (scan)', ['client_id' => $client['id'] ?? 'unknown', 'client_name' => $client['name'] ?? 'unknown']);
                    } else {
                        \Log::info('No client found by email (scan)', ['email' => $email]);
                    }
                }
            }
            if (!$client && $phone) {
                \Log::info('Searching for client by phone', ['phone' => $phone]);
                $client = $this->findClientByPhoneRobust($phone);
                if ($client) {
                    \Log::info('Found client by phone', ['client_id' => $client['id'] ?? 'unknown', 'client_name' => $client['name'] ?? 'unknown']);
                }
            }
            if (!$client && $name) {
                \Log::info('Searching for client by name', ['name' => $name]);
                $client = $this->findClientByNameFuzzy($name);
                if ($client) {
                    \Log::info('Found client by name', ['client_id' => $client['id'] ?? 'unknown', 'client_name' => $client['name'] ?? 'unknown']);
                }
            }

            // 2) If found → ensure contact exists on it, update Role if provided, then return
            if ($client && !empty($client['id'])) {
                $clientId = (int)$client['id'];
                \Log::info('Using existing client', ['client_id' => $clientId, 'client_name' => $client['name'] ?? 'unknown']);
                
                // Update Role extra field if provided for existing client
                if (!empty($hints['role'])) {
                    $this->updateClientExtraField($clientId, 'Role', $hints['role']);
                }
                
                $this->ensureContactExists($clientId, $hints);
                return ['id' => $clientId, 'created' => false, 'source' => 'resolved'];
            }

            // 3) Otherwise create a client (idempotent), then add contact
            \Log::info('No existing client found, creating new client', [
                'name' => $name ?: ($email ?: 'Unknown'),
                'email' => $email
            ]);
            $idKey = 'client:create:' . sha1(($name ?: '') . '|' . ($email ?: ''));

            // Build address only if we have address data
            $address = array_filter([
                'street'       => $hints['street'] ?? null,
                'streetNumber' => $hints['street_number'] ?? null,
                'postalCode'   => $hints['postal_code'] ?? null,
                'city'         => $hints['city'] ?? null,
                'country'      => $hints['country'] ?? null,
                'countryCode'  => $hints['country_code'] ?? null,
            ], fn($v) => $v !== null && $v !== '');

            $payload = array_filter([
                'name'       => $name ?: ($email ?: 'Unknown'),
                'email'      => $email,
                'tel'        => $phone,
                'language'   => $hints['language'] ?? null,
                'currency'   => $hints['currency'] ?? 'EUR',
                'vatNumber'  => $hints['vat_number'] ?? null,
                'website'    => $hints['website'] ?? null,
            ], fn($v) => $v !== null && $v !== '');

            // Only add address if we have address data
            if (!empty($address)) {
                $payload['address'] = $address;
            }

            // Add Role extra field if provided
            if (!empty($hints['role'])) {
                $payload['extraFields'] = [
                    'Role' => [
                        'stringValue' => (string) $hints['role']
                    ]
                ];
            }

            $resp = $this->executeWithRateLimitRetry(function() use ($idKey, $payload) {
                return $this->getHttpClient()
                    ->withHeaders(['Idempotency-Key' => $idKey, 'Content-Type'=>'application/json'])
                    ->post('/api/v2/clients', $payload)
                    ->throw()->json();
            });

            $clientId = (int)($resp['id'] ?? 0);
            if ($clientId > 0) {
                \Log::info('Successfully created new client', ['client_id' => $clientId, 'client_name' => $resp['name'] ?? 'unknown']);
                $this->ensureContactExists($clientId, $hints);
                return ['id' => $clientId, 'created' => true, 'source' => 'created'];
            }

            \Log::error('Failed to create client - no ID returned', ['response' => $resp]);
            throw new \RuntimeException('Failed to create or resolve client');
        });
    }

    /**
     * Update a single extra field on a client
     */
    private function updateClientExtraField(int $clientId, string $fieldName, $value): void
    {
        if (!$value) return;
        
        try {
            $this->executeWithRateLimitRetry(function() use ($clientId, $fieldName, $value) {
                return $this->getHttpClient()
                    ->withHeaders(['Content-Type' => 'application/merge-patch+json'])
                    ->patch("/api/v2/clients/{$clientId}", [
                        'extraFields' => [
                            $fieldName => ['stringValue' => (string) $value]
                        ]
                    ]);
            });
            
            \Illuminate\Support\Facades\Log::info('Updated client extra field', [
                'client_id' => $clientId,
                'field_name' => $fieldName,
                'value' => $value
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to update client extra field', [
                'client_id' => $clientId,
                'field_name' => $fieldName,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Ensure the contact exists (email is the identity)
     */
    public function ensureContactExists(int $clientId, array $hints): void
    {
        try {
            $email = $this->normEmail($hints['contact_email'] ?? $hints['email'] ?? null);
            $first = $hints['first_name'] ?? $hints['firstName'] ?? null;
            $last  = $hints['last_name']  ?? $hints['surname']   ?? null;
            $tel   = $hints['contact_phone'] ?? $hints['phone'] ?? $hints['tel'] ?? null;
            $gsm   = $hints['contact_mobile'] ?? $hints['mobile'] ?? $hints['gsm'] ?? null;

            // Skip if no email provided
            if (!$email) {
                \Illuminate\Support\Facades\Log::info('Skipping contact creation - no email provided', [
                    'client_id' => $clientId,
                    'hints' => array_keys($hints)
                ]);
                return;
            }

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
                        try {
                            $this->getHttpClient()
                                ->withHeaders(['Content-Type'=>'application/merge-patch+json'])
                                ->patch("/api/v2/clients/{$clientId}/contacts/{$c['id']}", $patch);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('Failed to update existing contact', [
                                'client_id' => $clientId,
                                'contact_id' => $c['id'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    \Illuminate\Support\Facades\Log::info('Contact already exists', [
                        'client_id' => $clientId,
                        'email' => $email,
                        'contact_id' => $c['id']
                    ]);
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
                
            \Illuminate\Support\Facades\Log::info('Contact created successfully', [
                'client_id' => $clientId,
                'email' => $email,
                'first_name' => $first,
                'last_name' => $last
            ]);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to ensure contact exists', [
                'client_id' => $clientId,
                'email' => $email ?? 'N/A',
                'error' => $e->getMessage(),
                'hints' => $hints
            ]);
            // Don't re-throw - contact creation failure shouldn't break client creation
        }
    }

    /**
     * Find or create a contact for a client and return their ID
     * This method is used for quotation contact person linking
     */
    public function findOrCreateClientContactId(int $clientId, array $c): ?int
    {
        $email = isset($c['email']) ? mb_strtolower(trim($c['email'])) : null;

        // A) scan client-scoped contacts (page all; size=100)
        $page = 0; 
        $size = 100;
        
        do {
            $res = $this->getHttpClient()
                ->get("/api/v2/clients/{$clientId}/contacts", ['page'=>$page,'size'=>$size])
                ->throw()->json();

            foreach (($res['items'] ?? $res ?? []) as $ct) {
                // Primary match by email
                if ($email && isset($ct['email']) && mb_strtolower(trim($ct['email'])) === $email) {
                    return (int)$ct['id'];
                }
                
                // Fallback match by surname + (firstName|name) when no email
                $sn = mb_strtolower(trim($ct['surname'] ?? ''));
                $fn = mb_strtolower(trim($ct['firstName'] ?? $ct['name'] ?? ''));
                if ($sn && $fn && $sn === mb_strtolower(trim($c['last_name'] ?? $c['surname'] ?? ''))
                         && $fn === mb_strtolower(trim($c['first_name'] ?? $c['firstName'] ?? ''))) {
                    return (int)$ct['id'];
                }
            }

            $page++;
            $total = (int)($res['totalItems'] ?? 0);
        } while ($page * $size < $total);

        // B) create contact under the client
        $payload = array_filter([
            'title'     => $c['title'] ?? null,
            'firstName' => $c['first_name'] ?? $c['firstName'] ?? null, // harmless if tenant ignores
            'surname'   => $c['last_name']  ?? $c['surname']   ?? ($c['name'] ?? null),
            'function'  => $c['function']   ?? null,
            'email'     => $c['email']      ?? null,
            'tel'       => $c['tel']        ?? $c['phone']  ?? null,
            'gsm'       => $c['gsm']        ?? $c['mobile'] ?? null,
            'isPrimary' => $c['is_primary'] ?? false,
            'receivesQuotes'   => $c['receives_quotes']   ?? true,
            'receivesInvoices' => $c['receives_invoices'] ?? false,
        ], fn($v) => $v !== null && $v !== '');

        $created = $this->getHttpClient()
            ->withHeaders(['Content-Type'=>'application/json','Accept'=>'application/json'])
            ->post("/api/v2/clients/{$clientId}/contacts", $payload)
            ->throw()->json();

        return isset($created['id']) ? (int)$created['id'] : null;
    }

    /**
     * Set the contact on an offer (tries clientContactId, contactPersonId, then contactId)
     */
    public function setOfferContact(int $offerId, int $contactId): bool
    {
        foreach (['clientContactId','contactPersonId','contactId'] as $key) {
            $json = json_encode([$key => $contactId], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $res = $this->getHttpClient()
                ->withHeaders(['Content-Type'=>'application/merge-patch+json','Accept'=>'application/json'])
                ->withBody($json, 'application/merge-patch+json')
                ->patch("/api/v2/offers/{$offerId}");
            
            if ($res->successful()) {
                \Illuminate\Support\Facades\Log::info('Successfully set contact on offer', [
                    'offer_id' => $offerId,
                    'contact_id' => $contactId,
                    'field_used' => $key
                ]);
                return true;
            }

            if ($res->status() === 404) {
                // Self-heal stale offer IDs - log warning and let caller recreate
                \Illuminate\Support\Facades\Log::warning('Offer not found while linking contact; clear stored robaws_offer_id and recreate.', [
                    'offer_id' => $offerId,
                    'contact_id' => $contactId
                ]);
                return false;
            }
            
            if ($res->status() >= 500) break; // don't keep probing on server errors
        }
        
        \Illuminate\Support\Facades\Log::warning('Failed to set contact on offer', [
            'offer_id' => $offerId,
            'contact_id' => $contactId
        ]);
        
        return false;
    }

    /**
     * Build Robaws client payload from normalized customer data
     */
    public function buildRobawsClientPayload(array $c): array
    {
        // Build contacts array
        $contacts = [];
        if (!empty($c['contact']['name']) || !empty($c['contact']['email']) || !empty($c['contact']['phone'])) {
            $contacts[] = array_filter([
                'name' => $c['contact']['name'] ?? null,
                'email' => $c['contact']['email'] ?? null,
                'phone' => $c['contact']['phone'] ?? null,
                'mobile' => $c['contact']['mobile'] ?? null,
            ]);
        }

        // Build addresses array
        $addresses = [];
        if (!empty($c['address']['street']) || !empty($c['address']['city'])) {
            $addresses[] = array_filter([
                'type' => 'billing',
                'street' => $c['address']['street'] ?? null,
                'zip' => $c['address']['zip'] ?? null,
                'city' => $c['address']['city'] ?? null,
                'country' => $c['address']['country'] ?? null,
            ]);
        }

        // Build the main payload
        return array_filter([
            'name' => $c['name'] ?? null,
            'email' => $c['email'] ?? null,
            'tel' => $c['phone'] ?? null,
            'gsm' => $c['mobile'] ?? null,
            'vatNumber' => $c['vat'] ?? null,
            'website' => $c['website'] ?? null,
            'clientType' => $c['client_type'] ?? 'company',
            'addresses' => !empty($addresses) ? $addresses : null,
            'contacts' => !empty($contacts) ? $contacts : null,
        ], fn($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * Public method to access HTTP client for quotation system
     * This allows the quotation system to make API calls while maintaining encapsulation
     */
    public function getHttpClientForQuotation(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->getHttpClient();
    }

    /**
     * Get all articles with pagination and filtering
     * Based on Robaws API: GET /api/v2/articles
     */
    public function getArticles(array $params = []): array
    {
        try {
            // Enforce rate limits before making request
            $this->enforceRateLimit();
            
            // Default parameters
            $query = array_merge([
                'page' => 0,
                'size' => 100, // Robaws max
                'sort' => 'name:asc'
            ], $params);

            $response = $this->getHttpClient()->get('/api/v2/articles', $query);
            
            // Update rate limits from response headers
            $this->updateRateLimitsFromResponse($response);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'articles' => $response->json()['items'] ?? [],
                    'rate_limit' => [
                        'daily_remaining' => $this->dailyRemaining,
                        'daily_limit' => $this->dailyLimit,
                    ],
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
     * Get a single article by ID
     * Based on Robaws API: GET /api/v2/articles/{id}
     */
    public function getArticle(string $articleId, array $include = []): array
    {
        try {
            // Enforce rate limits before making request
            $this->enforceRateLimit();
            
            $query = [];
            if (!empty($include)) {
                $query['include'] = implode(',', $include);
            }

            $response = $this->getHttpClient()->get("/api/v2/articles/{$articleId}", $query);
            
            // Update rate limits from response headers
            $this->updateRateLimitsFromResponse($response);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'article' => $response->json(), // For backward compatibility
                    'rate_limit' => [
                        'daily_remaining' => $this->dailyRemaining,
                        'daily_limit' => $this->dailyLimit,
                    ],
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
     * Update an article in Robaws
     * PATCH /api/v2/articles/{id}
     *
     * @param string|int $articleId Robaws article ID
     * @param array $payload Update payload with extraFields structure
     * @return array Response wrapper: ['success' => bool, 'data'|'error' => mixed, 'status' => int]
     */
    public function updateArticle(string|int $articleId, array $payload): array
    {
        try {
            $this->enforceRateLimit();

            // Explicitly JSON-encode the payload and use merge-patch content type
            // This matches the pattern used in setOfferContact and updateClientContact
            $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            $response = $this->getHttpClient()
                ->withHeaders([
                    'Accept' => 'application/json',
                ])
                ->withBody($jsonBody, 'application/merge-patch+json')
                ->patch("/api/v2/articles/{$articleId}");

            $this->updateRateLimitsFromResponse($response);

            $responseBody = $response->body();
            $responseJson = $response->json();

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $responseJson,
                    'status' => $response->status(),
                    'body' => $responseBody, // Include body for debugging
                ];
            }

            return [
                'success' => false,
                'error' => $responseBody,
                'status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to update Robaws article', [
                'robaws_article_id' => $articleId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    /**
     * Register webhook endpoint with Robaws
     */
    public function registerWebhook(array $payload): array
    {
        try {
            $this->enforceRateLimit();
            
            $response = $this->getHttpClient()->post('/api/v2/webhook-endpoints', $payload);
            
            $this->updateRateLimitsFromResponse($response);

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
            ];
        }
    }

    /**
     * Fetch all articles across all pages
     */
    public function getAllArticlesPaginated(): array
    {
        $allArticles = [];
        $page = 0;
        $size = 100;
        
        do {
            $result = $this->getArticles([
                'page' => $page,
                'size' => $size,
            ]);
            
            if (!$result['success']) {
                \Log::error('Failed to fetch articles page', [
                    'page' => $page,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
                throw new \RuntimeException('Failed to fetch articles: ' . ($result['error'] ?? 'Unknown error'));
            }
            
            $data = $result['data'];
            $items = $data['items'] ?? [];
            $allArticles = array_merge($allArticles, $items);
            
            $page++;
            $totalItems = (int)($data['totalItems'] ?? 0);
            
            \Log::info('Fetched articles page', [
                'page' => $page - 1,
                'items_in_page' => count($items),
                'total_fetched' => count($allArticles),
                'total_items' => $totalItems
            ]);
            
        } while (count($items) === $size && count($allArticles) < $totalItems);
        
        return [
            'success' => true,
            'articles' => $allArticles,
            'total' => count($allArticles)
        ];
    }

    /**
     * Get the daily API call limit
     */
    public function getDailyLimit(): int
    {
        return Cache::get('robaws_daily_limit', $this->dailyLimit);
    }

    /**
     * Get the remaining daily API calls
     */
    public function getDailyRemaining(): int
    {
        return Cache::get('robaws_daily_remaining', $this->dailyRemaining);
    }

    /**
     * Get the per-second rate limit
     */
    public function getPerSecondLimit(): int
    {
        return $this->perSecondLimit;
    }
}
