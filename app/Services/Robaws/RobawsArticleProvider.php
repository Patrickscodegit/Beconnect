<?php

namespace App\Services\Robaws;

use App\Models\RobawsArticleCache;
use App\Models\RobawsSyncLog;
use App\Services\Export\Clients\RobawsApiClient;
use App\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class RobawsArticleProvider
{
    private const RATE_LIMIT_CACHE_KEY = 'robaws_rate_limit';

    public function __construct(
        private RobawsApiClient $robawsClient
    ) {}

    /**
     * Sync articles from Robaws offers (since /api/v2/articles returns 403)
     * Extracts articles from line items in offers
     * Following Robaws best practices with rate limiting and idempotency
     */
    public function syncArticles(): int
    {
        if (!config('quotation.enabled')) {
            Log::info('Quotation system disabled, skipping article sync.');
            return 0;
        }

        $syncLog = RobawsSyncLog::create([
            'sync_type' => 'articles',
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting Robaws article extraction from offers');

            // Get configuration
            $maxOffers = config('quotation.article_extraction.max_offers_to_process', 500);
            $batchSize = config('quotation.article_extraction.batch_size', 50);
            $totalPages = (int) ceil($maxOffers / $batchSize);

            $articlesMap = [];
            $relationshipsMap = [];
            $processedOffers = 0;

            // Fetch offers in batches
            for ($page = 0; $page < $totalPages; $page++) {
                $this->checkRateLimit();

                $idempotencyKey = 'article_extraction_offers_page_' . $page . '_' . date('Y-m-d');

                Log::info('Fetching offers batch', [
                    'page' => $page,
                    'size' => $batchSize
                ]);

                $response = $this->robawsClient->getHttpClientForQuotation()
                    ->withHeader('Idempotency-Key', $idempotencyKey)
                    ->get('/api/v2/offers', [
                        'page' => $page,
                        'size' => $batchSize,
                        'include' => 'lineItems,client'
                    ]);

                $this->handleRateLimitResponse($response);

                if (!$response->successful()) {
                    Log::warning('Failed to fetch offers page', [
                        'page' => $page,
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    continue;
                }

                $data = $response->json();
                $offers = $data['items'] ?? [];

                foreach ($offers as $offer) {
                    $lineItems = $offer['lineItems'] ?? [];
                    
                    if (empty($lineItems)) {
                        continue;
                    }

                    // Extract articles from line items
                    foreach ($lineItems as $item) {
                        $articleId = $item['articleId'] ?? $item['id'] ?? null;
                        
                        if (!$articleId) {
                            continue;
                        }

                        // Build article data if not already processed
                        if (!isset($articlesMap[$articleId])) {
                            $articlesMap[$articleId] = $this->buildArticleData($item, $offer);
                        }
                    }

                    // Detect parent-child relationships in this offer
                    $relationships = $this->detectParentChildRelationships($lineItems);
                    $relationshipsMap = array_merge_recursive($relationshipsMap, $relationships);

                    $processedOffers++;
                }

                // Delay between batches to respect rate limits
                if ($page < $totalPages - 1) {
                    usleep(config('quotation.article_extraction.request_delay_ms', 500) * 1000);
                }
            }

            Log::info('Article extraction completed', [
                'offers_processed' => $processedOffers,
                'unique_articles' => count($articlesMap),
                'parent_child_relationships' => count($relationshipsMap)
            ]);

            // Cache articles
            $syncedCount = $this->cacheExtractedArticles($articlesMap);

            // Create parent-child relationships
            $this->createParentChildLinks($relationshipsMap);

            $syncLog->markAsCompleted($syncedCount);

            Log::info('Robaws article sync completed', [
                'articles_synced' => $syncedCount
            ]);

            return $syncedCount;

        } catch (\Exception $e) {
            $syncLog->markAsFailed($e->getMessage());

            Log::error('Robaws article sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Build article data from line item
     */
    private function buildArticleData(array $lineItem, array $offer): array
    {
        $description = $lineItem['description'] ?? $lineItem['name'] ?? '';
        $unitPrice = $lineItem['unitPrice'] ?? $lineItem['price'] ?? null;
        $currency = $lineItem['currency'] ?? $offer['currency'] ?? 'EUR';

        // Extract article code
        $articleCode = $this->parseArticleCode($description);

        // Map to service type
        $serviceTypes = $this->mapToServiceType($articleCode, $description);

        // Parse quantity tier
        $quantityTier = $this->parseQuantityTier($description);

        // Detect pricing formula
        $pricingFormula = $this->detectPricingFormula($description, $unitPrice);

        // Parse carrier
        $carriers = $this->parseCarrierFromDescription($description);

        // Extract customer type
        $customerType = $this->extractCustomerType($description);

        // Determine category
        $category = $this->determineCategoryFromDescription($description);

        // Determine if parent article
        $isParent = $this->isParentArticle($description);

        // Determine if surcharge
        $isSurcharge = $this->isSurchargeArticle($description);

        return [
            'robaws_article_id' => $lineItem['articleId'] ?? $lineItem['id'],
            'article_code' => $articleCode,
            'article_name' => $description,
            'description' => $description,
            'category' => $category,
            'applicable_services' => $serviceTypes,
            'applicable_carriers' => $carriers,
            'customer_type' => $customerType,
            'min_quantity' => $quantityTier['min'] ?? 1,
            'max_quantity' => $quantityTier['max'] ?? 1,
            'tier_label' => $quantityTier['label'] ?? null,
            'unit_price' => $unitPrice,
            'currency' => $currency,
            'pricing_formula' => $pricingFormula,
            'is_parent_article' => $isParent,
            'is_surcharge' => $isSurcharge,
            'is_active' => true,
            'requires_manual_review' => $this->requiresManualReview($description, $articleCode),
            'last_synced_at' => now(),
        ];
    }

    /**
     * Cache extracted articles
     */
    private function cacheExtractedArticles(array $articlesMap): int
    {
        $syncedCount = 0;

        foreach ($articlesMap as $articleId => $articleData) {
            try {
                RobawsArticleCache::updateOrCreate(
                    ['robaws_article_id' => $articleId],
                    $articleData
                );

                $syncedCount++;
            } catch (\Exception $e) {
                Log::warning('Failed to cache article', [
                    'article_id' => $articleId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $syncedCount;
    }

    /**
     * Create parent-child links
     */
    private function createParentChildLinks(array $relationshipsMap): void
    {
        foreach ($relationshipsMap as $parentArticleId => $childIds) {
            $parent = RobawsArticleCache::where('robaws_article_id', $parentArticleId)->first();
            
            if (!$parent) {
                continue;
            }

            foreach ($childIds as $index => $childArticleId) {
                $child = RobawsArticleCache::where('robaws_article_id', $childArticleId)->first();
                
                if (!$child) {
                    continue;
                }

                // Check if relationship already exists
                $exists = $parent->children()->wherePivot('child_article_id', $child->id)->exists();
                
                if (!$exists) {
                    $parent->children()->attach($child->id, [
                        'sort_order' => $index + 1,
                        'is_required' => true,
                        'is_conditional' => false,
                    ]);

                    Log::info('Created parent-child relationship', [
                        'parent' => $parent->article_name,
                        'child' => $child->article_name
                    ]);
                }
            }
        }
    }

    /**
     * Detect parent-child relationships from line items sequence
     * Pattern: Parent article followed by surcharges mentioning parent name
     */
    private function detectParentChildRelationships(array $lineItems): array
    {
        $relationships = [];
        $currentParent = null;

        foreach ($lineItems as $item) {
            $description = $item['description'] ?? $item['name'] ?? '';
            $articleId = $item['articleId'] ?? $item['id'] ?? null;

            if (!$articleId) {
                continue;
            }

            // Detect parent articles (main services)
            if ($this->isParentArticle($description)) {
                $currentParent = [
                    'id' => $articleId,
                    'description' => $description,
                    'keywords' => $this->extractParentKeywords($description)
                ];
                
                // Initialize relationship array for this parent
                if (!isset($relationships[$articleId])) {
                    $relationships[$articleId] = [];
                }
                continue;
            }

            // Detect child articles (surcharges, add-ons)
            if ($this->isChildArticle($description) && $currentParent) {
                // Check if this child belongs to the current parent
                if ($this->childBelongsToParent($description, $currentParent)) {
                    $relationships[$currentParent['id']][] = $articleId;
                }
            }
        }

        return $relationships;
    }

    /**
     * Extract keywords from description for matching
     */
    private function extractKeywordsFromDescription(string $description): array
    {
        // Remove common words and extract meaningful parts
        $keywords = [];
        $words = preg_split('/[\s\-,]+/', $description);
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 3 && !in_array(strtolower($word), ['from', 'with', 'service', 'import', 'export'])) {
                $keywords[] = strtolower($word);
            }
        }

        return array_unique($keywords);
    }

    /**
     * Extract parent-specific keywords from description
     * Based on GANRLAGSV pattern: carrier + route + service type
     */
    private function extractParentKeywords(string $description): array
    {
        $keywords = [];
        $desc = strtoupper($description);
        
        // Extract carrier name (e.g., "Grimaldi" from "Grimaldi(ANR 1333)")
        if (preg_match('/([A-Z]{3,20})\(/', $desc, $matches)) {
            $keywords[] = strtolower($matches[1]);
        }
        
        // Extract route info (e.g., "Lagos", "Nigeria")
        $routeWords = preg_split('/[\s\-\(\)\[\],]+/', $desc);
        foreach ($routeWords as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, ['SEAFREIGHT', 'SERVICE', 'IMPORT', 'EXPORT', 'SMALL', 'VAN', 'CAR', 'BV', 'SV', 'ANR', 'ZEE'])) {
                $keywords[] = strtolower($word);
            }
        }
        
        // Extract service type (e.g., "SV" from "SMALL VAN")
        if (str_contains($desc, 'SMALL VAN') || str_contains($desc, 'SV')) {
            $keywords[] = 'sv';
        }
        if (str_contains($desc, 'BIG VAN') || str_contains($desc, 'BV')) {
            $keywords[] = 'bv';
        }
        if (str_contains($desc, 'CAR')) {
            $keywords[] = 'car';
        }
        
        return array_unique($keywords);
    }

    /**
     * Check if child article belongs to parent based on keywords
     */
    private function childBelongsToParent(string $childDescription, array $parent): bool
    {
        $childDesc = strtolower($childDescription);
        $parentKeywords = $parent['keywords'];
        
        // Direct keyword matching
        foreach ($parentKeywords as $keyword) {
            if (str_contains($childDesc, $keyword)) {
                return true;
            }
        }
        
        // Special patterns for surcharges (from your screenshot)
        $surchargePatterns = [
            'surcharge', 'sur charge', 'additional', 'extra', 'admin', 'customs',
            'courrier', 'waiver', 'besc', 'exa', 'waf'
        ];
        
        foreach ($surchargePatterns as $pattern) {
            if (str_contains($childDesc, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Parse article code from description
     * Examples: GANRLAGSV, BWFCLIMP, BWA-FCL, CIB-RORO-IMP
     */
    private function parseArticleCode(string $description): ?string
    {
        // Pattern 1: Service code at start (GANRLAGSV, BWFCLIMP)
        if (preg_match('/^([A-Z]{6,15})\b/', $description, $matches)) {
            return $matches[1];
        }

        // Pattern 2: Code with hyphens (BWA-FCL, CIB-RORO-IMP)
        if (preg_match('/\b([A-Z]{2,5}-[A-Z]{2,8}(?:-[A-Z]{2,5})?)\b/', $description, $matches)) {
            return $matches[1];
        }

        // Pattern 3: Code in parentheses (ANR 1333, ZEE 1234)
        if (preg_match('/[\(\[]([A-Z]{3,6}\s*\d{3,6})[\)\]]/', $description, $matches)) {
            return str_replace(' ', '', $matches[1]); // Remove spaces
        }

        // Pattern 4: Extract from carrier pattern (Grimaldi(ANR 1333) -> GANRLAG)
        if (preg_match('/([A-Z]{3,20})\([A-Z]{3,6}\s*\d{3,6}\)/', $description, $matches)) {
            $carrier = strtolower($matches[1]);
            // Convert to GANRLAG pattern
            if ($carrier === 'grimaldi') {
                return 'GANRLAG';
            }
            // Add more carrier mappings as needed
            return strtoupper(substr($carrier, 0, 6));
        }

        return null;
    }

    /**
     * Map article to service types based on code and description
     */
    private function mapToServiceType(?string $articleCode, string $description): array
    {
        $services = [];
        $desc = strtoupper($description);
        $code = strtoupper($articleCode ?? '');

        // RORO services
        if (str_contains($desc, 'RORO') || str_contains($code, 'RORO')) {
            if (str_contains($desc, 'IMPORT') || str_contains($code, 'IMP')) {
                $services[] = 'RORO_IMPORT';
            } elseif (str_contains($desc, 'EXPORT') || str_contains($code, 'EXP')) {
                $services[] = 'RORO_EXPORT';
            } else {
                $services[] = 'RORO_IMPORT';
                $services[] = 'RORO_EXPORT';
            }
        }

        // FCL services
        if (str_contains($desc, 'FCL') || str_contains($desc, 'CONTAINER') || str_contains($code, 'FCL')) {
            if (str_contains($desc, 'CONSOL') || str_contains($desc, 'CONSOLIDAT')) {
                $services[] = 'FCL_CONSOL_EXPORT';
            } elseif (str_contains($desc, 'IMPORT') || str_contains($code, 'IMP')) {
                $services[] = 'FCL_IMPORT';
            } elseif (str_contains($desc, 'EXPORT') || str_contains($code, 'EXP')) {
                $services[] = 'FCL_EXPORT';
            } else {
                $services[] = 'FCL_IMPORT';
                $services[] = 'FCL_EXPORT';
            }
        }

        // LCL services
        if (str_contains($desc, 'LCL')) {
            if (str_contains($desc, 'IMPORT') || str_contains($code, 'IMP')) {
                $services[] = 'LCL_IMPORT';
            } elseif (str_contains($desc, 'EXPORT') || str_contains($code, 'EXP')) {
                $services[] = 'LCL_EXPORT';
            } else {
                $services[] = 'LCL_IMPORT';
                $services[] = 'LCL_EXPORT';
            }
        }

        // Break Bulk
        if (str_contains($desc, 'BREAK BULK') || str_contains($desc, 'BREAKBULK') || str_contains($code, 'BB')) {
            if (str_contains($desc, 'IMPORT')) {
                $services[] = 'BB_IMPORT';
            } elseif (str_contains($desc, 'EXPORT')) {
                $services[] = 'BB_EXPORT';
            }
        }

        // Air services
        if (str_contains($desc, 'AIR') || str_contains($desc, 'AIRFREIGHT')) {
            if (str_contains($desc, 'IMPORT')) {
                $services[] = 'AIR_IMPORT';
            } elseif (str_contains($desc, 'EXPORT')) {
                $services[] = 'AIR_EXPORT';
            }
        }

        return array_unique($services);
    }

    /**
     * Parse quantity tier from description
     * Examples: "1-pack", "2 pack container", "3-pack", "4 pack"
     */
    private function parseQuantityTier(string $description): array
    {
        $desc = strtolower($description);

        // Pattern: "X-pack" or "X pack"
        if (preg_match('/(\d+)[\s-]pack/', $desc, $matches)) {
            $quantity = (int) $matches[1];
            return [
                'min' => $quantity,
                'max' => $quantity,
                'label' => $quantity . '-pack'
            ];
        }

        // Pattern: "X container" or "X vehicles"
        if (preg_match('/(\d+)\s+(container|vehicle|car|unit)s?/', $desc, $matches)) {
            $quantity = (int) $matches[1];
            if ($quantity >= 1 && $quantity <= 4) {
                return [
                    'min' => $quantity,
                    'max' => $quantity,
                    'label' => $quantity . '-pack'
                ];
            }
        }

        // Default: 1
        return [
            'min' => 1,
            'max' => 1,
            'label' => null
        ];
    }

    /**
     * Detect pricing formula from description
     * Examples: "ocean freight / 2 + 800", "half of seafreight plus 800"
     */
    private function detectPricingFormula(string $description, $price): ?array
    {
        $desc = strtolower($description);

        // Pattern 1: "/ 2 + 800" or "divided by 2 plus 800"
        if (preg_match('/(ocean|sea)\s*freight.*?\/\s*(\d+)\s*\+\s*(\d+)/', $desc, $matches)) {
            return [
                'type' => 'formula',
                'base' => 'ocean_freight',
                'divisor' => (int) $matches[2],
                'fixed_amount' => (float) $matches[3]
            ];
        }

        // Pattern 2: "half" keyword
        if (str_contains($desc, 'half') && str_contains($desc, 'ocean')) {
            // Try to find fixed amount
            if (preg_match('/\+\s*(\d+)/', $desc, $matches)) {
                return [
                    'type' => 'formula',
                    'base' => 'ocean_freight',
                    'divisor' => 2,
                    'fixed_amount' => (float) $matches[1]
                ];
            }
        }

        return null;
    }

    /**
     * Parse carrier from description using known carriers list
     */
    private function parseCarrierFromDescription(string $description): array
    {
        $carriers = [];
        $desc = strtoupper($description);
        $knownCarriers = config('quotation.known_carriers', []);

        foreach ($knownCarriers as $carrier) {
            if (str_contains($desc, strtoupper($carrier))) {
                $carriers[] = $carrier;
            }
        }

        return array_unique($carriers);
    }

    /**
     * Extract customer type from description
     */
    private function extractCustomerType(string $description): ?string
    {
        $desc = strtoupper($description);

        $customerTypes = config('quotation.customer_types', []);
        
        foreach (array_keys($customerTypes) as $type) {
            if (str_contains($desc, strtoupper($type))) {
                return $type;
            }
        }

        // Check for common keywords
        if (str_contains($desc, 'FORWARDER')) {
            return 'FORWARDERS';
        }
        if (str_contains($desc, 'CIB')) {
            return 'CIB';
        }
        if (str_contains($desc, 'PRIVATE')) {
            return 'PRIVATE';
        }

        return null;
    }

    /**
     * Check if article is a parent article
     */
    private function isParentArticle(string $description): bool
    {
        $desc = strtolower($description);

        // Parent articles are main seafreight services (like GANRLAGSV)
        return str_contains($desc, 'seafreight') && 
               !str_contains($desc, 'surcharge') && 
               !str_contains($desc, 'additional') &&
               !str_contains($desc, 'courrier') &&
               !str_contains($desc, 'admin') &&
               !str_contains($desc, 'customs') &&
               !str_contains($desc, 'waiver');
    }

    /**
     * Check if this is a child article (surcharge/additional)
     */
    private function isChildArticle(string $description): bool
    {
        $desc = strtolower($description);

        // Child articles are surcharges, admin fees, customs, etc.
        return str_contains($desc, 'surcharge') || 
               str_contains($desc, 'sur charge') ||
               str_contains($desc, 'additional') ||
               str_contains($desc, 'extra') ||
               str_contains($desc, 'courrier') ||
               str_contains($desc, 'admin') ||
               str_contains($desc, 'customs') ||
               str_contains($desc, 'waiver') ||
               str_contains($desc, 'besc') ||
               str_contains($desc, 'exa') ||
               str_contains($desc, 'waf');
    }

    /**
     * Check if article is a surcharge
     */
    private function isSurchargeArticle(string $description): bool
    {
        return $this->isChildArticle($description);
    }

    /**
     * Check if article requires manual review
     */
    private function requiresManualReview(string $description, ?string $articleCode): bool
    {
        // Require review if no code could be parsed
        if (!$articleCode) {
            return true;
        }

        // Require review if description is very short (likely incomplete)
        if (strlen($description) < 10) {
            return true;
        }

        return false;
    }

    /**
     * Determine category from description
     */
    private function determineCategoryFromDescription(string $description): string
    {
        $name = strtolower($description);

        if (str_contains($name, 'seafreight') || str_contains($name, 'ocean')) {
            return 'seafreight';
        } elseif (str_contains($name, 'precarriage') || str_contains($name, 'pre-carriage') || str_contains($name, 'trucking to port')) {
            return 'precarriage';
        } elseif (str_contains($name, 'oncarriage') || str_contains($name, 'on-carriage') || str_contains($name, 'trucking from port')) {
            return 'oncarriage';
        } elseif (str_contains($name, 'customs') || str_contains($name, 'clearance')) {
            return 'customs';
        } elseif (str_contains($name, 'warehouse') || str_contains($name, 'storage')) {
            return 'warehouse';
        } elseif (str_contains($name, 'insurance')) {
            return 'insurance';
        } elseif (str_contains($name, 'documentation') || str_contains($name, 'document') || str_contains($name, 'admin')) {
            return 'administration';
        } elseif (str_contains($name, 'surcharge')) {
            return 'miscellaneous';
        } else {
            return 'general';
        }
    }

    /**
     * Get articles for a specific carrier
     */
    public function getArticlesForCarrier(string $carrierCode): Collection
    {
        return RobawsArticleCache::active()
            ->forCarrier($carrierCode)
            ->get();
    }

    /**
     * Get articles for a service type
     */
    public function getArticlesForServiceType(string $serviceType): Collection
    {
        return RobawsArticleCache::active()
            ->forService($serviceType)
            ->get();
    }

    /**
     * Get articles by category
     */
    public function getArticlesByCategory(string $category): Collection
    {
        return RobawsArticleCache::active()
            ->byCategory($category)
            ->get();
    }

    /**
     * Check rate limit before making requests
     * Critical: Robaws monitors 429 errors and will block integration if too many occur
     */
    private function checkRateLimit(): void
    {
        $rateLimitData = Cache::get(self::RATE_LIMIT_CACHE_KEY);

        if ($rateLimitData && isset($rateLimitData['remaining'])) {
            if ($rateLimitData['remaining'] <= 5) {
                $waitTime = max(0, ($rateLimitData['reset_time'] ?? time()) - time());

                if ($waitTime > 0 && $waitTime <= 60) {
                    Log::info('Approaching rate limit, waiting', [
                        'wait_seconds' => $waitTime,
                        'remaining_requests' => $rateLimitData['remaining']
                    ]);

                    sleep($waitTime);
                }
            }
        }
    }

    /**
     * Handle rate limit response from Robaws
     */
    private function handleRateLimitResponse($response): void
    {
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After')[0] ?? 60);

            Log::warning('Robaws rate limit exceeded', [
                'retry_after' => $retryAfter,
                'endpoint' => $response->effectiveUri()
            ]);

            Cache::put(self::RATE_LIMIT_CACHE_KEY, [
                'remaining' => 0,
                'reset_time' => time() + $retryAfter
            ], $retryAfter);

            throw RateLimitException::exceeded($retryAfter);
        }

        // Store rate limit headers for future requests
        $headers = $response->headers();
        
        if (isset($headers['X-RateLimit-Remaining'])) {
            $remaining = (int) ($headers['X-RateLimit-Remaining'][0] ?? 100);
            $reset = (int) ($headers['X-RateLimit-Reset'][0] ?? time() + 3600);

            Cache::put(self::RATE_LIMIT_CACHE_KEY, [
                'remaining' => $remaining,
                'reset_time' => $reset
            ], 3600);

            if ($remaining <= 10) {
                Log::warning('Robaws rate limit getting low', [
                    'remaining' => $remaining,
                    'reset_time' => date('Y-m-d H:i:s', $reset)
                ]);
            }
        }
    }

    /**
     * Get article details from Robaws
     */
    public function getArticleDetails(string $articleId): ?array
    {
        try {
            $this->checkRateLimit();

            Log::debug('Fetching article details from Robaws', [
                'article_id' => $articleId,
                'endpoint' => "/api/v2/articles/{$articleId}"
            ]);

            $response = $this->robawsClient->getHttpClientForQuotation()
                ->get("/api/v2/articles/{$articleId}");

            $this->handleRateLimitResponse($response);

            if ($response->successful()) {
                Log::debug('Successfully fetched article details', [
                    'article_id' => $articleId
                ]);
                return $response->json();
            }

            // Log unsuccessful response
            Log::error('Robaws API returned unsuccessful response', [
                'article_id' => $articleId,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'headers' => $response->headers()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Failed to get article details from Robaws', [
                'article_id' => $articleId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Sync article metadata for a specific article
     * Fetches IMPORTANT INFO and ARTICLE INFO metadata from Robaws
     * Falls back to description extraction if API is unavailable
     */
    public function syncArticleMetadata(int $articleId): array
    {
        try {
            $article = RobawsArticleCache::find($articleId);
            
            if (!$article) {
                throw new \Exception("Article not found in cache: {$articleId}");
            }

            // Try to fetch full article details from Robaws API first
            $details = $this->getArticleDetails($article->robaws_article_id);
            
            if ($details) {
                // ✅ API success - parse from API response
                $metadata = $this->parseArticleMetadata($details);
                $source = 'api';
            } else {
                // ⚠️ API failed - use fallback extraction from article description
                Log::warning('Robaws API unavailable, using fallback extraction', [
                    'article_id' => $articleId,
                    'robaws_article_id' => $article->robaws_article_id,
                    'article_name' => $article->article_name
                ]);
                
                $metadata = $this->extractMetadataFromArticle($article);
                $source = 'fallback';
            }
            
            // Update article with metadata
            $article->update($metadata);

            Log::info('Article metadata synced', [
                'article_id' => $articleId,
                'source' => $source,
                'metadata_keys' => array_keys($metadata)
            ]);

            return $metadata;

        } catch (\Exception $e) {
            Log::error('Failed to sync article metadata', [
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Sync composite items (children) for a parent article
     * Fetches and links child articles from Robaws article data
     * Gracefully handles API unavailability
     */
    public function syncCompositeItems(int $parentArticleId): void
    {
        try {
            $parent = RobawsArticleCache::find($parentArticleId);
            
            if (!$parent) {
                throw new \Exception("Parent article not found: {$parentArticleId}");
            }

            // Try to fetch full article details from Robaws API
            $details = $this->getArticleDetails($parent->robaws_article_id);
            
            if (!$details) {
                Log::warning('Cannot sync composite items - API unavailable', [
                    'parent_article_id' => $parentArticleId,
                    'parent_article_name' => $parent->article_name
                ]);
                return; // Gracefully skip instead of throwing
            }

            // Parse composite items
            $compositeItems = $this->parseCompositeItems($details);
            
            if (empty($compositeItems)) {
                Log::info('No composite items found for parent article', [
                    'parent_id' => $parentArticleId
                ]);
                return;
            }

            // Link composite items as children
            foreach ($compositeItems as $index => $item) {
                $child = $this->findOrCreateChildArticle($item);
                
                if ($child) {
                    // Check if relationship already exists
                    $exists = $parent->children()->wherePivot('child_article_id', $child->id)->exists();
                    
                    if (!$exists) {
                        $parent->children()->attach($child->id, [
                            'sort_order' => $index + 1,
                            'is_required' => $item['is_required'] ?? true,
                            'is_conditional' => false,
                            'cost_type' => $item['cost_type'] ?? null,
                            'default_quantity' => $item['quantity'] ?? 1.00,
                            'default_cost_price' => $item['cost_price'] ?? null,
                            'unit_type' => $item['unit_type'] ?? null,
                        ]);

                        Log::info('Linked composite item to parent', [
                            'parent' => $parent->article_name,
                            'child' => $child->article_name
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to sync composite items', [
                'parent_article_id' => $parentArticleId,
                'error' => $e->getMessage()
            ]);

            // Don't throw - just log and continue
            // This allows the system to continue working even if some articles fail
        }
    }

    /**
     * Parse article metadata from Robaws API response
     * Extracts IMPORTANT INFO and ARTICLE INFO fields
     */
    private function parseArticleMetadata(array $details): array
    {
        $metadata = [];

        // Parse ARTICLE INFO section
        $articleInfo = $this->parseArticleInfo($details);
        $metadata = array_merge($metadata, $articleInfo);

        // Parse IMPORTANT INFO section
        $importantInfo = $this->parseImportantInfo($details);
        $metadata = array_merge($metadata, $importantInfo);

        return $metadata;
    }

    /**
     * Parse ARTICLE INFO section from Robaws data
     * Fields: shipping_line, service_type, pol_terminal, parent_item
     */
    private function parseArticleInfo(array $rawData): array
    {
        $info = [];

        // Extract from extraFields if available
        $extraFields = $rawData['extraFields'] ?? [];
        
        foreach ($extraFields as $field) {
            $code = $field['code'] ?? '';
            $value = $field['stringValue'] ?? $field['value'] ?? null;

            switch ($code) {
                case 'SHIPPING_LINE':
                    $info['shipping_line'] = $value;
                    break;
                case 'SERVICE_TYPE':
                    $info['service_type'] = $value;
                    break;
                case 'POL_TERMINAL':
                    $info['pol_terminal'] = $value;
                    break;
                case 'PARENT_ITEM':
                    $info['is_parent_item'] = (bool) $value;
                    break;
            }
        }

        // Fallback: try to extract from description or metadata
        if (empty($info['shipping_line'])) {
            $info['shipping_line'] = $this->extractShippingLineFromDescription($rawData['description'] ?? $rawData['name'] ?? '');
        }

        if (empty($info['service_type'])) {
            $info['service_type'] = $this->extractServiceTypeFromDescription($rawData['description'] ?? $rawData['name'] ?? '');
        }

        return $info;
    }

    /**
     * Parse IMPORTANT INFO section from Robaws data
     * Fields: article_info, update_date, validity_date
     */
    private function parseImportantInfo(array $rawData): array
    {
        $info = [];

        // Extract from extraFields if available
        $extraFields = $rawData['extraFields'] ?? [];
        
        foreach ($extraFields as $field) {
            $code = $field['code'] ?? '';
            $value = $field['stringValue'] ?? $field['value'] ?? null;

            switch ($code) {
                case 'ARTICLE_INFO':
                case 'INFO':
                    $info['article_info'] = $value;
                    break;
                case 'UPDATE_DATE':
                    $info['update_date'] = $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
                    break;
                case 'VALIDITY_DATE':
                    $info['validity_date'] = $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
                    break;
            }
        }

        return $info;
    }

    /**
     * Parse composite items from Robaws article data
     * Returns array of child articles with their metadata
     */
    private function parseCompositeItems(array $rawData): array
    {
        $compositeItems = [];

        // Look for composite items in various possible locations
        $items = $rawData['compositeItems'] ?? $rawData['children'] ?? $rawData['lineItems'] ?? [];

        foreach ($items as $item) {
            $compositeItems[] = [
                'robaws_article_id' => $item['articleId'] ?? $item['id'] ?? null,
                'name' => $item['description'] ?? $item['name'] ?? '',
                'cost_type' => $item['costType'] ?? $item['cost_type'] ?? 'Material',
                'description' => $item['description'] ?? '',
                'unit_type' => $item['unitType'] ?? $item['unit_type'] ?? '',
                'quantity' => $item['quantity'] ?? 1.00,
                'cost_price' => $item['costPrice'] ?? $item['unitPrice'] ?? null,
                'is_required' => $item['isRequired'] ?? true,
            ];
        }

        return $compositeItems;
    }

    /**
     * Find or create child article from composite item data
     */
    private function findOrCreateChildArticle(array $itemData): ?RobawsArticleCache
    {
        if (!$itemData['robaws_article_id']) {
            // Create new surcharge article
            return RobawsArticleCache::create([
                'robaws_article_id' => 'SURCHARGE_' . uniqid(),
                'article_name' => $itemData['name'],
                'description' => $itemData['description'],
                'category' => 'miscellaneous',
                'unit_price' => $itemData['cost_price'],
                'currency' => 'EUR',
                'unit_type' => $itemData['unit_type'],
                'is_surcharge' => true,
                'is_active' => true,
                'last_synced_at' => now(),
            ]);
        }

        // Find existing article
        return RobawsArticleCache::where('robaws_article_id', $itemData['robaws_article_id'])->first();
    }

    /**
     * Extract shipping line from description
     */
    private function extractShippingLineFromDescription(string $description): ?string
    {
        $desc = strtoupper($description);
        
        // Known shipping lines
        $shippingLines = [
            'SALLAUM LINES' => ['SALLAUM'],
            'MSC' => ['MSC'],
            'MAERSK' => ['MAERSK'],
            'GRIMALDI' => ['GRIMALDI'],
            'CMA CGM' => ['CMA', 'CGM'],
        ];

        foreach ($shippingLines as $name => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($desc, $keyword)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Extract service type from description
     */
    private function extractServiceTypeFromDescription(string $description): ?string
    {
        $desc = strtoupper($description);
        
        if (str_contains($desc, 'RORO') && str_contains($desc, 'EXPORT')) {
            return 'RORO EXPORT';
        } elseif (str_contains($desc, 'RORO') && str_contains($desc, 'IMPORT')) {
            return 'RORO IMPORT';
        } elseif (str_contains($desc, 'FCL') && str_contains($desc, 'EXPORT')) {
            return 'FCL EXPORT';
        } elseif (str_contains($desc, 'FCL') && str_contains($desc, 'IMPORT')) {
            return 'FCL IMPORT';
        }

        return null;
    }

    /**
     * Fallback method to extract metadata from article when API is unavailable
     */
    private function extractMetadataFromArticle(RobawsArticleCache $article): array
    {
        $metadata = [];
        
        // Extract shipping line from description (using existing method)
        $metadata['shipping_line'] = $this->extractShippingLineFromDescription(
            $article->article_name
        );
        
        // Extract service type from description (using existing method)
        $metadata['service_type'] = $this->extractServiceTypeFromDescription(
            $article->article_name
        );
        
        // Extract POL terminal from description
        $metadata['pol_terminal'] = $this->extractPolTerminalFromDescription(
            $article->article_name
        );
        
        // Determine if parent based on description
        $metadata['is_parent_item'] = $this->isParentArticle($article->article_name);
        
        // Cannot extract dates from description, leave null
        $metadata['update_date'] = null;
        $metadata['validity_date'] = null;
        $metadata['article_info'] = 'Extracted from description (API unavailable)';
        
        return $metadata;
    }

    /**
     * Extract POL terminal from article description
     */
    private function extractPolTerminalFromDescription(string $description): ?string
    {
        $desc = strtoupper($description);
        
        // Common terminal patterns
        $patterns = [
            '/ST\s*(\d{3,4})/i',           // "ST 332", "ST 740"
            '/TERMINAL\s*(\d{3,4})/i',     // "Terminal 332"
            '/ANR\s*(\d{3,4})/i',          // "ANR 332"
            '/ZEE\s*(\d{3,4})/i',          // "ZEE 1234"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $desc, $matches)) {
                return 'ST ' . $matches[1];
            }
        }
        
        return null;
    }

}

