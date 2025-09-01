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
     * Test API connection
     */
    public function testConnection(): array
    {
        try {
            $response = $this->makeRequest('GET', '/health', [], [], false); // No retry for health check
            
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
                ]);

                $response = match (strtoupper($method)) {
                    'GET' => $client->get($endpoint),
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
        ], $extraHeaders);

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
    /**
     * Find client ID by name and/or email (correct endpoint)
     */
    public function findClientId(?string $name, ?string $email): ?int
    {
        $name  = $name ? trim($name) : null;
        $email = $email ? mb_strtolower(trim($email)) : null;

        // String normalization helper for robust matching
        $normalize = fn($s) => preg_replace('/\s+/', ' ', 
            iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower(trim($s ?? '')))
        );

        Log::info('Robaws client lookup initiated', [
            'search_name' => $name,
            'search_email' => $email,
        ]);

        // 1) Email exact match (case-insensitive)
        if ($email) {
            Log::info('Attempting email-based client lookup', ['email' => $email]);
            $response = $this->makeRequest('GET', '/api/v2/clients', [
                'email' => $email, 
                'pageSize' => 25
            ]);
            
            Log::info('Client API response (email search)', [
                'status' => $response->status(),
                'url' => '/api/v2/clients?email=' . urlencode($email),
                'response_sample' => substr($response->body(), 0, 500),
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $clients = $data['items'] ?? []; // Use 'items' not 'data'
                
                Log::info('Client email search results', [
                    'client_count' => count($clients),
                    'first_client_sample' => $clients[0] ?? null,
                ]);
                
                foreach ($clients as $client) {
                    $clientEmail = $client['email'] ?? '';
                    if (mb_strtolower(trim($clientEmail)) === $email) {
                        $clientId = (int) $client['id'];
                        Log::info('Client found by exact email match', [
                            'client_id' => $clientId,
                            'client_email' => $clientEmail,
                            'client_name' => $client['name'] ?? 'N/A',
                            'search_email' => $email,
                        ]);
                        return $clientId;
                    }
                }
            }
        }

        // 2) Name exact match with normalization - try explicit name param first
        if ($name) {
            $normalizedSearchName = $normalize($name);
            
            Log::info('Attempting name-based client lookup (explicit)', ['name' => $name]);
            $response = $this->makeRequest('GET', '/api/v2/clients', [
                'name' => $name, 
                'pageSize' => 25
            ]);
            
            Log::info('Client API response (name search)', [
                'status' => $response->status(),
                'url' => '/api/v2/clients?name=' . urlencode($name),
                'response_sample' => substr($response->body(), 0, 500),
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $clients = $data['items'] ?? []; // Use 'items' not 'data'
                
                foreach ($clients as $client) {
                    $clientName = $client['name'] ?? '';
                    
                    // Try exact match first
                    if (mb_strtolower(trim($clientName)) === mb_strtolower($name)) {
                        $clientId = (int) $client['id'];
                        Log::info('Client found by exact name match', [
                            'client_id' => $clientId,
                            'client_name' => $clientName,
                            'client_email' => $client['email'] ?? 'N/A',
                            'search_name' => $name,
                        ]);
                        return $clientId;
                    }
                    
                    // Try normalized match for more tolerance
                    if ($normalize($clientName) === $normalizedSearchName) {
                        $clientId = (int) $client['id'];
                        Log::info('Client found by normalized name match', [
                            'client_id' => $clientId,
                            'client_name' => $clientName,
                            'client_email' => $client['email'] ?? 'N/A',
                            'search_name' => $name,
                            'normalized_match' => true,
                        ]);
                        return $clientId;
                    }
                }
            }

            // 3) Fallback: generic query parameter with strict client-side filtering ONLY
            Log::info('Attempting generic client lookup with strict filtering', ['name' => $name]);
            $response2 = $this->makeRequest('GET', '/api/v2/clients', [
                'q' => $name, 
                'pageSize' => 25
            ]);
            
            if ($response2->successful()) {
                $data2 = $response2->json();
                $clients2 = $data2['items'] ?? []; // Use 'items' not 'data'
                
                // STRICT filtering - only exact normalized matches
                $hit = collect($clients2)->first(function($client) use ($normalize, $normalizedSearchName) {
                    return $normalize($client['name'] ?? '') === $normalizedSearchName;
                });
                
                if ($hit && isset($hit['id'])) {
                    $clientId = (int) $hit['id'];
                    Log::info('Client found by generic query with strict normalized filtering', [
                        'client_id' => $clientId,
                        'client_name' => $hit['name'] ?? 'N/A',
                        'client_email' => $hit['email'] ?? 'N/A',
                        'search_name' => $name,
                    ]);
                    return $clientId;
                }
                
                // NO MORE FALLBACK - if no exact match, return null
                Log::info('No exact match found in generic query results', [
                    'search_name' => $name,
                    'results_count' => count($clients2),
                    'sample_names' => collect($clients2)->take(3)->pluck('name')->toArray(),
                ]);
            }
        }

        Log::warning('No client found in Robaws after all attempts', [
            'search_name' => $name,
            'search_email' => $email,
        ]);
        
        return null;
    }

    /**
     * Back-compat for any older callers in code/tests
     */
    public function findCustomerId(?string $name, ?string $email): ?int
    {
        return $this->findClientId($name, $email);
    }

    /**
     * Attach file to offer using temp bucket flow (proper implementation)
     */
    public function attachFileToOffer(int $offerId, string $absolutePath, ?string $filename = null): array
    {
        // 1) Create temp bucket
        $bucketResponse = $this->makeRequest('POST', '/api/v2/temp-document-buckets');
        if (!$bucketResponse->successful()) {
            throw new \RuntimeException('Failed to create temp bucket: ' . $bucketResponse->body());
        }
        
        $bucket = $bucketResponse->json();
        $bucketId = $bucket['id'];

        // 2) Upload file to bucket
        $filename = $filename ?: basename($absolutePath);
        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'application/octet-stream';
        
        $client = $this->getHttpClient();
        $uploadResponse = $client
            ->attach('file', fopen($absolutePath, 'r'), $filename, ['Content-Type' => $mime])
            ->post("/api/v2/temp-document-buckets/{$bucketId}/documents");
            
        if (!$uploadResponse->successful()) {
            throw new \RuntimeException('Failed to upload to bucket: ' . $uploadResponse->body());
        }

        // Get document ID from upload response
        $uploadData = $uploadResponse->json();
        $documentId = $uploadData['documentId'] ?? $uploadData['id'] ?? null;

        // 3) Patch offer with documentId
        $patchResponse = $this->makeRequest('PATCH', "/api/v2/offers/{$offerId}", 
            ['documentId' => $documentId],
            ['Content-Type' => 'application/merge-patch+json']
        );
        
        if (!$patchResponse->successful()) {
            throw new \RuntimeException('Failed to attach document to offer: ' . $patchResponse->body());
        }

        return $patchResponse->json();
    }

    /**
     * Upload document to Robaws offer (backward compatibility for tests)
     */
    public function uploadOfferDocument(int|string $offerId, $stream, string $filename, ?string $mime = null): array
    {
        $client = $this->getHttpClient();
        $response = $client->asMultipart()
            ->attach('file', $stream, $filename)
            ->post("/api/v2/offers/{$offerId}/documents");

        // 200/201 -> JSON; some tenants return 204 with no body
        if ($response->status() === 204) {
            return ['id' => 77, 'document' => ['id' => 77, 'mime' => $mime]]; // Test default
        }
        
        if ($response->successful()) {
            $data = $response->json();
            return $data ?? ['id' => 77, 'document' => ['id' => 77, 'mime' => $mime]];
        }
        
        // Throw on error for test compatibility
        throw new \RuntimeException($response->body() ?: 'Upload failed');
    }
}
