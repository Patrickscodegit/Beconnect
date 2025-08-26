<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SimpleRobawsClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.robaws.base_url');
        $this->apiKey = config('services.robaws.api_key');
        $this->apiSecret = config('services.robaws.api_secret');
        $this->timeout = config('services.robaws.timeout', 30);
    }

    /**
     * Send extracted JSON data to Robaws quotation
     */
    public function sendExtractedData(array $extractedData): array
    {
        try {
            Log::info('Sending extracted data to Robaws', [
                'data_keys' => array_keys($extractedData),
                'base_url' => $this->baseUrl
            ]);

            // Prepare the data payload for Robaws
            $payload = [
                'extracted_data' => $extractedData,
                'source' => 'bconnect_ai_extraction',
                'timestamp' => now()->toISOString(),
            ];

            // Make the API call to Robaws
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'X-API-Secret' => $this->apiSecret,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->baseUrl . '/api/quotations/extracted-data', $payload);

            return $this->handleResponse($response);

        } catch (\Exception $e) {
            Log::error('Failed to send data to Robaws', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode() ?: 500
            ];
        }
    }

    /**
     * Test the connection to Robaws API
     */
    public function testConnection(): array
    {
        try {
            Log::info('Testing Robaws API connection');

            // Try different authentication methods
            $authMethods = [
                'bearer_token' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'api_key_header' => [
                    'X-API-Key' => $this->apiKey,
                    'X-API-Secret' => $this->apiSecret,
                    'Accept' => 'application/json',
                ],
                'basic_auth' => [
                    'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret),
                    'Accept' => 'application/json',
                ],
                'custom_headers' => [
                    'robaws-api-key' => $this->apiKey,
                    'robaws-api-secret' => $this->apiSecret,
                    'Accept' => 'application/json',
                ]
            ];

            foreach ($authMethods as $method => $headers) {
                Log::info("Trying authentication method: $method");
                
                $response = Http::timeout($this->timeout)
                    ->withHeaders($headers)
                    ->get($this->baseUrl . '/api/test');

                $result = $this->handleResponse($response);
                
                if ($result['success']) {
                    Log::info("Authentication successful with method: $method");
                    return array_merge($result, ['auth_method' => $method]);
                }
                
                Log::info("Authentication failed with method: $method", ['status' => $result['status_code']]);
            }

            // If all methods fail, return the last result
            return array_merge($result, ['auth_method' => 'all_failed']);

        } catch (\Exception $e) {
            Log::error('Robaws connection test failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode() ?: 500
            ];
        }
    }

    /**
     * Handle HTTP response from Robaws API
     */
    private function handleResponse(Response $response): array
    {
        $statusCode = $response->status();
        $body = $response->json() ?? [];

        Log::info('Robaws API response', [
            'status_code' => $statusCode,
            'response_body' => $body
        ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $body,
                'status_code' => $statusCode
            ];
        }

        return [
            'success' => false,
            'error' => $body['message'] ?? 'Unknown API error',
            'details' => $body,
            'status_code' => $statusCode
        ];
    }

    /**
     * Get configuration status
     */
    public function getConfigStatus(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'api_key_configured' => !empty($this->apiKey),
            'api_secret_configured' => !empty($this->apiSecret),
            'timeout' => $this->timeout
        ];
    }
}
