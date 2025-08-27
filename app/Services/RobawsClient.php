<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RobawsClient
{
    protected string $baseUrl;
    protected ?string $username;
    protected ?string $password;
    protected ?string $apiKey;
    protected ?string $apiSecret;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.robaws.base_url');
        $this->username = config('services.robaws.username');
        $this->password = config('services.robaws.password');
        $this->apiKey = config('services.robaws.api_key');
        $this->apiSecret = config('services.robaws.api_secret');
        $this->timeout = config('services.robaws.timeout', 30);
    }

    protected function http()
    {
        // Robaws API uses Basic Auth with API Key as username and API Secret as password
        if (!$this->apiKey || !$this->apiSecret) {
            throw new \Exception('Robaws API Key and API Secret are required for API access');
        }

        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->apiKey, $this->apiSecret)  // API Key as username, Secret as password
            ->acceptJson()
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Laravel/11.0 Bconnect/1.0'
            ])
            ->timeout($this->timeout)
            ->retry(2, 1000, function ($exception, $request) {
                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $response = $exception->response;
                    return $response && ($response->status() === 429 || $response->serverError());
                }
                return false;
            })
            ->beforeSending(function ($request) {
                Log::debug('Robaws API Request', [
                    'method' => $request->method(),
                    'url' => $request->url(),
                    'headers' => $request->headers()
                ]);
            });
    }

    /**
     * Test API connection
     */
    public function testConnection(): array
    {
        try {
            // Test actual API endpoints (302 on base URL is normal)
            $tests = [
                ['name' => 'API V2 Metadata', 'url' => '/api/v2/metadata', 'method' => 'GET'],
                ['name' => 'API V2 Clients', 'url' => '/api/v2/clients', 'method' => 'GET'],
                ['name' => 'API V2 Offers', 'url' => '/api/v2/offers', 'method' => 'GET'],
            ];
            
            $results = [];
            
            foreach ($tests as $test) {
                try {
                    $response = $this->http()->get($test['url'] . '?limit=1');
                    
                    $results[] = [
                        'test' => $test['name'],
                        'url' => $this->baseUrl . $test['url'],
                        'status' => $response->status(),
                        'success' => $response->successful(),
                        'headers' => $response->headers(),
                        'body_preview' => substr($response->body(), 0, 200) . '...'
                    ];
                    
                    // If this one succeeded, return success
                    if ($response->successful()) {
                        return [
                            'success' => true,
                            'status' => $response->status(),
                            'message' => "Connected successfully via {$test['name']}",
                            'data' => $response->json(),
                            'endpoint' => $test['url'],
                            'all_tests' => $results
                        ];
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'test' => $test['name'],
                        'url' => $this->baseUrl . $test['url'],
                        'error' => $e->getMessage(),
                        'success' => false
                    ];
                }
            }
            
            return [
                'success' => false,
                'status' => $results[0]['status'] ?? 0,
                'message' => 'All API endpoint tests failed',
                'all_tests' => $results
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Create a new offer (quotation) in Robaws
     */
    public function createOffer(array $payload): array
    {
        $res = $this->http()
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v2/offers', $payload);

        $this->throwUnlessOk($res);
        
        $result = $res->json();
        Log::info('Robaws offer created', ['offer_id' => $result['id'] ?? null]);
        
        return $result;
    }

    /**
     * Get an offer by ID
     */
    public function getOffer(string $offerId): array
    {
        $res = $this->http()->get("/api/v2/offers/{$offerId}");
        $this->throwUnlessOk($res);
        return $res->json();
    }

    /**
     * Add a line item to an offer
     */
    public function addOfferLineItem(string $offerId, array $line): array
    {
        $res = $this->http()->post("/api/v2/offers/{$offerId}/line-items", $line);
        $this->throwUnlessOk($res);
        return $res->json();
    }

    /**
     * List clients with optional query parameters
     */
    public function listClients(array $q = []): array
    {
        $res = $this->http()->get('/api/v2/clients', $q);
        $this->throwUnlessOk($res);
        return $res->json();
    }

    /**
     * Search/list clients
     */
    public function searchClients(array $filters = []): array
    {
        return $this->listClients($filters);
    }

    /**
     * Create a client if it doesn't exist
     */
    public function createClient(array $clientData): array
    {
        $res = $this->http()
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v2/clients', $clientData);

        $this->throwUnlessOk($res);
        return $res->json();
    }

    /**
     * Find or create a client based on email or name
     */
    public function findOrCreateClient(array $clientData): array
    {
        // Search for existing client by email first
        if (!empty($clientData['email'])) {
            try {
                $existingClients = $this->searchClients([
                    'email' => $clientData['email'],
                    'limit' => 1
                ]);

                if (!empty($existingClients['data'])) {
                    return $existingClients['data'][0];
                }
            } catch (\Exception $e) {
                // Continue to create new client if search fails
                Log::warning('Client search by email failed', ['error' => $e->getMessage()]);
            }
        }
        
        // Search by name if no email or email search failed
        if (!empty($clientData['name'])) {
            try {
                $existingClients = $this->searchClients([
                    'name' => $clientData['name'],
                    'limit' => 1
                ]);

                if (!empty($existingClients['data'])) {
                    return $existingClients['data'][0];
                }
            } catch (\Exception $e) {
                // Continue to create new client if search fails
                Log::warning('Client search by name failed', ['error' => $e->getMessage()]);
            }
        }

        // Create new client
        return $this->createClient($clientData);
    }

    /**
     * Get list of available line item types/articles
     */
    public function getArticles(array $filters = []): array
    {
        $res = $this->http()->get('/api/v2/articles', $filters);
        $this->throwUnlessOk($res);
        return $res->json();
    }

    protected function throwUnlessOk(Response $res): void
    {
        if ($res->failed()) {
            Log::error('Robaws API Error', [
                'status' => $res->status(),
                'body' => $res->body(),
                'headers' => $res->headers()
            ]);
            
            throw new \RuntimeException("Robaws error {$res->status()}: " . $res->body());
        }
    }
}
