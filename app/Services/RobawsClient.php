<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\RobawsException;

class RobawsClient
{
    private string $baseUrl;
    private ?string $username;
    private ?string $password;
    private ?string $apiKey;
    private ?string $apiSecret;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.robaws.base_url', 'https://app.robaws.com');
        $this->username = config('services.robaws.username');
        $this->password = config('services.robaws.password');
        $this->apiKey = config('services.robaws.api_key');
        $this->apiSecret = config('services.robaws.api_secret');
        $this->timeout = config('services.robaws.timeout', 60);
        
        // Debug logging to help track down null values
        if (!$this->username || !$this->password) {
            Log::warning('RobawsClient: Missing credentials', [
                'username' => $this->username,
                'password_set' => !empty($this->password),
                'config_username' => config('services.robaws.username'),
                'config_password_set' => !empty(config('services.robaws.password')),
                'env_username' => env('ROBAWS_USERNAME'),
                'env_password_set' => !empty(env('ROBAWS_PASSWORD')),
            ]);
        }
    }
    
    /**
     * Validate that required credentials are present
     * 
     * @throws RobawsException
     */
    private function validateCredentials(): void
    {
        if (empty($this->baseUrl)) {
            throw new RobawsException('Robaws base URL is not configured. Please set ROBAWS_BASE_URL in your .env file.');
        }
        
        if (empty($this->username) || empty($this->password)) {
            throw new RobawsException('Robaws credentials are not configured. Please set ROBAWS_USERNAME and ROBAWS_PASSWORD in your .env file.');
        }
    }

    /**
     * Make an authenticated HTTP request
     */
    private function makeRequest()
    {
        $this->validateCredentials();
        
        return Http::timeout($this->timeout)
            ->withBasicAuth($this->username, $this->password)
            ->acceptJson();
    }

    /**
     * Test API connection
     */
    public function testConnection(): array
    {
        if (!$this->username || !$this->password) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Robaws credentials not configured. Please set ROBAWS_USERNAME and ROBAWS_PASSWORD in .env'
            ];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->get($this->baseUrl . '/api/v2/clients', ['limit' => 1]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'message' => 'Connection successful',
                    'data' => $response->json()
                ];
            }

            return [
                'success' => false,
                'status' => $response->status(),
                'message' => 'Connection failed: ' . $response->body(),
                'headers' => $response->headers()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * List clients
     */
    public function listClients(array $params = []): array
    {
        try {
            $response = $this->makeRequest()
                ->get($this->baseUrl . '/api/v2/clients', $params);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            Log::error('Failed to list clients', [
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'data' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Search clients
     */
    public function searchClients(array $params = []): array
    {
        // If searching by email, use the search endpoint
        if (isset($params['email'])) {
            try {
                $response = $this->makeRequest()
                    ->get($this->baseUrl . '/api/v2/clients/search', [
                        'query' => $params['email']
                    ]);

                return $this->handleResponse($response);
            } catch (\Exception $e) {
                Log::error('Failed to search clients', [
                    'params' => $params,
                    'error' => $e->getMessage()
                ]);
                
                return [
                    'success' => false,
                    'data' => [],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Fallback to regular list
        return $this->listClients($params);
    }

    /**
     * Find or create client
     */
    public function findOrCreateClient(array $clientData): array
    {
        // First try to find existing client by email
        if (!empty($clientData['email'])) {
            $existing = $this->searchClients(['email' => $clientData['email']]);
            if ($existing['success'] && !empty($existing['data']['items'])) {
                Log::info('Found existing Robaws client', [
                    'client_id' => $existing['data']['items'][0]['id'],
                    'email' => $clientData['email']
                ]);
                return $existing['data']['items'][0];
            }
        }

        // Create new client
        try {
            // Prepare address data in the correct format
            $addressData = null;
            if (!empty($clientData['address'])) {
                $addressData = [
                    'addressLine1' => $clientData['address'],
                    'addressLine2' => null,
                    'postalCode' => $clientData['postalCode'] ?? null,
                    'city' => $clientData['city'] ?? null,
                    'country' => $clientData['country'] ?? 'BE', // Default to Belgium
                    'latitude' => 0,
                    'longitude' => 0
                ];
            }
            
            $response = $this->makeRequest()
                ->post($this->baseUrl . '/api/v2/clients', [
                    'name' => $clientData['name'],
                    'email' => $clientData['email'] ?? null,
                    'tel' => $clientData['tel'] ?? null,
                    'address' => $addressData,
                ]);

            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                Log::info('Created new Robaws client', [
                    'client_id' => $result['data']['id'],
                    'client_name' => $clientData['name']
                ]);
                return $result['data'];
            }
            
            throw new RobawsException('Failed to create client: ' . ($result['error'] ?? 'Unknown error'));
        } catch (RobawsException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create client', [
                'client_data' => $clientData,
                'error' => $e->getMessage()
            ]);
            
            throw new RobawsException('Failed to create client: ' . $e->getMessage());
        }
    }

    /**
     * Create offer in Robaws with robust clientId validation
     */
    public function createOffer(array $offerData): array
    {
        // Guard: fail early with a clear message
        if (!array_key_exists('clientId', $offerData) || empty($offerData['clientId'])) {
            Log::error('createOffer(): missing clientId', ['offer_data_keys' => array_keys($offerData)]);
            throw new RobawsException('createOffer(): "clientId" is required but missing.');
        }

        try {
            $response = $this->makeRequest()
                ->post($this->baseUrl . '/api/v2/offers', $offerData);

            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                Log::info('Created Robaws offer', [
                    'offer_id' => $result['data']['id'] ?? null,
                    'client_id' => $offerData['clientId'] ?? null, // safe access
                ]);
                return $result['data'];
            }

            throw new RobawsException('Failed to create offer: ' . ($result['error'] ?? 'Unknown error'));
        } catch (RobawsException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create offer', [
                'offer_data_keys' => array_keys($offerData),
                'error' => $e->getMessage(),
            ]);
            
            throw new RobawsException('Failed to create offer: ' . $e->getMessage());
        }
    }

    /**
     * Get offer from Robaws
     */
    public function getOffer(string $offerId): array
    {
        try {
            $response = $this->makeRequest()
                ->get($this->baseUrl . '/api/v2/offers/' . $offerId);

            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                Log::info('Retrieved Robaws offer', [
                    'offer_id' => $offerId
                ]);
                return $result['data'];
            }

            Log::error('Failed to get offer', [
                'offer_id' => $offerId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            
            throw new RobawsException('Failed to get offer: ' . ($result['error'] ?? 'Unknown error'));
        } catch (RobawsException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get offer', [
                'offer_id' => $offerId,
                'error' => $e->getMessage()
            ]);
            
            throw new RobawsException('Failed to get offer: ' . $e->getMessage());
        }
    }

    /**
     * Update offer in Robaws 
     */
    public function updateOffer(string $offerId, array $payload): void
    {
        try {
            $response = $this->makeRequest()
                ->asJson()
                ->put($this->baseUrl . '/api/v2/offers/' . $offerId, $payload);

            if (!in_array($response->status(), [200, 204], true)) {
                throw new RobawsException("Robaws PUT offers/{$offerId} failed: " . $response->status());
            }

            Log::info('Successfully updated Robaws offer', [
                'offer_id' => $offerId,
                'status' => $response->status()
            ]);
        } catch (RobawsException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update offer', [
                'offer_id' => $offerId,
                'error' => $e->getMessage()
            ]);
            
            throw new RobawsException('Failed to update offer: ' . $e->getMessage());
        }
    }

    /**
     * Update offer in Robaws with extraFields using GET → PUT pattern
     */
    public function updateOfferWithExtraFields(string $offerId, array $fullPayload): ?array
    {
        try {
            $response = $this->makeRequest()
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put($this->baseUrl . '/api/v2/offers/' . $offerId, $fullPayload);

            Log::info('PUT request to Robaws', [
                'offer_id' => $offerId,
                'status' => $response->status(),
                'payload_keys' => array_keys($fullPayload),
                'has_extra_fields' => isset($fullPayload['extraFields']),
                'extra_fields_count' => isset($fullPayload['extraFields']) ? count($fullPayload['extraFields']) : 0
            ]);
            
            if ($response->status() === 204) {
                // 204 No Content means success, get the updated offer
                Log::info('Successfully updated Robaws offer with extraFields', [
                    'offer_id' => $offerId
                ]);
                return $this->getOffer($offerId);
            }
            
            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                Log::info('Updated Robaws offer with extraFields', [
                    'offer_id' => $offerId
                ]);
                return $result['data'];
            }

            throw new RobawsException('Failed to update offer with extraFields: ' . ($result['error'] ?? 'Unknown error'));
        } catch (\Exception $e) {
            Log::error('Failed to update offer with extraFields', [
                'offer_id' => $offerId,
                'error' => $e->getMessage(),
                'response_status' => isset($response) ? $response->status() : 'no response',
                'response_body' => isset($response) ? substr($response->body(), 0, 500) : 'no response'
            ]);
            
            throw new RobawsException('Failed to update offer with extraFields: ' . $e->getMessage());
        }
    }

    /**
     * Upload document to Robaws (supports stream data for cloud storage)
     */
    public function uploadDocument(string $quotationId, array $fileData): array
    {
        try {
            // Handle stream data for cloud storage compatibility
            if (isset($fileData['stream'])) {
                $fileContent = stream_get_contents($fileData['stream']);
                $fileName = $fileData['filename'] ?? 'upload.bin';
                $mimeType = $fileData['mime_type'] ?? $fileData['mime'] ?? 'application/octet-stream';
                $fileSize = $fileData['file_size'] ?? $fileData['size'] ?? null;
                
                // If size wasn't provided, compute it from content
                if ($fileSize === null && $fileContent !== false) {
                    $fileSize = strlen($fileContent);
                }
            } else {
                // Fallback to file path handling
                $file = $fileData['file'];
                if (is_string($file)) {
                    $fileName = basename($file);
                    $mimeType = mime_content_type($file);
                    $fileContent = file_get_contents($file);
                    $fileSize = filesize($file);
                } else {
                    $fileName = $file->getClientOriginalName();
                    $mimeType = $file->getClientMimeType();
                    $fileContent = file_get_contents($file->getRealPath());
                    $fileSize = $file->getSize();
                }
            }

            // Check file size limit (6MB for direct upload)
            if ($fileSize > 6 * 1024 * 1024) {
                throw new \Exception('File too large for direct upload. Use temp bucket workflow for files >6MB.');
            }

            Log::info('Uploading document to Robaws quotation', [
                'quotation_id' => $quotationId,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'mime_type' => $mimeType
            ]);

            $response = Http::timeout(120) // Longer timeout for file uploads
                ->withBasicAuth($this->username, $this->password)
                ->attach('file', $fileContent, $fileName)
                ->post($this->baseUrl . "/api/v2/offers/{$quotationId}/documents");

            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                Log::info('Document uploaded successfully to Robaws', [
                    'document_id' => $result['data']['id'] ?? 'unknown',
                    'quotation_id' => $quotationId
                ]);
                return $result['data'];
            }

            throw new \Exception('Document upload failed: ' . ($result['message'] ?? 'Unknown error'));

        } catch (\Exception $e) {
            Log::error('Document upload failed', [
                'quotation_id' => $quotationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * List documents attached to an offer
     */
    public function listOfferDocuments(string $offerId): array
    {
        try {
            $token = $this->authenticate();

            $response = Http::withToken($token)
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/api/v2/offers/{$offerId}/documents");

            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                return $result['data'] ?? [];
            }

            Log::warning('Failed to list offer documents', [
                'offer_id' => $offerId,
                'message' => $result['message'] ?? 'Unknown error'
            ]);
            
            return [];

        } catch (\Exception $e) {
            Log::error('List offer documents failed', [
                'offer_id' => $offerId,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Upload small file directly to entity (≤6MB)
     */
    public function uploadDirectToEntity(string $entityType, string $entityId, $file): array
    {
        try {
            // Handle both UploadedFile and file path
            if (is_string($file)) {
                $filePath = $file;
                $fileName = basename($file);
                $mimeType = mime_content_type($file);
                $fileContent = file_get_contents($file);
                $fileSize = filesize($file);
            } else {
                $filePath = $file->getRealPath();
                $fileName = $file->getClientOriginalName();
                $mimeType = $file->getClientMimeType();
                $fileContent = file_get_contents($filePath);
                $fileSize = $file->getSize();
            }

            // Check file size limit (6MB)
            if ($fileSize > 6 * 1024 * 1024) {
                throw new \Exception('File too large for direct upload. Use temp bucket workflow for files >6MB.');
            }

            Log::info('Uploading file directly to Robaws entity', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'mime_type' => $mimeType
            ]);

            $response = Http::timeout(120) // Longer timeout for file uploads
                ->withBasicAuth($this->username, $this->password)
                ->attach('file', $fileContent, $fileName)
                ->post($this->baseUrl . "/api/v2/{$entityType}s/{$entityId}/documents");

            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                Log::info('File uploaded successfully to Robaws', [
                    'document_id' => $result['data']['id'] ?? 'unknown',
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ]);
                return $result['data'];
            }

            throw new \Exception('File upload failed: ' . ($result['message'] ?? 'Unknown error'));

        } catch (\Exception $e) {
            Log::error('Direct file upload failed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create temporary bucket for large file uploads
     */
    public function createTempBucket(): array
    {
        $response = Http::timeout($this->timeout)
            ->withBasicAuth($this->username, $this->password)
            ->acceptJson()
            ->post($this->baseUrl . '/api/v2/temp-document-buckets');

        $result = $this->handleResponse($response);
        
        if ($result['success']) {
            Log::info('Created temporary bucket', [
                'bucket_id' => $result['data']['id']
            ]);
            return $result['data'];
        }

        throw new \Exception('Failed to create temp bucket: ' . ($result['message'] ?? 'Unknown error'));
    }

    /**
     * Upload file to temporary bucket
     */
    public function uploadToBucket(string $bucketId, $file): array
    {
        try {
            // Handle both UploadedFile and file path
            if (is_string($file)) {
                $filePath = $file;
                $fileName = basename($file);
                $fileContent = file_get_contents($file);
                $fileSize = filesize($file);
            } else {
                $filePath = $file->getRealPath();
                $fileName = $file->getClientOriginalName();
                $fileContent = file_get_contents($filePath);
                $fileSize = $file->getSize();
            }

            Log::info('Uploading file to temp bucket', [
                'bucket_id' => $bucketId,
                'file_name' => $fileName,
                'file_size' => $fileSize
            ]);

            $response = Http::timeout(300) // Extended timeout for large files
                ->withBasicAuth($this->username, $this->password)
                ->attach('file', $fileContent, $fileName)
                ->post($this->baseUrl . "/api/v2/temp-document-buckets/{$bucketId}/documents");

            $result = $this->handleResponse($response);
            
            if ($result['success']) {
                Log::info('File uploaded to temp bucket successfully', [
                    'document_id' => $result['data']['id'] ?? 'unknown',
                    'bucket_id' => $bucketId
                ]);
                return $result['data'];
            }

            throw new \Exception('Bucket upload failed: ' . ($result['message'] ?? 'Unknown error'));

        } catch (\Exception $e) {
            Log::error('Bucket file upload failed', [
                'bucket_id' => $bucketId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Attach document from bucket to entity
     */
    public function attachDocumentFromBucket(string $entityType, string $entityId, string $bucketId, string $documentId): array
    {
        // For offers, use PATCH with documentId instead of attach endpoint
        if ($entityType === 'offer') {
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->username, $this->password)
                ->withHeaders(['Content-Type' => 'application/merge-patch+json'])
                ->patch($this->baseUrl . "/api/v2/{$entityType}s/{$entityId}", [
                    'documentId' => $documentId
                ]);
        } else {
            // Use attach endpoint for other entity types
            $response = Http::timeout($this->timeout)
                ->withBasicAuth($this->username, $this->password)
                ->acceptJson()
                ->post($this->baseUrl . "/api/v2/{$entityType}s/{$entityId}/documents/attach", [
                    'bucketId' => $bucketId,
                    'documentId' => $documentId
                ]);
        }

        if ($response->successful()) {
            Log::info('Document attached from bucket', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'document_id' => $documentId,
                'method' => $entityType === 'offer' ? 'patch' : 'attach',
                'status' => $response->status()
            ]);
            
            // Handle different response types
            if ($response->status() === 204) {
                // No content response (successful PATCH)
                return [
                    'id' => $documentId,
                    'status' => 'attached',
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ];
            } else {
                // Response with data
                return $response->json();
            }
        }

        $result = $this->handleResponse($response);
        throw new \Exception('Failed to attach document: ' . ($result['message'] ?? 'Unknown error'));
    }

    /**
     * Upload multiple documents (batch processing)
     */
    public function uploadMultipleDocuments(string $entityType, string $entityId, array $files): array
    {
        $results = [];
        $smallFiles = [];
        $largeFiles = [];

        // Group files by size
        foreach ($files as $file) {
            $fileSize = is_string($file) ? filesize($file) : $file->getSize();
            
            if ($fileSize <= 6 * 1024 * 1024) {
                $smallFiles[] = $file;
            } else {
                $largeFiles[] = $file;
            }
        }

        Log::info('Starting batch upload', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'small_files' => count($smallFiles),
            'large_files' => count($largeFiles)
        ]);

        // Upload small files directly
        foreach ($smallFiles as $file) {
            try {
                $result = $this->uploadDirectToEntity($entityType, $entityId, $file);
                $results[] = [
                    'file' => is_string($file) ? basename($file) : $file->getClientOriginalName(),
                    'status' => 'success',
                    'method' => 'direct',
                    'document_id' => $result['id'] ?? null
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'file' => is_string($file) ? basename($file) : $file->getClientOriginalName(),
                    'status' => 'error',
                    'method' => 'direct',
                    'error' => $e->getMessage()
                ];
            }
        }

        // Upload large files via temp bucket
        if (!empty($largeFiles)) {
            try {
                $bucket = $this->createTempBucket();
                
                foreach ($largeFiles as $file) {
                    try {
                        $uploadResult = $this->uploadToBucket($bucket['id'], $file);
                        $attachResult = $this->attachDocumentFromBucket(
                            $entityType, 
                            $entityId, 
                            $bucket['id'], 
                            $uploadResult['id']
                        );
                        
                        $results[] = [
                            'file' => is_string($file) ? basename($file) : $file->getClientOriginalName(),
                            'status' => 'success',
                            'method' => 'bucket',
                            'document_id' => $attachResult['id'] ?? null
                        ];
                    } catch (\Exception $e) {
                        $results[] = [
                            'file' => is_string($file) ? basename($file) : $file->getClientOriginalName(),
                            'status' => 'error',
                            'method' => 'bucket',
                            'error' => $e->getMessage()
                        ];
                    }
                }
            } catch (\Exception $e) {
                foreach ($largeFiles as $file) {
                    $results[] = [
                        'file' => is_string($file) ? basename($file) : $file->getClientOriginalName(),
                        'status' => 'error',
                        'method' => 'bucket',
                        'error' => 'Failed to create temp bucket: ' . $e->getMessage()
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Handle HTTP response from Robaws API
     */
    private function handleResponse(Response $response): array
    {
        $statusCode = $response->status();
        $body = $response->body();

        if ($response->successful()) {
            return [
                'success' => true,
                'status' => $statusCode,
                'data' => $response->json(),
                'message' => 'Request successful'
            ];
        }

        // Handle specific error cases
        $errorMessage = "HTTP {$statusCode}";
        $errorData = null;

        try {
            $errorData = $response->json();
            $errorMessage = $errorData['message'] ?? $errorData['error'] ?? $errorMessage;
        } catch (\Exception $e) {
            $errorMessage .= ": " . $body;
        }

        // Check for temp-blocked status
        $unauthorizedReason = $response->header('X-Robaws-Unauthorized-Reason');
        if ($unauthorizedReason === 'temp-blocked') {
            $errorMessage = 'Account temporarily blocked from API access. Contact Robaws support.';
        }

        Log::error('Robaws API error', [
            'status' => $statusCode,
            'error' => $errorMessage,
            'unauthorized_reason' => $unauthorizedReason,
            'response_body' => $body
        ]);

        return [
            'success' => false,
            'status' => $statusCode,
            'message' => $errorMessage,
            'data' => $errorData
        ];
    }
}
