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
    private string $userId;
    private string $userSecret;
    private string $tpId;
    private int $timeout;
    private ?string $apiPrefix = null; // Cached API prefix ('/api' or '')
    private ?string $workingBaseUrl = null; // Base URL that supports SailingSchedule
    private string $voyageProbe;

    public function __construct()
    {
        $this->baseUrl = config('services.grimaldi.base_url', 'https://www.grimaldi-eservice.com/EServiceAPI_BETA/v2');
        $this->userId = config('services.grimaldi.user_id', '');
        $this->userSecret = config('services.grimaldi.user_secret', '');
        $this->tpId = config('services.grimaldi.tp_id', 'BELGACO');
        // Reduced timeout to prevent sync from hanging (10 seconds instead of 30)
        $this->timeout = config('services.grimaldi.timeout', 10);
        $this->voyageProbe = config('services.grimaldi.voyage_probe', 'GHA0323');
        
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'GrimaldiApiClient.php:__construct',
            'message' => 'Grimaldi API client initialized',
            'data' => ['base_url' => $this->baseUrl, 'base_url_length' => strlen($this->baseUrl)],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'F'
        ]) . "\n", FILE_APPEND);
        // #endregion
    }

    /**
     * Get security token from Grimaldi API
     * Token is valid for 15 minutes
     */
    public function getSecurityToken(): ?string
    {
        // Check if we have a valid cached token
        if ($this->isTokenValid()) {
            return $this->token;
        }

        try {
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:42',
                'message' => 'Requesting Grimaldi API security token',
                'data' => ['base_url' => $this->baseUrl, 'user_id' => $this->userId],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A'
            ]) . "\n", FILE_APPEND);
            // #endregion
            Log::info('Requesting new Grimaldi API security token');

            // According to PDF: "The token can be requested invoking specific API with UserID and UserSecret (both provided by Grimaldi Corporate IT) passed via the Header."
            // PDF shows: GET api/Security
            // Security endpoint is confirmed to work at /api/Security, so we keep it hardcoded
            $url = rtrim($this->baseUrl, '/') . '/api/Security';
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:getSecurityToken:before_request',
                'message' => 'About to request security token',
                'data' => ['url' => $url, 'base_url' => $this->baseUrl, 'user_id' => $this->userId],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A'
            ]) . "\n", FILE_APPEND);
            // #endregion
            
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'UserID' => $this->userId,
                    'UserSecret' => $this->userSecret,
                ])
                ->get($url);
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:getSecurityToken:response',
                'message' => 'Security token response received',
                'data' => ['status' => $response->status(), 'successful' => $response->successful(), 'body_preview' => substr($response->body(), 0, 100)],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A'
            ]) . "\n", FILE_APPEND);
            // #endregion

            if ($response->successful()) {
                $token = $response->body();
                
                // Token might be wrapped in quotes, clean it
                $token = trim($token, '"');
                
                // #region agent log
                @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'GrimaldiApiClient.php:52',
                    'message' => 'Token response received',
                    'data' => ['status' => $response->status(), 'token_length' => strlen($token), 'token_preview' => substr($token, 0, 20)],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'A'
                ]) . "\n", FILE_APPEND);
                // #endregion
                
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
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:detectApiPrefix',
                'message' => 'Using cached API prefix',
                'data' => ['prefix' => $cachedPrefix, 'base_url' => $this->baseUrl],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'G'
            ]) . "\n", FILE_APPEND);
            // #endregion
            
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

        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'GrimaldiApiClient.php:detectApiPrefix:probe_start',
            'message' => 'Starting API prefix detection',
            'data' => ['base_url' => $this->baseUrl, 'probe_endpoints' => $probeEndpoints],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'G'
        ]) . "\n", FILE_APPEND);
        // #endregion

        foreach ($probeEndpoints as $prefix => $url) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ])
                    ->get($url);

                // #region agent log
                @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'GrimaldiApiClient.php:detectApiPrefix:probe_result',
                    'message' => 'Probe result',
                    'data' => ['prefix' => $prefix, 'url' => $url, 'status' => $response->status(), 'successful' => $response->successful(), 'body_preview' => substr($response->body(), 0, 200)],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'G'
                ]) . "\n", FILE_APPEND);
                // #endregion

                if ($response->successful()) {
                    // Cache the working prefix for 24 hours
                    Cache::put($cacheKey, $prefix, 24 * 60 * 60); // 24 hours
                    
                    Log::info('Grimaldi API prefix detected', [
                        'prefix' => $prefix,
                        'base_url' => $this->baseUrl,
                    ]);
                    
                    // #region agent log
                    @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                        'timestamp' => time() * 1000,
                        'location' => 'GrimaldiApiClient.php:detectApiPrefix:detected',
                        'message' => 'API prefix detected and cached',
                        'data' => ['prefix' => $prefix, 'base_url' => $this->baseUrl, 'cache_key' => $cacheKey],
                        'sessionId' => 'debug-session',
                        'runId' => 'run1',
                        'hypothesisId' => 'G'
                    ]) . "\n", FILE_APPEND);
                    // #endregion
                    
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
            // Correct format: GET SailingSchedule?GrimaldiTrade=NEWAF_RORO&nDays=40&POL=BEANR&POD=CIABJ
            // The curly braces in documentation are NOTATION only - do NOT include them in the actual URL
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:makeAuthenticatedRequest:url_built',
                'message' => 'URL constructed for API request',
                'data' => ['base_url' => $this->baseUrl, 'api_prefix' => $apiPrefix, 'endpoint' => $endpoint, 'final_url' => $url, 'params' => $params],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'G'
            ]) . "\n", FILE_APPEND);
            // #endregion
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:makeAuthenticatedRequest:url_built',
                'message' => 'URL constructed for API request',
                'data' => ['base_url' => $this->baseUrl, 'endpoint' => $endpoint, 'final_url' => $url, 'params' => $params],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B'
            ]) . "\n", FILE_APPEND);
            // #endregion

            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:makeAuthenticatedRequest:before_request',
                'message' => 'About to make authenticated request',
                'data' => ['endpoint' => $endpoint, 'url' => $url, 'params' => $params, 'base_url' => $this->baseUrl],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B'
            ]) . "\n", FILE_APPEND);
            // #endregion
            
            Log::debug('Making authenticated Grimaldi API request', [
                'endpoint' => $endpoint,
                'url' => $url,
                'params' => $params,
            ]);

            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:makeAuthenticatedRequest:before_http_call',
                'message' => 'About to make HTTP GET request',
                'data' => ['url' => $url, 'url_length' => strlen($url), 'has_braces' => strpos($url, '{') !== false, 'has_encoded_braces' => strpos($url, '%7B') !== false],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B'
            ]) . "\n", FILE_APPEND);
            // #endregion
            
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            // #region agent log
            $bodyPreview = substr($response->body(), 0, 200);
            $isJson = $response->header('Content-Type') && str_contains($response->header('Content-Type'), 'application/json');
            
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:makeAuthenticatedRequest',
                'message' => 'HTTP response received',
                'data' => [
                    'base_url' => $this->baseUrl,
                    'api_prefix' => $apiPrefix,
                    'endpoint' => $endpoint,
                    'final_url' => $url,
                    'status' => $response->status(),
                    'successful' => $response->successful(),
                    'is_json' => $isJson,
                    'body_preview' => $bodyPreview,
                    'body_length' => strlen($response->body()),
                    'content_type' => $response->header('Content-Type'),
                ],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'G'
            ]) . "\n", FILE_APPEND);
            // #endregion

            if ($response->successful()) {
                $data = $response->json();
                
                // #region agent log
                @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'GrimaldiApiClient.php:makeAuthenticatedRequest',
                    'message' => 'Response parsed as JSON',
                    'data' => ['endpoint' => $endpoint, 'json_data' => $data, 'json_type' => gettype($data), 'is_array' => is_array($data), 'is_null' => is_null($data)],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'B'
                ]) . "\n", FILE_APPEND);
                // #endregion
                
                Log::debug('Grimaldi API request successful', [
                    'endpoint' => $endpoint,
                    'response_type' => gettype($data),
                ]);
                
                return $data;
            }

            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:makeAuthenticatedRequest',
                'message' => 'HTTP response not successful',
                'data' => ['endpoint' => $endpoint, 'status' => $response->status(), 'body' => $response->body(), 'is_401' => $response->status() === 401],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B'
            ]) . "\n", FILE_APPEND);
            // #endregion

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
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:probeBaseUrlCapabilities',
                'message' => 'Using cached capabilities',
                'data' => ['base_url' => $baseUrl, 'capabilities' => $cached],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'H'
            ]) . "\n", FILE_APPEND);
            // #endregion
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
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:probeBaseUrlCapabilities:info',
                'message' => 'Info endpoint probe',
                'data' => ['base_url' => $baseUrl, 'url' => $infoUrl, 'status' => $response->status(), 'successful' => $response->successful()],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'H'
            ]) . "\n", FILE_APPEND);
            // #endregion
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
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:probeBaseUrlCapabilities:sailing_voyage',
                'message' => 'SailingSchedule (VoyageNo) probe',
                'data' => ['base_url' => $baseUrl, 'url' => $voyageUrl, 'status' => $response->status(), 'successful' => $response->successful(), 'body_preview' => substr($response->body(), 0, 300)],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'H'
            ]) . "\n", FILE_APPEND);
            // #endregion
        } catch (\Exception $e) {
            Log::warning('Error probing SailingSchedule (VoyageNo)', ['base_url' => $baseUrl, 'error' => $e->getMessage()]);
        }

        // Probe 3: SailingSchedule with parameters
        try {
            $paramsUrl = rtrim($baseUrl, '/') . $apiPrefix . '/SailingSchedule?GrimaldiTrade=NEWAF_RORO&nDays=1&POL=BEANR&POD=CIABJ';
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
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'GrimaldiApiClient.php:probeBaseUrlCapabilities:sailing_params',
                'message' => 'SailingSchedule (params) probe',
                'data' => ['base_url' => $baseUrl, 'url' => $paramsUrl, 'status' => $response->status(), 'successful' => $response->successful(), 'content_type' => $response->header('Content-Type'), 'body_preview' => substr($response->body(), 0, 300)],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'H'
            ]) . "\n", FILE_APPEND);
            // #endregion
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

        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'GrimaldiApiClient.php:findWorkingBaseUrl',
            'message' => 'Finding working base URL',
            'data' => ['base_urls_to_test' => $baseUrls],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'H'
        ]) . "\n", FILE_APPEND);
        // #endregion

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
                
                // #region agent log
                @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'GrimaldiApiClient.php:findWorkingBaseUrl:found',
                    'message' => 'Working base URL found',
                    'data' => ['base_url' => $baseUrl, 'capabilities' => $capabilities],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'H'
                ]) . "\n", FILE_APPEND);
                // #endregion
                
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
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'GrimaldiApiClient.php:getSailingSchedule',
            'message' => 'Getting sailing schedule',
            'data' => ['pol' => $pol, 'pod' => $pod, 'nDays' => $nDays],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'H'
        ]) . "\n", FILE_APPEND);
        // #endregion
        
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
        
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'GrimaldiApiClient.php:getSailingSchedule:capabilities_check',
            'message' => 'Checking endpoint capabilities',
            'data' => ['base_url' => $workingBaseUrl, 'params_endpoint_works' => $paramsEndpointWorks, 'voyage_endpoint_works' => $voyageEndpointWorks, 'capabilities' => $capabilities],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'H'
        ]) . "\n", FILE_APPEND);
        // #endregion
        
        // Temporarily switch to working base URL
        $originalBaseUrl = $this->baseUrl;
        $this->baseUrl = $workingBaseUrl;
        $this->apiPrefix = null; // Reset to force detection with new base
        
        try {
            // If params endpoint works, use it
            if ($paramsEndpointWorks) {
                // Try different trade routes
                $tradeRoutes = ['NEWAF_RORO', 'NAWAF_RORO', 'SHORTSEA'];
                $schedules = null;
                
                foreach ($tradeRoutes as $tradeRoute) {
                    try {
                        // #region agent log
                        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                            'timestamp' => time() * 1000,
                            'location' => 'GrimaldiApiClient.php:getSailingSchedule:try_trade_route',
                            'message' => 'Trying trade route (params endpoint)',
                            'data' => ['base_url' => $workingBaseUrl, 'trade_route' => $tradeRoute, 'pol' => $pol, 'pod' => $pod, 'nDays' => $nDays],
                            'sessionId' => 'debug-session',
                            'runId' => 'run1',
                            'hypothesisId' => 'H'
                        ]) . "\n", FILE_APPEND);
                        // #endregion
                        
                        $schedules = $this->makeAuthenticatedRequest('SailingSchedule', [
                            'GrimaldiTrade' => $tradeRoute,
                            'POL' => $pol,
                            'POD' => $pod,
                            'nDays' => $nDays,
                        ]);
                        
                        // #region agent log
                        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                            'timestamp' => time() * 1000,
                            'location' => 'GrimaldiApiClient.php:getSailingSchedule:result',
                            'message' => 'Trade route result',
                            'data' => ['base_url' => $workingBaseUrl, 'trade_route' => $tradeRoute, 'pol' => $pol, 'pod' => $pod, 'schedules_count' => is_array($schedules) ? count($schedules) : 0, 'is_array' => is_array($schedules), 'is_null' => is_null($schedules), 'success' => !empty($schedules) && is_array($schedules)],
                            'sessionId' => 'debug-session',
                            'runId' => 'run1',
                            'hypothesisId' => 'H'
                        ]) . "\n", FILE_APPEND);
                        // #endregion
                        
                        if (!empty($schedules) && is_array($schedules)) {
                            Log::info('Grimaldi schedules found', [
                                'base_url' => $workingBaseUrl,
                                'trade_route' => $tradeRoute,
                                'pol' => $pol,
                                'pod' => $pod,
                                'count' => count($schedules),
                            ]);
                            break; // Found schedules, stop trying other routes
                        }
                    } catch (\Exception $e) {
                        Log::warning('Grimaldi API request failed for trade route', [
                            'base_url' => $workingBaseUrl,
                            'pol' => $pol,
                            'pod' => $pod,
                            'trade_route' => $tradeRoute,
                            'error' => $e->getMessage()
                        ]);
                        continue; // Try next trade route
                    }
                }
                
                return $schedules ?? [];
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
                
                // #region agent log
                $logFile = base_path('.cursor/debug.log');
                $logEntry = json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'GrimaldiApiClient.php:getSailingSchedule:exception_thrown',
                    'message' => 'Throwing exception - POL/POD queries not supported',
                    'data' => ['base_url' => $workingBaseUrl, 'params_endpoint_works' => $paramsEndpointWorks, 'voyage_endpoint_works' => $voyageEndpointWorks, 'tested_urls' => $testedUrls],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'H'
                ]) . "\n";
                @file_put_contents($logFile, $logEntry, FILE_APPEND);
                @fflush(fopen($logFile, 'a')); // Force flush
                // #endregion
                
                Log::error('Grimaldi API limitation: POL/POD queries not supported', [
                    'base_url' => $workingBaseUrl,
                    'capabilities' => $capabilities,
                    'tested_urls' => $testedUrls,
                ]);
                
                throw new \Exception(
                    "Grimaldi API limitation: SailingSchedule endpoint supports VoyageNo queries but NOT POL/POD queries.\n" .
                    "The endpoint GET {base}/api/SailingSchedule?GrimaldiTrade=...&POL=...&POD=... returns 404.\n" .
                    "Only GET {base}/api/SailingSchedule?VoyageNo=... is available.\n\n" .
                    "Tested URLs and status codes:\n" .
                    json_encode($testedUrls, JSON_PRETTY_PRINT) . "\n\n" .
                    "To get schedules by POL/POD, you would need:\n" .
                    "1. First query voyages using a different endpoint (if available)\n" .
                    "2. Then query each voyage using SailingSchedule?VoyageNo=...\n" .
                    "OR contact Grimaldi support to enable POL/POD queries on the SailingSchedule endpoint."
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

