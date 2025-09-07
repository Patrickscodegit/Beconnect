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

    /** Search clients directly by email (more reliable than contacts endpoint) */
    public function findContactByEmail(string $email): ?array
    {
        // Search through clients directly since contacts endpoint doesn't have reliable email data
        $page = 0;
        $size = 100;
        $maxPages = 50; // reasonable limit to avoid infinite loops
        
        do {
            $res = $this->getHttpClient()
                ->get('/api/v2/clients', ['page' => $page, 'size' => $size, 'sort' => 'name:asc'])
                ->throw()
                ->json();

            $clients = $res['items'] ?? [];
            
            foreach ($clients as $client) {
                if (!empty($client['email']) && strcasecmp($client['email'], $email) === 0) {
                    return $client; // Return the client directly since it has all needed info
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
     * Create a new client in Robaws
     */
    public function createClient(array $clientData): ?array
    {
        try {
            // Ensure we have at least a name
            if (empty($clientData['name'])) {
                $clientData['name'] = 'Unknown Customer';
            }
            
            // Set default email if not provided (some Robaws setups require it)
            if (empty($clientData['email'])) {
                $clientData['email'] = 'noreply@bconnect.com';
            }
            
            $response = $this->getHttpClient()
                ->post('/api/v2/clients', $clientData)
                ->throw()
                ->json();
            
            if ($response && isset($response['id'])) {
                \Illuminate\Support\Facades\Log::info('Created new Robaws client', [
                    'client_id' => $response['id'],
                    'name' => $clientData['name']
                ]);
                
                return $response;
            }
            
            return null;
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create Robaws client', [
                'error' => $e->getMessage(),
                'data' => $clientData
            ]);
            
            // Try with minimal data if the first attempt fails
            if (count($clientData) > 2) {
                return $this->createClient([
                    'name' => $clientData['name'],
                    'email' => $clientData['email'] ?? 'noreply@bconnect.com'
                ]);
            }
            
            return null;
        }
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
     */
    private function getFileContent(string $filePath): string
    {
        // Normalize the file path - remove storage disk prefix if present
        $normalizedPath = $filePath;
        $documentsRoot = storage_path('app/documents');
        if (str_starts_with($filePath, $documentsRoot)) {
            $normalizedPath = str_replace($documentsRoot . '/', '', $filePath);
        }
        
        // Try Storage facade first for cloud storage compatibility
        $disk = \Illuminate\Support\Facades\Storage::disk('documents');
        
        // Try the normalized path first
        if ($disk->exists($normalizedPath)) {
            return $disk->get($normalizedPath);
        }
        
        // Try the original path with Storage facade
        if ($disk->exists($filePath)) {
            return $disk->get($filePath);
        }
        
        // Try direct file access for absolute paths
        if (file_exists($filePath)) {
            return file_get_contents($filePath);
        }
        
        // Try with the documents root prepended
        $fullPath = $documentsRoot . '/' . ltrim($normalizedPath, '/');
        if (file_exists($fullPath)) {
            return file_get_contents($fullPath);
        }
        
        // Provide detailed error information
        $attempts = [
            "Storage disk 'documents' with normalized path: {$normalizedPath}",
            "Storage disk 'documents' with original path: {$filePath}",
            "Direct file access: {$filePath}",
            "Direct file access (full path): {$fullPath}"
        ];
        
        throw new \Exception("File not found after trying multiple paths: " . implode(', ', $attempts));
    }
}
