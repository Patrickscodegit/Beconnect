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
}
