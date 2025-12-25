<?php

namespace App\Services\Grimaldi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GrimaldiApiClient
{
    private ?string $token = null;
    private ?int $tokenTimestamp = null;
    private const TOKEN_VALIDITY_SECONDS = 840; // 14 minutes (1 minute buffer before 15-minute expiry)
    
    private string $baseUrl;
    private ?string $userId;
    private ?string $userSecret;
    private string $tpId;
    private int $timeout;
    private ?string $apiPrefix = null; // Cached API prefix ('/api' or '')
    private ?string $workingBaseUrl = null; // Base URL that supports SailingSchedule
    private string $voyageProbe;

    public function __construct()
    {
        $this->baseUrl = config('services.grimaldi.base_url', 'https://www.grimaldi-eservice.com/EServiceAPI_BETA/v2');
        $this->userId = config('services.grimaldi.user_id') ?: null;
        $this->userSecret = config('services.grimaldi.user_secret') ?: null;
        $this->tpId = config('services.grimaldi.tp_id', 'BELGACO');
        // Reduced timeout to prevent sync from hanging (10 seconds instead of 30)
        $this->timeout = config('services.grimaldi.timeout', 10);
        $this->voyageProbe = config('services.grimaldi.voyage_probe', 'GHA0323');
    }

    /**
     * Get security token from Grimaldi API
     * Token is valid for 15 minutes
     */
    public function getSecurityToken(): ?string
    {
        // Check if credentials are configured
        if (empty($this->userId) || empty($this->userSecret)) {
            Log::error('Grimaldi API credentials not configured', [
                'user_id_set' => !empty($this->userId),
                'user_secret_set' => !empty($this->userSecret),
            ]);
            return null;
        }

        // Check if we have a valid cached token
        if ($this->isTokenValid()) {
            return $this->token;
        }

        try {
            Log::info('Requesting new Grimaldi API security token');

            // According to PDF: "The token can be requested invoking specific API with UserID and UserSecret (both provided by Grimaldi Corporate IT) passed via the Header."
            // PDF shows: GET api/Security
            // Security endpoint is confirmed to work at /api/Security, so we keep it hardcoded
            $url = rtrim($this->baseUrl, '/') . '/api/Security';
            
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'UserID' => $this->userId,
                    'UserSecret' => $this->userSecret,
                ])
                ->get($url);

            if ($response->successful()) {
                $token = $response->body();
                
                // Token might be wrapped in quotes, clean it
                $token = trim($token, '"');
                
                if (!empty($token)) {
                    $this->token = $token;
                    $this->tokenTimestamp = time();
                    
                    Log::info('Grimaldi API security token obtained successfully', [
                        'token_length' => strlen($token),
                        'token_preview' => substr($token, 0, 10) . '...',
                    ]);
                    
                    return $this->token;
                }
            }

            Log::error('Failed to obtain Grimaldi API security token', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

        } catch (\Exception $e) {
            Log::error('Exception while obtaining Grimaldi API security token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return null;
    }

    /**
     * Check if the current token is still valid
     */
    public function isTokenValid(): bool
    {
        if ($this->token === null || $this->tokenTimestamp === null) {
            return false;
        }

        $age = time() - $this->tokenTimestamp;
        return $age < self::TOKEN_VALIDITY_SECONDS;
    }

    /**
     * Detect the API prefix style by probing a lightweight endpoint
     * Returns '/api' or '' (empty string)
     * Caches the result for 24 hours
     */
    private function detectApiPrefix(): string
    {
        // Check cache first
        $cacheKey = 'grimaldi_api_prefix:' . md5($this->baseUrl);
        $cachedPrefix = Cache::get($cacheKey);
        
        if ($cachedPrefix !== null) {
            
            return $cachedPrefix;
        }

        $token = $this->getSecurityToken();
        if (!$token) {
            Log::error('Cannot detect API prefix: no valid token');
            // Default to '/api' if we can't get a token
            return '/api';
        }

        // Probe both styles: {base}/Info and {base}/api/Info
        $probeEndpoints = [
            '' => rtrim($this->baseUrl, '/') . '/Info?CodeType=0&Filter=',
            '/api' => rtrim($this->baseUrl, '/') . '/api/Info?CodeType=0&Filter=',
        ];

        foreach ($probeEndpoints as $prefix => $url) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ])
                    ->get($url);

                if ($response->successful()) {
                    // Cache the working prefix for 24 hours
                    Cache::put($cacheKey, $prefix, 24 * 60 * 60); // 24 hours
                    
                    Log::info('Grimaldi API prefix detected', [
                        'prefix' => $prefix,
                        'base_url' => $this->baseUrl,
                    ]);
                    
                    return $prefix;
                }
            } catch (\Exception $e) {
                Log::warning('Error probing API prefix', [
                    'prefix' => $prefix,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // If neither works, default to '/api' and cache it for a shorter time (1 hour) to retry soon
        Log::warning('Could not detect Grimaldi API prefix, defaulting to /api', [
            'base_url' => $this->baseUrl,
        ]);
        
        Cache::put($cacheKey, '/api', 60 * 60); // Cache for 1 hour as fallback
        
        return '/api';
    }

    /**
     * Get the API prefix (with caching)
     */
    private function getApiPrefix(?string $baseUrl = null): string
    {
        $baseToUse = $baseUrl ?? $this->baseUrl;
        
        // Check cache first
        $cacheKey = 'grimaldi_api_prefix:' . md5($baseToUse);
        $cachedPrefix = Cache::get($cacheKey);
        
        if ($cachedPrefix !== null) {
            return $cachedPrefix;
        }
        
        // Temporarily override base URL for detection
        $originalBaseUrl = $this->baseUrl;
        $this->baseUrl = $baseToUse;
        $this->apiPrefix = null; // Reset to force detection
        
        $prefix = $this->detectApiPrefix();
        
        // Restore original base URL
        $this->baseUrl = $originalBaseUrl;
        
        return $prefix;
    }

    /**
     * Make an authenticated request to the Grimaldi API
     */
    public function makeAuthenticatedRequest(string $endpoint, array $params = []): ?array
    {
        $token = $this->getSecurityToken();
        
        if (!$token) {
            Log::error('Cannot make authenticated request: no valid token');
            return null;
        }

        try {
            // Get the detected API prefix (auto-detected and cached)
            $apiPrefix = $this->getApiPrefix();
            
            // Build URL: {base}{prefix}/{endpoint}
            // Examples:
            // - {base}/api/SailingSchedule (if prefix is '/api')
            // - {base}/SailingSchedule (if prefix is '')
            $url = rtrim($this->baseUrl, '/') . $apiPrefix . '/' . ltrim($endpoint, '/');
            
            // Build query string if params provided
            // Correct format: GET SailingSchedule?GTrade=ALL_RORO&nDays=40&POL=BEANR&POD=CIABJ
            // Or: GET SailingSchedule?GTrade=NEWAF_RORO&nDays=30 (without POL/POD for Northern Europe → WAF overview)
            // The curly braces in documentation are NOTATION only - do NOT include them in the actual URL
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            
            Log::debug('Making authenticated Grimaldi API request', [
                'endpoint' => $endpoint,
                'url' => $url,
                'params' => $params,
            ]);
            
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::debug('Grimaldi API request successful', [
                    'endpoint' => $endpoint,
                    'response_type' => gettype($data),
                ]);
                
                return $data;
            }

            // If token expired, try once more with a new token
            if ($response->status() === 401) {
                Log::warning('Grimaldi API token expired, refreshing and retrying', [
                    'endpoint' => $endpoint,
                ]);
                
                $this->token = null;
                $this->tokenTimestamp = null;
                $token = $this->getSecurityToken();
                
                if ($token) {
                    $response = Http::timeout($this->timeout)
                        ->withHeaders([
                            'Authorization' => 'Bearer ' . $token,
                            'Accept' => 'application/json',
                        ])
                        ->get($url);
                    
                    if ($response->successful()) {
                        return $response->json();
                    }
                }
            }

            // Log error with body preview for non-JSON responses (first 300 chars)
            $bodyPreview = substr($response->body(), 0, 300);
            $contentType = $response->header('Content-Type');
            $isJson = $contentType && str_contains($contentType, 'application/json');
            
            Log::error('Grimaldi API request failed', [
                'base_url' => $this->baseUrl,
                'api_prefix' => $apiPrefix,
                'endpoint' => $endpoint,
                'final_url' => $url,
                'status' => $response->status(),
                'content_type' => $contentType,
                'is_json' => $isJson,
                'body_preview' => $bodyPreview,
                'body_length' => strlen($response->body()),
            ]);

        } catch (\Exception $e) {
            Log::error('Exception while making Grimaldi API request', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return null;
    }

    /**
     * Make an authenticated request with a specific prefix (for testing alternative prefix styles)
     */
    private function makeAuthenticatedRequestWithPrefix(string $endpoint, array $params, string $prefix): ?array
    {
        $token = $this->getSecurityToken();
        
        if (!$token) {
            Log::error('Cannot make authenticated request: no valid token');
            return null;
        }

        try {
            // Build URL with specified prefix
            $url = rtrim($this->baseUrl, '/') . $prefix . '/' . ltrim($endpoint, '/');
            
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Exception in makeAuthenticatedRequestWithPrefix', [
                'endpoint' => $endpoint,
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Probe capabilities of a base URL
     * Returns array with status codes for Info and SailingSchedule endpoints
     */
    private function probeBaseUrlCapabilities(string $baseUrl): array
    {
        $cacheKey = 'grimaldi_capabilities:' . md5($baseUrl);
        $cached = Cache::get($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }

        $token = $this->getSecurityToken();
        if (!$token) {
            Log::error('Cannot probe capabilities: no valid token');
            return [
                'base_url' => $baseUrl,
                'info_status' => null,
                'sailing_schedule_voyage_status' => null,
                'sailing_schedule_params_status' => null,
                'error' => 'No valid token',
            ];
        }

        $apiPrefix = $this->getApiPrefix($baseUrl);
        $capabilities = [
            'base_url' => $baseUrl,
            'api_prefix' => $apiPrefix,
            'info_status' => null,
            'sailing_schedule_voyage_status' => null,
            'sailing_schedule_params_status' => null,
        ];

        // Probe 1: Info endpoint
        try {
            $infoUrl = rtrim($baseUrl, '/') . $apiPrefix . '/Info?CodeType=0&Filter=';
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                ->get($infoUrl);
            
            $capabilities['info_status'] = $response->status();
        } catch (\Exception $e) {
            Log::warning('Error probing Info endpoint', ['base_url' => $baseUrl, 'error' => $e->getMessage()]);
        }

        // Probe 2: SailingSchedule with VoyageNo
        try {
            $voyageUrl = rtrim($baseUrl, '/') . $apiPrefix . '/SailingSchedule?VoyageNo=' . $this->voyageProbe;
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                ->get($voyageUrl);
            
            $capabilities['sailing_schedule_voyage_status'] = $response->status();
            $capabilities['sailing_schedule_voyage_url'] = $voyageUrl;
            $capabilities['sailing_schedule_voyage_body_preview'] = substr($response->body(), 0, 300);
        } catch (\Exception $e) {
            Log::warning('Error probing SailingSchedule (VoyageNo)', ['base_url' => $baseUrl, 'error' => $e->getMessage()]);
        }

        // Probe 3: SailingSchedule with parameters (using GTrade=ALL_RORO as per Grimaldi docs)
        try {
            $paramsUrl = rtrim($baseUrl, '/') . $apiPrefix . '/SailingSchedule?GTrade=ALL_RORO&nDays=1&POL=BEANR&POD=CIABJ';
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                ->get($paramsUrl);
            
            $capabilities['sailing_schedule_params_status'] = $response->status();
            $capabilities['sailing_schedule_params_url'] = $paramsUrl;
            $capabilities['sailing_schedule_params_body_preview'] = substr($response->body(), 0, 300);
            $capabilities['sailing_schedule_params_content_type'] = $response->header('Content-Type');
        } catch (\Exception $e) {
            Log::warning('Error probing SailingSchedule (params)', ['base_url' => $baseUrl, 'error' => $e->getMessage()]);
        }

        // Cache results for 24 hours
        Cache::put($cacheKey, $capabilities, 24 * 60 * 60);
        
        return $capabilities;
    }

    /**
     * Find the best base URL that supports SailingSchedule
     * Returns the base URL or null if none found
     */
    private function findWorkingBaseUrl(): ?string
    {
        if ($this->workingBaseUrl !== null) {
            return $this->workingBaseUrl;
        }

        $baseUrls = [
            config('services.grimaldi.base_url_beta'),
            config('services.grimaldi.base_url_prod'),
            $this->baseUrl, // Fallback to configured base_url
        ];
        
        // Remove duplicates
        $baseUrls = array_unique(array_filter($baseUrls));

        foreach ($baseUrls as $baseUrl) {
            $capabilities = $this->probeBaseUrlCapabilities($baseUrl);
            
            // Check if SailingSchedule is available (either VoyageNo or params endpoint returns 200)
            $sailingScheduleWorks = 
                ($capabilities['sailing_schedule_voyage_status'] === 200) ||
                ($capabilities['sailing_schedule_params_status'] === 200);
            
            if ($sailingScheduleWorks) {
                $this->workingBaseUrl = $baseUrl;
                Log::info('Found working Grimaldi base URL with SailingSchedule support', [
                    'base_url' => $baseUrl,
                    'capabilities' => $capabilities,
                ]);
                
                return $baseUrl;
            }
        }

        // If none found, return null (will throw exception in getSailingSchedule)
        return null;
    }

    /**
     * Get sailing schedules by POL/POD
     * Uses working base URL (BETA or PROD) that supports SailingSchedule
     * Throws exception if no working base URL found
     */
    public function getSailingSchedule(string $pol, string $pod, int $nDays = 40): array
    {
        
        // Find working base URL
        $workingBaseUrl = $this->findWorkingBaseUrl();
        
        if (!$workingBaseUrl) {
            // Get all tested base URLs and their capabilities
            $baseUrls = [
                config('services.grimaldi.base_url_beta'),
                config('services.grimaldi.base_url_prod'),
                $this->baseUrl,
            ];
            $baseUrls = array_unique(array_filter($baseUrls));
            
            $testedUrls = [];
            foreach ($baseUrls as $baseUrl) {
                $capabilities = $this->probeBaseUrlCapabilities($baseUrl);
                $testedUrls[] = [
                    'base_url' => $baseUrl,
                    'info_status' => $capabilities['info_status'] ?? 'not_tested',
                    'sailing_schedule_voyage_status' => $capabilities['sailing_schedule_voyage_status'] ?? 'not_tested',
                    'sailing_schedule_params_status' => $capabilities['sailing_schedule_params_status'] ?? 'not_tested',
                    'sailing_schedule_voyage_url' => $capabilities['sailing_schedule_voyage_url'] ?? null,
                    'sailing_schedule_params_url' => $capabilities['sailing_schedule_params_url'] ?? null,
                ];
            }
            
            throw new \Exception(
                "Grimaldi SailingSchedule endpoint not available on any tested base URL.\n" .
                "Tested URLs and status codes:\n" .
                json_encode($testedUrls, JSON_PRETTY_PRINT) . "\n" .
                "Please check if SailingSchedule is available in your API environment or contact Grimaldi support."
            );
        }
        
        // Get capabilities to check which endpoint style works
        $capabilities = $this->probeBaseUrlCapabilities($workingBaseUrl);
        $paramsEndpointWorks = ($capabilities['sailing_schedule_params_status'] ?? null) === 200;
        $voyageEndpointWorks = ($capabilities['sailing_schedule_voyage_status'] ?? null) === 200;
        
        // Temporarily switch to working base URL
        $originalBaseUrl = $this->baseUrl;
        $this->baseUrl = $workingBaseUrl;
        $this->apiPrefix = null; // Reset to force detection with new base
        
        try {
            // If params endpoint works, use it
            if ($paramsEndpointWorks) {
                // Option 1: Use GTrade=ALL_RORO with POL/POD (recommended when POL/POD are known)
                // Option 2: Use GTrade=NEWAF_RORO without POL/POD (for Northern Europe → WAF overview)
                $schedules = null;
                
                // First try: ALL_RORO with POL/POD (recommended by Grimaldi)
                try {
                    
                    $schedules = $this->makeAuthenticatedRequest('SailingSchedule', [
                        'GTrade' => 'ALL_RORO',
                        'POL' => $pol,
                        'POD' => $pod,
                        'nDays' => $nDays,
                    ]);
                    
                    if (!empty($schedules) && is_array($schedules)) {
                        Log::info('Grimaldi schedules found using ALL_RORO', [
                            'base_url' => $workingBaseUrl,
                            'pol' => $pol,
                            'pod' => $pod,
                            'count' => count($schedules),
                        ]);
                        return $schedules;
                    }
                } catch (\Exception $e) {
                    Log::warning('Grimaldi API request failed for ALL_RORO', [
                        'base_url' => $workingBaseUrl,
                        'pol' => $pol,
                        'pod' => $pod,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Fallback: Try NEWAF_RORO without POL/POD (returns all Northern Europe → WAF routes)
                // Then filter client-side by POL/POD if needed
                try {
                    
                    $allSchedules = $this->makeAuthenticatedRequest('SailingSchedule', [
                        'GTrade' => 'NEWAF_RORO',
                        'nDays' => $nDays,
                    ]);
                    
                    // Filter by POL/POD if we got results
                    if (!empty($allSchedules) && is_array($allSchedules)) {
                        $schedules = array_filter($allSchedules, function($schedule) use ($pol, $pod) {
                            $schedulePol = $schedule['pol'] ?? $schedule['POL'] ?? null;
                            $schedulePod = $schedule['pod'] ?? $schedule['POD'] ?? null;
                            return ($schedulePol === $pol && $schedulePod === $pod);
                        });
                        $schedules = array_values($schedules); // Re-index array
                        
                        if (!empty($schedules)) {
                            Log::info('Grimaldi schedules found using NEWAF_RORO (filtered)', [
                                'base_url' => $workingBaseUrl,
                                'pol' => $pol,
                                'pod' => $pod,
                                'total' => count($allSchedules),
                                'filtered' => count($schedules),
                            ]);
                            return $schedules;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Grimaldi API request failed for NEWAF_RORO', [
                        'base_url' => $workingBaseUrl,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // If only VoyageNo endpoint works, we cannot query by POL/POD
            // Throw a clear exception explaining the limitation
            if ($voyageEndpointWorks && !$paramsEndpointWorks) {
                $testedUrls = [
                    'base_url' => $workingBaseUrl,
                    'info_status' => $capabilities['info_status'] ?? 'not_tested',
                    'sailing_schedule_voyage_status' => $capabilities['sailing_schedule_voyage_status'] ?? 'not_tested',
                    'sailing_schedule_params_status' => $capabilities['sailing_schedule_params_status'] ?? 'not_tested',
                    'sailing_schedule_voyage_url' => $capabilities['sailing_schedule_voyage_url'] ?? null,
                    'sailing_schedule_params_url' => $capabilities['sailing_schedule_params_url'] ?? null,
                ];
                
                Log::error('Grimaldi API limitation: POL/POD queries not supported', [
                    'base_url' => $workingBaseUrl,
                    'capabilities' => $capabilities,
                    'tested_urls' => $testedUrls,
                ]);
                
                throw new \Exception(
                    "Grimaldi API limitation: SailingSchedule endpoint supports VoyageNo queries but NOT GTrade parameter queries.\n" .
                    "The endpoint GET {base}/api/SailingSchedule?GTrade=...&POL=...&POD=... returns 404.\n" .
                    "Only GET {base}/api/SailingSchedule?VoyageNo=... is available.\n\n" .
                    "Tested URLs and status codes:\n" .
                    json_encode($testedUrls, JSON_PRETTY_PRINT) . "\n\n" .
                    "To get schedules by POL/POD, you would need:\n" .
                    "1. First query voyages using a different endpoint (if available)\n" .
                    "2. Then query each voyage using SailingSchedule?VoyageNo=...\n" .
                    "OR contact Grimaldi support to enable GTrade parameter queries on the SailingSchedule endpoint."
                );
            }
            
            // If neither endpoint works, this shouldn't happen (findWorkingBaseUrl should have caught it)
            throw new \Exception("Grimaldi SailingSchedule endpoint not available on base URL: {$workingBaseUrl}");
            
        } finally {
            // Restore original base URL
            $this->baseUrl = $originalBaseUrl;
            $this->apiPrefix = null; // Reset
        }
    }

    /**
     * Get sailing schedule details by voyage number
     */
    public function getScheduleByVoyage(string $voyageNo): ?array
    {
        return $this->makeAuthenticatedRequest('/api/Info', [
            'VoyageNo' => $voyageNo,
        ]);
    }

    /**
     * Get vessel list (optional, for data enrichment)
     */
    public function getVessels(bool $unRestricted = false): ?array
    {
        return $this->makeAuthenticatedRequest('/api/Vessel', [
            'UnRestricted' => $unRestricted ? 'true' : 'false',
        ]);
    }
}

