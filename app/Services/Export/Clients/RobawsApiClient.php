<?php

namespace App\Services\Export\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RobawsApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private array $config;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.robaws.base_url'), '/');
        $this->apiKey = config('services.robaws.api_key');
        $this->config = config('services.robaws', []);
        
        // Validate required configuration
        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('Robaws API key is not configured. Set ROBAWS_API_KEY in your .env file.');
        }
        
        if (empty($this->baseUrl)) {
            throw new \InvalidArgumentException('Robaws base URL is not configured. Set ROBAWS_BASE_URL in your .env file.');
        }
    }

    /**
     * Create or update quotation with idempotency
     */
    public function createQuotation(array $payload, string $idempotencyKey = null): array
    {
        $idempotencyKey = $idempotencyKey ?? $this->generateIdempotencyKey($payload);
        
        $response = $this->makeRequest('POST', '/api/v2/offers', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        // Success path
        if ($response->successful() || in_array($response->status(), [200, 201, 202])) {
            $data = $response->json();
            return [
                'success' => true,
                'status' => $response->status(),
                'quotation_id' => $data['id'] ?? $data['offer_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'data' => $data,
            ];
        }

        // Handle specific error codes
        if ($response->status() === 409) {
            // Conflict - resource already exists with this idempotency key
            $data = $response->json();
            return [
                'success' => true,
                'status' => $response->status(),
                'quotation_id' => $data['id'] ?? $data['offer_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'note' => 'Resource already exists (idempotent)',
                'data' => $data,
            ];
        }

        // Failure path with visibility
        Log::warning('Robaws API error', [
            'method' => 'POST',
            'url' => '/api/v2/offers',
            'status' => $response->status(),
            'reason' => $response->reason(),
            'error' => $response->json('message') ?? $response->json('error') ?? Str::limit($response->body(), 240),
            'req_id' => $response->header('X-Request-Id') ?: $response->header('X-Correlation-Id'),
            'auth' => config('services.robaws.auth'),
            'has_basic' => (bool) config('services.robaws.username'),
            'has_token' => (bool) config('services.robaws.token'),
            'idempotency_key' => $idempotencyKey,
        ]);

        return [
            'success' => false,
            'status' => $response->status(),
            'error' => $response->json('message')
                        ?? $response->json('error')
                        ?? $response->reason()
                        ?? 'HTTP '.$response->status(),
            'data' => $response->json() ?: ['body' => $response->body()],
            'request_id' => $response->header('X-Request-Id')
                            ?? $response->header('X-Correlation-Id'),
            'headers' => $response->headers(),
        ];
    }

    /**
     * Update existing quotation
     */
    public function updateQuotation(string $quotationId, array $payload, string $idempotencyKey = null): array
    {
        $idempotencyKey = $idempotencyKey ?? $this->generateIdempotencyKey($payload);
        
        $response = $this->makeRequest('PATCH', "/api/v2/offers/{$quotationId}", $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        // Success path
        if ($response->successful() || in_array($response->status(), [200, 201, 202])) {
            $data = $response->json();
            return [
                'success' => true,
                'status' => $response->status(),
                'quotation_id' => $quotationId,
                'idempotency_key' => $idempotencyKey,
                'data' => $data,
            ];
        }

        // Failure path with visibility
        return [
            'success' => false,
            'status' => $response->status(),
            'error' => $response->json('message')
                        ?? $response->json('error')
                        ?? $response->reason()
                        ?? 'HTTP '.$response->status(),
            'data' => $response->json() ?: ['body' => $response->body()],
            'request_id' => $response->header('X-Request-Id')
                            ?? $response->header('X-Correlation-Id'),
            'headers' => $response->headers(),
        ];
    }

    /**
     * Get quotation by ID
     */
    public function getQuotation(string $quotationId): array
    {
        $response = $this->makeRequest('GET', "/api/v2/offers/{$quotationId}");

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'error' => $response->json()['message'] ?? 'Quotation not found',
            'status' => $response->status(),
        ];
    }

    /**
     * Get offer by ID (alias for getQuotation)
     */
    public function getOffer(string $id): array
    {
        return $this->getQuotation($id);
    }

    /**
     * Test API connection with real endpoint
     */
    public function testConnection(): array
    {
        try {
            // Use a real endpoint instead of /health
            $response = $this->makeRequest('GET', '/api/v2/clients', ['size' => 1], [], false);
            
            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'response_time' => $response->transferStats?->getTransferTime(),
                'data' => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ping API with safe read-only endpoint
     */
    public function ping(): array
    {
        try {
            $response = $this->makeRequest('GET', '/api/v2/clients', ['size' => 1]);
            return [
                'status' => $response->status(),
                'ok' => $response->status() < 400,
                'response_time' => $response->transferStats?->getTransferTime(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 0,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make HTTP request with retry logic and proper headers
     */
    private function makeRequest(
        string $method, 
        string $endpoint, 
        array $payload = [], 
        array $headers = [],
        bool $withRetry = true
    ): Response {
        $attempts = $withRetry ? ($this->config['max_retries'] ?? 3) : 1;
        $baseDelay = $this->config['retry_delay'] ?? 1000; // milliseconds
        
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = $this->getHttpClient($headers);
                
                Log::info('Robaws API Request', [
                    'method' => $method,
                    'url' => $this->baseUrl . $endpoint,
                    'attempt' => $attempt,
                    'payload_size' => strlen(json_encode($payload)),
                    'headers' => array_keys($headers),
                    'payload_summary' => [
                        'client_id' => $payload['clientId'] ?? null,
                        'contact_email' => $payload['contactEmail'] ?? null,
                        'title' => $payload['title'] ?? null,
                        'lines_count' => isset($payload['lines']) ? count($payload['lines']) : 0,
                        'extra_fields_count' => isset($payload['extraFields']) ? count($payload['extraFields']) : 0,
                    ],
                ]);

                $response = match (strtoupper($method)) {
                    'GET' => $client->get($endpoint, $payload), // Pass query parameters for GET
                    'POST' => $client->post($endpoint, $payload),
                    'PUT' => $client->put($endpoint, $payload),
                    'PATCH' => $client->patch($endpoint, $payload),
                    'DELETE' => $client->delete($endpoint),
                    default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
                };

                Log::info('Robaws API Response', [
                    'status' => $response->status(),
                    'attempt' => $attempt,
                    'response_size' => strlen($response->body()),
                    'successful' => $response->successful(),
                    'response_summary' => [
                        'id' => $response->json()['id'] ?? null,
                        'offer_id' => $response->json()['offer_id'] ?? null,
                        'message' => $response->json()['message'] ?? null,
                        'error' => $response->json()['error'] ?? null,
                    ],
                ]);

                // Success or non-retryable error
                if ($response->successful() || !$this->isRetryableError($response)) {
                    return $response;
                }

                // Log retryable error
                Log::warning('Robaws API retryable error', [
                    'status' => $response->status(),
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'error' => $response->json()['message'] ?? 'Unknown error',
                ]);

            } catch (\Exception $e) {
                Log::error('Robaws API request exception', [
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                ]);

                if ($attempt >= $attempts) {
                    throw $e;
                }
            }

            // Calculate exponential backoff delay
            if ($attempt < $attempts) {
                $delay = $baseDelay * pow(2, $attempt - 1); // 1s, 2s, 4s, etc.
                $jitter = rand(0, (int)($delay * 0.1)); // Add 10% jitter
                usleep(($delay + $jitter) * 1000); // Convert to microseconds
            }
        }

        throw new \RuntimeException('Max retry attempts exceeded');
    }

    /**
     * Configure HTTP client with proper headers and timeouts
     */
    private function getHttpClient(array $extraHeaders = []): PendingRequest
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Bconnect/1.0 (Laravel)',
            'X-Request-ID' => Str::uuid()->toString(),
        ], array_filter([
            'X-Company-ID' => config('services.robaws.default_company_id'),
            'X-Tenant-ID' => config('services.robaws.tenant_id'),
        ]), $extraHeaders);

        $http = Http::baseUrl($this->baseUrl)
            ->withHeaders($headers)
            ->timeout($this->config['timeout'] ?? 30)
            ->connectTimeout($this->config['connect_timeout'] ?? 10)
            ->retry(1, 0) // Handle retries manually for better control
            ->withOptions([
                'verify' => $this->config['verify_ssl'] ?? true,
            ]);

        // Configure authentication based on config
        if (config('services.robaws.auth') === 'basic') {
            $http = $http->withBasicAuth(
                config('services.robaws.username'),
                config('services.robaws.password')
            );
        } else {
            // Bearer token authentication
            $token = config('services.robaws.token') ?? config('services.robaws.api_key');
            if ($token) {
                $http = $http->withToken($token);
            }
        }

        return $http;
    }

    /**
     * Determine if an error is retryable
     */
    private function isRetryableError(Response $response): bool
    {
        $status = $response->status();
        
        // 5xx server errors are retryable
        if ($status >= 500) {
            return true;
        }
        
        // Some 4xx errors are retryable
        $retryable4xx = [408, 429]; // Request Timeout, Too Many Requests
        if (in_array($status, $retryable4xx)) {
            return true;
        }
        
        return false;
    }

    /**
     * Generate idempotency key from payload
     */
    private function generateIdempotencyKey(array $payload): string
    {
        // Create stable hash from critical fields
        $critical = [
            'customer' => $payload['quotation_info']['customer'] ?? '',
            'project' => $payload['quotation_info']['project'] ?? '',
            'cargo' => $payload['cargo_details']['cargo'] ?? '',
            'por' => $payload['routing']['por'] ?? '',
            'pod' => $payload['routing']['pod'] ?? '',
        ];
        
        $hash = hash('sha256', json_encode($critical, \JSON_SORT_KEYS|\JSON_UNESCAPED_UNICODE));
        return 'bconnect_' . substr($hash, 0, 32);
    }

    /**
     * Validate API configuration
     */
    public function validateConfig(): array
    {
        $issues = [];
        
        if (empty($this->baseUrl)) {
            $issues[] = 'Missing Robaws API URL';
        }
        
        if (empty($this->apiKey)) {
            $issues[] = 'Missing Robaws API key';
        }
        
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            $issues[] = 'Invalid Robaws API URL format';
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'config' => [
                'url' => $this->baseUrl,
                'has_api_key' => !empty($this->apiKey),
                'timeout' => $this->config['timeout'] ?? 30,
                'max_retries' => $this->config['max_retries'] ?? 3,
            ],
        ];
    }

    /**
     * Find customer ID by email or name for proper Robaws customer assignment
     */
    public function findClientId(?string $name, ?string $email, ?string $phone = null): ?int
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        $norm = fn(string $s) => trim(mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)));

        Log::info('Robaws client lookup initiated', [
            'search_name' => $name,
            'search_email' => $email,
            'search_phone' => $phone,
        ]);

        // ---- 1) Exact email search using specific email parameter
        if ($email) {
            $clientId = $this->findClientIdByEmail($email);
            if ($clientId) return $clientId;
        }

        // ---- 2) Exact phone search (strict)
        if ($phone) {
            $clientId = $this->findClientIdByPhone($phone);
            if ($clientId) return $clientId;
        }

        // ---- 3) Exact name search using specific name parameter
        if ($name) {
            $clientId = $this->findClientIdByName($name);
            if ($clientId) return $clientId;
        }

        Log::warning('No client found after all search methods', [
            'search_name' => $name,
            'search_email' => $email,
            'search_phone' => $phone,
        ]);
        
        return null;
    }

    /**
     * Find client ID by email with exact matching
     */
    private function findClientIdByEmail(string $email): ?int
    {
        Log::info('Searching by email with specific email parameter', ['email' => $email]);
        
        $response = $this->get('/api/v2/clients', [
            'email' => $email,
            'size' => 100
        ]);
        
        $items = $response['items'] ?? [];
        
        Log::info('Email search results', [
            'items_count' => count($items),
            'email' => $email
        ]);
        
        // Check for exact email match in client.email
        foreach ($items as $client) {
            $clientEmail = mb_strtolower(trim($client['email'] ?? ''));
            if ($clientEmail === $email) {
                $clientId = (int) $client['id'];
                Log::info('Client found by exact email match', [
                    'client_id' => $clientId,
                    'client_name' => $client['name'] ?? 'N/A',
                    'client_email' => $client['email'] ?? 'N/A',
                ]);
                return $clientId;
            }
        }
        
        // If no direct email match, check contacts for each client
        foreach ($items as $client) {
            $contacts = $this->get("/api/v2/clients/{$client['id']}/contacts", ['size' => 100]);
            $contactItems = $contacts['items'] ?? [];
            
            foreach ($contactItems as $contact) {
                $contactEmail = mb_strtolower(trim($contact['email'] ?? ''));
                if ($contactEmail === $email) {
                    $clientId = (int) $client['id'];
                    Log::info('Client found by contact email match', [
                        'client_id' => $clientId,
                        'client_name' => $client['name'] ?? 'N/A',
                        'contact_name' => $contact['name'] ?? 'N/A',
                        'contact_email' => $contact['email'] ?? 'N/A',
                    ]);
                    return $clientId;
                }
            }
        }
        
        // Fallback: try general search with email
        Log::info('Trying general search with email', ['email' => $email]);
        $fallbackResponse = $this->get('/api/v2/clients', [
            'search' => $email,
            'size' => 100
        ]);
        
        $fallbackItems = $fallbackResponse['items'] ?? [];
        foreach ($fallbackItems as $client) {
            $clientEmail = mb_strtolower(trim($client['email'] ?? ''));
            if ($clientEmail === $email) {
                $clientId = (int) $client['id'];
                Log::info('Client found by fallback email search', [
                    'client_id' => $clientId,
                    'client_name' => $client['name'] ?? 'N/A',
                    'client_email' => $client['email'] ?? 'N/A',
                ]);
                return $clientId;
            }
        }

        return null;
    }

    /**
     * Strict phone resolver:
     * 1) Try /clients?phone=...
     * 2) If not found, try /clients?search=<last-7-9-digits>
     * 3) For any candidate, fetch /clients/{id}/contacts and compare phones
     * Returns clientId when unique; null when 0 or multiple matches.
     */
    public function findClientIdByPhone(string $phone, ?string $defaultCountry = null): ?int
    {
        $needle = $this->normalizePhone($phone, $defaultCountry);
        if (!$needle) return null;

        Log::info('Searching by phone number', [
            'original_phone' => $phone,
            'normalized_phone' => $needle
        ]);

        // A) direct phone param (if API supports it)
        $resp = $this->get('/api/v2/clients', ['phone' => $needle, 'size' => 50]);
        $cands = $resp['items'] ?? [];

        // B) widen by search tail if needed
        if (empty($cands)) {
            $tail = substr($needle, -9);
            $resp = $this->get('/api/v2/clients', ['search' => $tail, 'size' => 50]);
            $cands = $resp['items'] ?? [];
        }

        $matches = [];

        foreach ($cands as $c) {
            $cid = $c['id'] ?? null;

            // compare client-level phones if present
            foreach (['telephone', 'mobile', 'phone'] as $key) {
                if (!empty($c[$key]) && $this->phonesEqual($c[$key], $needle)) {
                    $matches[$cid] = $c;
                    Log::info('Phone match found in client data', [
                        'client_id' => $cid,
                        'client_name' => $c['name'] ?? 'N/A',
                        'matched_field' => $key,
                        'client_phone' => $c[$key]
                    ]);
                }
            }

            // fetch & compare each contact's phones
            if ($cid) {
                $contacts = $this->get("/api/v2/clients/{$cid}/contacts", ['size' => 50]);
                foreach (($contacts['items'] ?? []) as $ct) {
                    foreach (['telephone', 'mobile', 'phone'] as $pkey) {
                        if (!empty($ct[$pkey]) && $this->phonesEqual($ct[$pkey], $needle)) {
                            $matches[$cid] = $c;
                            Log::info('Phone match found in contact data', [
                                'client_id' => $cid,
                                'client_name' => $c['name'] ?? 'N/A',
                                'contact_name' => $ct['name'] ?? 'N/A',
                                'matched_field' => $pkey,
                                'contact_phone' => $ct[$pkey]
                            ]);
                        }
                    }
                }
            }
        }

        if (count($matches) === 1) {
            $clientId = (int) array_key_first($matches);
            Log::info('Unique phone match found', [
                'client_id' => $clientId,
                'phone' => $needle
            ]);
            return $clientId;
        }

        if (count($matches) > 1) {
            Log::warning('Ambiguous phone match - multiple clients found', [
                'phone' => $needle,
                'matching_client_ids' => array_keys($matches),
                'match_count' => count($matches)
            ]);
        }

        // ambiguous or none → be safe and return null
        return null;
    }

    /**
     * Find client ID by name with exact normalized matching
     */
    private function findClientIdByName(string $name): ?int
    {
        Log::info('Searching by name with specific name parameter', ['name' => $name]);
        $norm = fn(string $s) => trim(mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)));
        $normalizedName = $norm($name);
        
        $response = $this->get('/api/v2/clients', [
            'name' => $name,
            'size' => 100
        ]);
        
        $items = $response['items'] ?? [];
        
        Log::info('Name search results', [
            'items_count' => count($items),
            'name' => $name
        ]);
        
        // Check for exact normalized name match
        foreach ($items as $client) {
            $clientName = $norm($client['name'] ?? '');
            if ($clientName === $normalizedName) {
                $clientId = (int) $client['id'];
                Log::info('Client found by exact name match', [
                    'client_id' => $clientId,
                    'client_name' => $client['name'] ?? 'N/A',
                    'normalized_match' => true,
                ]);
                return $clientId;
            }
        }
        
        // Fallback: try general search with name
        Log::info('Trying general search with name', ['name' => $name]);
        $fallbackResponse = $this->get('/api/v2/clients', [
            'search' => $name,
            'size' => 100
        ]);
        
        $fallbackItems = $fallbackResponse['items'] ?? [];
        foreach ($fallbackItems as $client) {
            $clientName = $norm($client['name'] ?? '');
            if ($clientName === $normalizedName) {
                $clientId = (int) $client['id'];
                Log::info('Client found by fallback name search', [
                    'client_id' => $clientId,
                    'client_name' => $client['name'] ?? 'N/A',
                    'normalized_match' => true,
                ]);
                return $clientId;
            }
        }

        return null;
    }

    /**
     * Helper method to make GET requests for cleaner code
     */
    private function get(string $endpoint, array $params = []): array
    {
        $response = $this->makeRequest('GET', $endpoint, $params);
        return $response->successful() ? $response->json() : [];
    }

    /**
     * Normalize phone number for consistent comparison
     */
    private function normalizePhone(?string $raw, ?string $defaultCountry = null): ?string
    {
        if (!$raw) return null;

        // keep digits, keep leading +
        $raw = preg_replace('/[^\d+]/', '', $raw ?? '');
        if ($raw === '') return null;

        // if it doesn't start with + and caller gave a country (e.g. "BE", "NL"),
        // you can optionally prepend country code here (left as no-op by default)
        return $raw;
    }

    /**
     * Compare two phone numbers with fuzzy matching
     */
    private function phonesEqual(string $a, string $b): bool
    {
        $a = $this->normalizePhone($a);
        $b = $this->normalizePhone($b);
        if (!$a || !$b) return false;

        // exact OR last 7–9 digits match (handles different formats)
        if ($a === $b) return true;
        $tail = 9;
        return substr($a, -$tail) === substr($b, -$tail);
    }

    /**
     * Back-compat for any older callers in code/tests
     */
    public function findCustomerId(?string $name, ?string $email): ?int
    {
        return $this->findClientId($name, $email);
    }

    /**
     * Find exact offer ID by number + client - critical for file uploads
     */
    public function findOfferId(string $number, ?int $clientId = null): ?int
    {
        $response = $this->makeRequest('GET', '/api/v2/offers', [
            'search' => $number,
            'size' => 10,
            'page' => 0
        ]);

        if (!$response->successful()) {
            Log::warning('Offer search failed', [
                'number' => $number,
                'client_id' => $clientId,
                'status' => $response->status()
            ]);
            return null;
        }

        $data = $response->json();
        $items = $data['items'] ?? [];

        // Filter by clientId if provided (critical for avoiding wrong offers)
        if ($clientId) {
            $items = array_values(array_filter($items, fn($o) => (int)($o['clientId'] ?? 0) === $clientId));
            Log::info('Filtered offers by client ID', [
                'number' => $number,
                'client_id' => $clientId,
                'filtered_count' => count($items)
            ]);
        }

        // Look for exact number match first (e.g., O251114)
        foreach ($items as $offer) {
            if (($offer['logicId'] ?? '') === $number || ($offer['number'] ?? '') === $number) {
                $offerId = (int) $offer['id'];
                Log::info('Found exact offer match', [
                    'number' => $number,
                    'client_id' => $clientId,
                    'offer_id' => $offerId,
                    'title' => $offer['title'] ?? 'N/A'
                ]);
                return $offerId;
            }
        }

        // If single result and no exact match, return it (fuzzy match)
        if (count($items) === 1) {
            $offerId = (int) $items[0]['id'];
            Log::info('Single fuzzy offer match', [
                'number' => $number,
                'client_id' => $clientId,
                'offer_id' => $offerId,
                'actual_number' => $items[0]['logicId'] ?? $items[0]['number'] ?? 'N/A',
                'title' => $items[0]['title'] ?? 'N/A'
            ]);
            return $offerId;
        }

        Log::warning('No unique offer found', [
            'number' => $number,
            'client_id' => $clientId,
            'matches' => count($items)
        ]);

        return null;
    }

    /**
     * Get offer by human-readable number (e.g., "Q251114") - backward compatibility
     */
    public function getOfferByNumber(string $offerNumber): ?array
    {
        $offerId = $this->findOfferId($offerNumber);
        if (!$offerId) return null;

        $response = $this->makeRequest('GET', "/api/v2/offers/{$offerId}");
        return $response->successful() ? $response->json() : null;
    }

    /**
     * Upload file and attach to offer - updated to use new methods
     */
    public function attachFileToOffer(int $offerId, string $absolutePath, ?string $filename = null): array
    {
        $filename = $filename ?: basename($absolutePath);
        $fileSize = filesize($absolutePath);
        
        Log::info('Starting file upload to offer', [
            'offer_id' => $offerId,
            'filename' => $filename,
            'file_size' => $fileSize
        ]);

        // Choose upload method based on file size
        if ($fileSize <= 6 * 1024 * 1024) { // 6MB or less - use direct upload
            $result = $this->uploadOfferDocumentDirect($offerId, $absolutePath, $filename);
            if ($result['success']) {
                return $result['data'];
            }
            throw new \RuntimeException('Direct upload failed: ' . ($result['error'] ?? 'Unknown error'));
        } else {
            $result = $this->uploadViaTempBucket($offerId, $absolutePath, $filename);
            if ($result['success']) {
                return $result['data'];
            }
            throw new \RuntimeException('Temp bucket upload failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    /**
     * Direct upload to offer (≤ 6MB) - fixed with proper parameters
     */
    public function uploadOfferDocumentDirect(int $offerId, string $absPath, ?string $filename = null): array
    {
        if (!is_readable($absPath)) {
            return ['success' => false, 'error' => 'File not readable'];
        }
        
        if (filesize($absPath) === 0) {
            return ['success' => false, 'error' => 'Zero-byte file'];
        }

        $filename = $filename ?: basename($absPath);
        $contentType = $this->detectMime($absPath) ?? 'application/octet-stream';

        Log::info('Direct upload starting', [
            'offer_id' => $offerId,
            'filename' => $filename,
            'content_type' => $contentType,
            'file_size' => filesize($absPath)
        ]);

        $response = $this->http()
            ->asMultipart()
            ->attach('file', fopen($absPath, 'rb'), $filename)
            ->attach('name', $filename)
            ->attach('contentType', $contentType)
            ->post("/api/v2/offers/{$offerId}/documents");

        if ($response->successful()) {
            $data = $response->json();
            Log::info('Direct upload successful', [
                'offer_id' => $offerId,
                'document_id' => $data['id'] ?? 'N/A',
                'status' => $response->status()
            ]);
            return ['success' => true, 'data' => $data];
        }

        Log::error('Direct upload failed', [
            'offer_id' => $offerId,
            'status' => $response->status(),
            'error' => $response->body()
        ]);

        return [
            'success' => false,
            'status' => $response->status(),
            'error' => $response->body()
        ];
    }

    /**
     * Temp bucket upload method - fixed with proper name parameter
     */
    public function uploadViaTempBucket(int $offerId, string $absPath, ?string $filename = null): array
    {
        if (!is_readable($absPath) || filesize($absPath) === 0) {
            return ['success' => false, 'error' => 'File missing or zero-byte'];
        }

        $filename = $filename ?: basename($absPath);
        $contentType = $this->detectMime($absPath) ?? 'application/octet-stream';

        Log::info('Temp bucket upload starting', [
            'offer_id' => $offerId,
            'filename' => $filename,
            'content_type' => $contentType,
            'file_size' => filesize($absPath)
        ]);

        // A) Create bucket
        $bucketResponse = $this->makeRequest('POST', '/api/v2/temp-document-buckets', []);
        if (!$bucketResponse->successful()) {
            return ['success' => false, 'error' => 'Failed to create temp bucket'];
        }

        $bucketData = $bucketResponse->json();
        $bucketId = $bucketData['id'] ?? null;
        if (!$bucketId) {
            return ['success' => false, 'error' => 'No bucket ID returned'];
        }

        Log::info('Temp bucket created', ['bucket_id' => $bucketId]);

        // B) Upload to bucket (multipart) - use same approach as direct upload
        $uploadResponse = $this->http()
            ->asMultipart()
            ->attach('file', fopen($absPath, 'rb'), $filename)
            ->attach('name', $filename)
            ->attach('contentType', $contentType)
            ->post("/api/v2/temp-document-buckets/{$bucketId}/documents");

        if (!$uploadResponse->successful()) {
            Log::error('Temp bucket file upload failed', [
                'bucket_id' => $bucketId,
                'status' => $uploadResponse->status(),
                'error' => $uploadResponse->body()
            ]);
            return [
                'success' => false,
                'status' => $uploadResponse->status(),
                'error' => $uploadResponse->body()
            ];
        }

        Log::info('File uploaded to temp bucket', ['bucket_id' => $bucketId]);

        // C) Attach to offer (critical: include name parameter)
        $attachResponse = $this->makeRequest('POST', "/api/v2/offers/{$offerId}/documents", [
            'tempDocumentBucketId' => $bucketId,
            'name' => $filename,
        ]);

        if ($attachResponse->successful()) {
            $data = $attachResponse->json();
            Log::info('Document attached to offer successfully', [
                'offer_id' => $offerId,
                'document_id' => $data['id'] ?? 'N/A'
            ]);
            return ['success' => true, 'data' => $data];
        }

        return [
            'success' => false,
            'status' => $attachResponse->status(),
            'error' => $attachResponse->body()
        ];
    }

    /**
     * Detect MIME type for proper Content-Type headers
     */
    private function detectMime(string $path): ?string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if ($mime) return $mime;
        }

        // Fallback based on extension
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
            'eml' => 'message/rfc822',
            'msg' => 'application/vnd.ms-outlook',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'zip' => 'application/zip',
        ];

        return $mimeMap[$ext] ?? 'application/octet-stream';
    }

    /**
     * Get HTTP client for multipart uploads (no JSON headers)
     */
    private function http(): PendingRequest
    {
        $http = Http::baseUrl($this->baseUrl)
            ->timeout($this->config['timeout'] ?? 30)
            ->connectTimeout($this->config['connect_timeout'] ?? 10)
            ->withOptions([
                'verify' => $this->config['verify_ssl'] ?? true,
            ]);

        // Configure authentication
        if (config('services.robaws.auth') === 'basic') {
            $http = $http->withBasicAuth(
                config('services.robaws.username'),
                config('services.robaws.password')
            );
        } else {
            $token = config('services.robaws.token') ?? config('services.robaws.api_key');
            if ($token) {
                $http = $http->withToken($token);
            }
        }

        return $http;
    }

    /**
     * Upload document to Robaws offer (backward compatibility for tests)
     */
    public function uploadOfferDocument(int|string $offerId, $stream, string $filename, ?string $mime = null): array
    {
        // Use the new 2-step flow for consistency
        $tempFile = tempnam(sys_get_temp_dir(), 'robaws_upload_');
        try {
            file_put_contents($tempFile, stream_get_contents($stream));
            $result = $this->attachFileToOffer((int) $offerId, $tempFile, $filename);
            
            // Return test-compatible format
            return [
                'id' => $result['id'] ?? 77,
                'document' => [
                    'id' => $result['id'] ?? 77,
                    'mime' => $mime ?? 'application/octet-stream'
                ]
            ];
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            if (is_resource($stream)) {
                rewind($stream);
            }
        }
    }
}
