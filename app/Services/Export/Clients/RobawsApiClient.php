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
        $this->baseUrl = rtrim(config('services.robaws.url'), '/');
        $this->apiKey = config('services.robaws.api_key');
        $this->config = config('services.robaws', []);
    }

    /**
     * Create or update quotation with idempotency
     */
    public function createQuotation(array $payload, string $idempotencyKey = null): array
    {
        $idempotencyKey = $idempotencyKey ?? $this->generateIdempotencyKey($payload);
        
        $response = $this->makeRequest('POST', '/quotations', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(),
                'quotation_id' => $response->json()['id'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ];
        }

        // Handle specific error codes
        if ($response->status() === 409) {
            // Conflict - resource already exists with this idempotency key
            return [
                'success' => true,
                'data' => $response->json(),
                'quotation_id' => $response->json()['id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'note' => 'Resource already exists (idempotent)',
            ];
        }

        return [
            'success' => false,
            'error' => $response->json()['message'] ?? 'Unknown error',
            'status' => $response->status(),
            'data' => $response->json(),
        ];
    }

    /**
     * Update existing quotation
     */
    public function updateQuotation(string $quotationId, array $payload, string $idempotencyKey = null): array
    {
        $idempotencyKey = $idempotencyKey ?? $this->generateIdempotencyKey($payload);
        
        $response = $this->makeRequest('PUT', "/quotations/{$quotationId}", $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(),
                'quotation_id' => $quotationId,
                'idempotency_key' => $idempotencyKey,
            ];
        }

        return [
            'success' => false,
            'error' => $response->json()['message'] ?? 'Unknown error',
            'status' => $response->status(),
            'data' => $response->json(),
        ];
    }

    /**
     * Get quotation by ID
     */
    public function getQuotation(string $quotationId): array
    {
        $response = $this->makeRequest('GET', "/quotations/{$quotationId}");

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
                    'GET' => $client->get($this->baseUrl . $endpoint),
                    'POST' => $client->post($this->baseUrl . $endpoint, $payload),
                    'PUT' => $client->put($this->baseUrl . $endpoint, $payload),
                    'DELETE' => $client->delete($this->baseUrl . $endpoint),
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
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Bconnect/1.0 (Laravel)',
            'X-Request-ID' => Str::uuid()->toString(),
        ], $extraHeaders);

        return Http::withHeaders($headers)
            ->timeout($this->config['timeout'] ?? 30)
            ->connectTimeout($this->config['connect_timeout'] ?? 10)
            ->retry(1, 0) // Handle retries manually for better control
            ->withOptions([
                'verify' => $this->config['verify_ssl'] ?? true,
            ]);
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
        
        $hash = hash('sha256', json_encode($critical, JSON_SORT_KEYS));
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
}
