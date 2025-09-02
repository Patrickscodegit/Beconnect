<?php

namespace App\Services\Export\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class RobawsApiClient
{
    private readonly PendingRequest $http;

    public function __construct()
    {
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

    /** Contacts search with include=client for direct client linkage */
    public function findClientByEmail(string $email): ?array
    {
        $res = $this->http
            ->get('/api/v2/contacts', ['email' => $email, 'include' => 'client', 'size' => 50])
            ->throw()
            ->json();

        foreach (($res['content'] ?? []) as $c) {
            if (strcasecmp($c['email'] ?? '', $email) === 0 && !empty($c['client']['id'])) {
                return $c['client']; // { id, name, ... }
            }
        }
        // fallback if 'email' filter isn't supported on tenant → try best-effort search
        $res = $this->http
            ->get('/api/v2/contacts', ['search' => $email, 'include' => 'client', 'size' => 50])
            ->throw()
            ->json();
        foreach (($res['content'] ?? []) as $c) {
            if (strcasecmp($c['email'] ?? '', $email) === 0 && !empty($c['client']['id'])) {
                return $c['client'];
            }
        }
        return null;
    }

    public function findClientByPhone(string $phone): ?array
    {
        $res = $this->http
            ->get('/api/v2/contacts', ['phone' => $phone, 'include' => 'client', 'size' => 50])
            ->throw()
            ->json();
        foreach (($res['content'] ?? []) as $c) {
            if (!empty($c['phone']) && !empty($c['client']['id'])) {
                // simple normalization for phone match
                $a = preg_replace('/\D+/', '', $c['phone']);
                $b = preg_replace('/\D+/', '', $phone);
                if ($a === $b) return $c['client'];
            }
        }
        return null;
    }

    /** Paged slice of clients for local matching */
    public function listClients(int $page = 0, int $size = 100): array
    {
        return $this->http
            ->get('/api/v2/clients', ['page' => $page, 'size' => min($size, 100), 'sort' => 'name:asc'])
            ->throw()
            ->json();
    }

    public function getClientById(string $id, array $include = []): ?array
    {
        $res = $this->http
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
     * Create a new quotation/offer in Robaws
     */
    public function createQuotation(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = $this->http
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
            $response = $this->http
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
    public function getOffer(string $offerId): array
    {
        try {
            $response = $this->http->get("/api/v2/offers/{$offerId}");

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
            
            $response = $this->http
                ->attach('file', file_get_contents($filePath), $filename)
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
            $response = $this->http->get('/api/v2/health');
            
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
            $http = $http->withBasicAuth(
                config('services.robaws.username'),
                config('services.robaws.password')
            );
        } else {
            // Bearer token authentication
            $token = config('services.robaws.token') ?? $apiKey;
            if ($token) {
                $http = $http->withToken($token);
            }
        }

        return $http;
    }
}
