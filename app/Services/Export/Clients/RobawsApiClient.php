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
    public function findClientId(?string $name, ?string $email): ?int
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        $norm = fn(string $s) => trim(mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)));

        Log::info('Robaws client lookup initiated', [
            'search_name' => $name,
            'search_email' => $email,
        ]);

        // ---- 1) Exact email search using specific email parameter
        if ($email) {
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
        }

        // ---- 2) Exact name search using specific name parameter
        if ($name) {
            Log::info('Searching by name with specific name parameter', ['name' => $name]);
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
        }

        Log::warning('No client found after all search methods', [
            'search_name' => $name,
            'search_email' => $email,
        ]);
        
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
     * Back-compat for any older callers in code/tests
     */
    public function findCustomerId(?string $name, ?string $email): ?int
    {
        return $this->findClientId($name, $email);
    }

    /**
     * Upload file and attach to offer using official 2-step flow
     */
    public function attachFileToOffer(int $offerId, string $absolutePath, ?string $filename = null): array
    {
        // Step 1: Upload file to get fileId
        $filename = $filename ?: basename($absolutePath);
        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'application/octet-stream';
        
        $client = $this->getHttpClient();
        $uploadResponse = $client
            ->attach('file', fopen($absolutePath, 'r'), $filename, ['Content-Type' => $mime])
            ->post('/api/v2/files');
            
        if (!$uploadResponse->successful()) {
            throw new \RuntimeException('Failed to upload file: ' . $uploadResponse->body());
        }

        $uploadData = $uploadResponse->json();
        $fileId = $uploadData['id'] ?? $uploadData['fileId'] ?? null;
        
        if (!$fileId) {
            throw new \RuntimeException('No fileId returned from upload: ' . $uploadResponse->body());
        }

        // Step 2: Attach file to offer using fileId
        $attachResponse = $this->makeRequest('POST', "/api/v2/offers/{$offerId}/documents", [
            'fileId' => $fileId
        ]);
        
        if (!$attachResponse->successful()) {
            throw new \RuntimeException('Failed to attach file to offer: ' . $attachResponse->body());
        }

        return $attachResponse->json();
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
