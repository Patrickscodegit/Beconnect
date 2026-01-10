<?php

namespace App\Services\Robaws;

use App\Models\RobawsArticleCache;
use App\Models\RobawsSyncLog;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Robaws\ArticleSyncEnhancementService;
use App\Services\Robaws\RobawsFieldMapper;
use App\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RobawsArticleProvider
{
    private const RATE_LIMIT_CACHE_KEY = 'robaws_rate_limit';

    public function __construct(
        private RobawsApiClient $robawsClient,
        private ArticleNameParser $parser,
        private ArticleSyncEnhancementService $enhancementService,
        private RobawsFieldMapper $fieldMapper,
        private \App\Services\Ports\PortResolutionService $portResolver
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
     * Parent articles are main seafreight services that can have child items (surcharges, fees)
     */
    private function isParentArticle(string $description): bool
    {
        $desc = strtolower($description);

        // Exclusion patterns - these are NOT parent items
        $exclusions = [
            'surcharge',
            'additional',
            'courrier',
            'courier',
            'admin',
            'customs',
            'waiver',
            'handling',
            'documentation',
            'certificate',
            'inspection',
            'storage',
            'demurrage',
            'detention'
        ];
        
        // Check for exclusions first
        foreach ($exclusions as $exclusion) {
            if (str_contains($desc, $exclusion)) {
                return false;
            }
        }

        // Positive indicators for parent articles
        $parentIndicators = [
            'seafreight',
            'ocean freight',
            'fcl',
            'lcl',
            'roro',
            'breakbulk',
            'bulk cargo',
            'container service',
            'shipping service'
        ];
        
        foreach ($parentIndicators as $indicator) {
            if (str_contains($desc, $indicator)) {
                return true;
            }
        }

        return false;
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
        $attempt = 0;
        $maxAttempts = 5;

        while ($attempt < $maxAttempts) {
            try {
                $this->checkRateLimit();

                Log::debug('Fetching article details from Robaws', [
                    'article_id' => $articleId,
                    'endpoint' => "/api/v2/articles/{$articleId}",
                    'attempt' => $attempt + 1,
                ]);

                // Use longer timeout for article details (includes many related items)
                $response = $this->robawsClient->getHttpClientForQuotation()
                    ->timeout(30) // Increased timeout for article details
                    ->connectTimeout(10)
                    ->get("/api/v2/articles/{$articleId}", [
                        'include' => 'extraFields,additionalItems,compositeItems,lineItems,children',
                    ]);

                $this->handleRateLimitResponse($response);

                if ($response->successful()) {
                    Log::debug('Successfully fetched article details', [
                        'article_id' => $articleId,
                        'attempt' => $attempt + 1,
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
            } catch (RateLimitException $e) {
                $attempt++;
                $retryAfter = $e->getRetryAfter() ?? 60;

                if ($attempt >= $maxAttempts) {
                    Log::error('Exceeded retry attempts due to Robaws rate limit', [
                        'article_id' => $articleId,
                        'attempts' => $attempt,
                        'retry_after' => $retryAfter,
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }

                Log::warning('Robaws rate limit hit while fetching article, retrying after delay', [
                    'article_id' => $articleId,
                    'attempt' => $attempt,
                    'retry_after' => $retryAfter,
                ]);

                $sleepFor = max(1, min($retryAfter + 5, 600));

                sleep($sleepFor);
                continue;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Handle timeout/connection errors specifically
                $attempt++;
                
                Log::warning('Robaws API connection timeout/error, retrying', [
                    'article_id' => $articleId,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= $maxAttempts) {
                    Log::error('Exceeded retry attempts due to connection errors', [
                        'article_id' => $articleId,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }

                // Exponential backoff for connection errors
                $sleepFor = min(pow(2, $attempt), 10); // Max 10 seconds
                sleep($sleepFor);
                continue;
            } catch (\Illuminate\Http\Client\RequestException $e) {
                // Handle other HTTP request errors
                $attempt++;
                
                Log::warning('Robaws API request error, retrying', [
                    'article_id' => $articleId,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                    'status_code' => $e->response?->status(),
                ]);

                if ($attempt >= $maxAttempts) {
                    Log::error('Exceeded retry attempts due to request errors', [
                        'article_id' => $articleId,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }

                // Short delay for request errors
                sleep(2);
                continue;
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

        return null;
    }

    /**
     * Sync article metadata from webhook payload (zero API calls)
     * Webhook payload contains full article data including extraFields
     * 
     * @param int|string $articleId The article cache ID or robaws_article_id
     * @param array $webhookData Full article data from webhook payload
     * @return array Synced metadata
     */
    public function syncArticleMetadataFromWebhook(int|string $articleId, array $webhookData): array
    {
        try {
            // Find article by ID (could be cache ID or robaws_article_id)
            $article = is_numeric($articleId) 
                ? RobawsArticleCache::find($articleId)
                : RobawsArticleCache::where('robaws_article_id', $articleId)->first();
            
            if (!$article) {
                // Article doesn't exist yet - process from webhook data first
                Log::info('Article not found in cache, processing from webhook data', [
                    'article_id' => $articleId,
                    'robaws_article_id' => $webhookData['id'] ?? null
                ]);
                
                // This will create the article from webhook data
                // The processArticle method will be called from RobawsArticlesSyncService
                throw new \Exception("Article not found in cache. Process webhook data first.");
            }

            // Webhook data contains full article data including extraFields
            // Parse metadata directly from webhook payload (no API call needed)
            $metadata = $this->parseArticleMetadata($webhookData);
            $source = 'webhook';

            // SUPPLEMENT: Always try to extract POL/POD from article name
            $nameExtraction = $this->extractMetadataFromArticleWithContext($article, $metadata);
            
            // Merge POL/POD from article name if API didn't provide them
            if (empty($metadata['pol_code']) && !empty($nameExtraction['pol_code'])) {
                $metadata['pol_code'] = $nameExtraction['pol_code'];
            }
            
            if (empty($metadata['pod_name']) && !empty($nameExtraction['pod_name'])) {
                $metadata['pod_name'] = $nameExtraction['pod_name'];
            }
            
            // Also supplement pol_terminal if not provided by API
            if (empty($metadata['pol_terminal']) && !empty($nameExtraction['pol_terminal'])) {
                $metadata['pol_terminal'] = $nameExtraction['pol_terminal'];
            }
            
            // IMPORTANT: Always use direction-aware applicable_services from name extraction
            if (!empty($nameExtraction['applicable_services'])) {
                $metadata['applicable_services'] = $nameExtraction['applicable_services'];
            }

            if (isset($metadata['is_parent_item']) && $metadata['is_parent_item']) {
                $metadata['is_parent_article'] = true;
            }
            
            // Extract enhanced fields for Smart Article Selection
            try {
                // Strategy: Use Robaws fields first, fallback to name parsing
                
                // 1. Commodity Type - Check multiple sources
                if (!empty($metadata['type'])) {
                    // Direct from Robaws TYPE field - most reliable
                    $metadata['commodity_type'] = $this->enhancementService->extractCommodityType(['type' => $metadata['type']]);
                } else {
                    // Fallback: Extract from article name
                    $articleData = [
                        'article_name' => $article->article_name,
                        'name' => $article->article_name,
                    ];
                    $metadata['commodity_type'] = $this->enhancementService->extractCommodityType($articleData);
                }
                
                // 2. POD Code - Check multiple sources
                if (!empty($metadata['pod'])) {
                    // Direct from Robaws POD field
                    $metadata['pod_code'] = $this->enhancementService->extractPodCode($metadata['pod']);
                } elseif (!empty($metadata['pod_name'])) {
                    // From pod_name field
                    $metadata['pod_code'] = $this->enhancementService->extractPodCode($metadata['pod_name']);
                } else {
                    // Fallback: Try to extract from article name
                    $metadata['pod_code'] = null;
                }
                
            } catch (\Exception $e) {
                Log::debug('Failed to extract enhanced fields during webhook metadata sync', [
                    'article_id' => $articleId,
                    'error' => $e->getMessage()
                ]);
                // Non-critical - continue without enhanced fields
            }
            
            // Ensure date fields are normalized before persisting
            $metadata['update_date'] = $this->normalizeRobawsDate($metadata['update_date'] ?? null, 'update_date');
            $metadata['validity_date'] = $this->normalizeRobawsDate($metadata['validity_date'] ?? null, 'validity_date');

            // Resolve port foreign keys using PortResolutionService
            // Resolve POL port
            if (!empty($metadata['pol_code'])) {
                $polPort = $this->portResolver->resolveOne($metadata['pol_code'], 'SEA');
                $metadata['pol_port_id'] = $polPort?->id;
            } else {
                $metadata['pol_port_id'] = null;
            }

            // Resolve POD port
            if (!empty($metadata['pod_code'])) {
                $podPort = $this->portResolver->resolveOne($metadata['pod_code'], 'SEA');
                $metadata['pod_port_id'] = $podPort?->id;
            } else {
                $metadata['pod_port_id'] = null;
            }

            // Set requires_manual_review flag
            $hasPolCode = !empty($metadata['pol_code']);
            $hasPodCode = !empty($metadata['pod_code']);
            $polResolved = !empty($metadata['pol_port_id']);
            $podResolved = !empty($metadata['pod_port_id']);

            if (($hasPolCode && !$polResolved) || ($hasPodCode && !$podResolved)) {
                $metadata['requires_manual_review'] = true;
            } elseif ((!$hasPolCode && !$hasPodCode) || ($polResolved && (!$hasPodCode || $podResolved))) {
                // Both resolved (or no codes to resolve) - clear flag
                $metadata['requires_manual_review'] = false;
            }

            // Update article with metadata (including pol_port_id, pod_port_id, requires_manual_review)
            $article->update($metadata);

            // Ensure parent flag stays in sync
            if (isset($metadata['is_parent_item']) && $metadata['is_parent_item']) {
                $article->is_parent_article = true;
            }

            // Sync composite items for parent articles (but don't make API calls - use webhook data)
            $shouldSyncChildren = $article->is_parent_article
                || (!empty($metadata['is_parent_article']))
                || (!empty($metadata['is_parent_item']));

            if ($shouldSyncChildren && !empty($webhookData['compositeItems'])) {
                // Use composite items from webhook data instead of API call
                $this->syncCompositeItemsFromWebhook($article->id, $webhookData);
            }

            Log::info('Article metadata synced from webhook', [
                'article_id' => $articleId,
                'source' => $source,
                'metadata_keys' => array_keys($metadata),
                'api_calls_made' => 0
            ]);

            return $metadata;

        } catch (\Exception $e) {
            Log::error('Failed to sync article metadata from webhook', [
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Sync composite items from webhook data (zero API calls)
     * 
     * @param int $parentArticleId The parent article cache ID
     * @param array $webhookData Full article data from webhook payload
     */
    public function syncCompositeItemsFromWebhook(int $parentArticleId, array $webhookData): void
    {
        try {
            $parent = RobawsArticleCache::find($parentArticleId);
            
            if (!$parent) {
                throw new \Exception("Parent article not found: {$parentArticleId}");
            }

            // Parse composite items from webhook data (no API call needed)
            $compositeItems = $this->parseCompositeItems($webhookData);
            
            if (empty($compositeItems)) {
                Log::info('No composite items found in webhook data for parent article', [
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

                        Log::info('Linked composite item to parent from webhook', [
                            'parent' => $parent->article_name,
                            'child' => $child->article_name
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to sync composite items from webhook', [
                'parent_article_id' => $parentArticleId,
                'error' => $e->getMessage()
            ]);

            // Don't throw - just log and continue
            // This allows the system to continue working even if some articles fail
        }
    }

    /**
     * Sync article metadata for a specific article
     * Now works from stored data (no API calls needed) since metadata is fetched during initial sync
     * Falls back to API call only if explicitly requested
     * 
     * @param int $articleId The article cache ID
     * @param bool $useApi Whether to attempt API call (default: false for speed)
     */
    public function syncArticleMetadata(int|string $articleId, bool $useApi = false): array
    {
        try {
            $article = RobawsArticleCache::find($articleId);
            
            if (!$article) {
                throw new \Exception("Article not found in cache: {$articleId}");
            }

            $hasCoreMetadata = !empty($article->shipping_line)
                && !empty($article->transport_mode)
                && !empty($article->pol_code)
                && !empty($article->pod_code);

            $shouldUseApi = $useApi
                || (bool) $article->is_parent_item
                || !$hasCoreMetadata;
            
            if (!$shouldUseApi) {
                // We already have the basics, only refresh derived values
                $metadata = $this->extractMetadataFromArticle($article);
                $source = 'stored';

                Log::debug('Using cached metadata snapshot', [
                    'article_id' => $articleId,
                    'article_name' => $article->article_name,
                ]);
            } else {
                // Fetch full article details from Robaws API when needed
                $details = $this->getArticleDetails($article->robaws_article_id);
                
                if ($details) {
                    // ✅ API success - parse from API response
                    $metadata = $this->parseArticleMetadata($details);
                    $source = 'api';
                    
                    // SUPPLEMENT: Always try to extract POL/POD from article name
                    $nameExtraction = $this->extractMetadataFromArticleWithContext($article, $metadata);
                    
                    // Merge POL/POD from article name if API didn't provide them
                    if (empty($metadata['pol_code']) && !empty($nameExtraction['pol_code'])) {
                        $metadata['pol_code'] = $nameExtraction['pol_code'];
                    }
                    
                    if (empty($metadata['pod_name']) && !empty($nameExtraction['pod_name'])) {
                        $metadata['pod_name'] = $nameExtraction['pod_name'];
                    }
                    
                    // Also supplement pol_terminal if not provided by API
                    if (empty($metadata['pol_terminal']) && !empty($nameExtraction['pol_terminal'])) {
                        $metadata['pol_terminal'] = $nameExtraction['pol_terminal'];
                    }
                    
                    // IMPORTANT: Always use direction-aware applicable_services from name extraction
                    if (!empty($nameExtraction['applicable_services'])) {
                        $metadata['applicable_services'] = $nameExtraction['applicable_services'];
                    }

                    if (isset($metadata['is_parent_item']) && $metadata['is_parent_item']) {
                        $metadata['is_parent_article'] = true;
                    }
                    
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
            }
            
            // Extract enhanced fields for Smart Article Selection
            try {
                // Strategy: Use Robaws fields first, fallback to name parsing
                
                // 1. Commodity Type - Check multiple sources
                if (!empty($metadata['type'])) {
                    // Direct from Robaws TYPE field - most reliable
                    $metadata['commodity_type'] = $this->enhancementService->extractCommodityType(['type' => $metadata['type']]);
                } else {
                    // Fallback: Extract from article name
                    $articleData = [
                        'article_name' => $article->article_name,
                        'name' => $article->article_name,
                    ];
                    $metadata['commodity_type'] = $this->enhancementService->extractCommodityType($articleData);
                }
                
                // 2. POD Code - Check multiple sources
                if (!empty($metadata['pod'])) {
                    // Direct from Robaws POD field
                    $metadata['pod_code'] = $this->enhancementService->extractPodCode($metadata['pod']);
                } elseif (!empty($metadata['pod_name'])) {
                    // From pod_name field
                    $metadata['pod_code'] = $this->enhancementService->extractPodCode($metadata['pod_name']);
                } else {
                    // Fallback: Try to extract from article name
                    $metadata['pod_code'] = null;
                }
                
            } catch (\Exception $e) {
                Log::debug('Failed to extract enhanced fields during metadata sync', [
                    'article_id' => $articleId,
                    'error' => $e->getMessage()
                ]);
                // Non-critical - continue without enhanced fields
            }
            
            // Ensure date fields are normalized before persisting
            $metadata['update_date'] = $this->normalizeRobawsDate($metadata['update_date'] ?? null, 'update_date');
            $metadata['validity_date'] = $this->normalizeRobawsDate($metadata['validity_date'] ?? null, 'validity_date');

            // Resolve port foreign keys using PortResolutionService
            // Resolve POL port
            if (!empty($metadata['pol_code'])) {
                $polPort = $this->portResolver->resolveOne($metadata['pol_code'], 'SEA');
                $metadata['pol_port_id'] = $polPort?->id;
            } else {
                $metadata['pol_port_id'] = null;
            }

            // Resolve POD port
            if (!empty($metadata['pod_code'])) {
                $podPort = $this->portResolver->resolveOne($metadata['pod_code'], 'SEA');
                $metadata['pod_port_id'] = $podPort?->id;
            } else {
                $metadata['pod_port_id'] = null;
            }

            // Set requires_manual_review flag
            $hasPolCode = !empty($metadata['pol_code']);
            $hasPodCode = !empty($metadata['pod_code']);
            $polResolved = !empty($metadata['pol_port_id']);
            $podResolved = !empty($metadata['pod_port_id']);

            if (($hasPolCode && !$polResolved) || ($hasPodCode && !$podResolved)) {
                $metadata['requires_manual_review'] = true;
            } elseif ((!$hasPolCode && !$hasPodCode) || ($polResolved && (!$hasPodCode || $podResolved))) {
                // Both resolved (or no codes to resolve) - clear flag
                $metadata['requires_manual_review'] = false;
            }

            // Update article with metadata (including pol_port_id, pod_port_id, requires_manual_review)
            $article->update($metadata);

            // Ensure parent flag stays in sync
            if (isset($metadata['is_parent_item']) && $metadata['is_parent_item']) {
                $article->is_parent_article = true;
            }

            // Sync composite items for parent articles
            $shouldSyncChildren = $article->is_parent_article
                || (!empty($metadata['is_parent_article']))
                || (!empty($metadata['is_parent_item']));

            if ($shouldSyncChildren) {
                $this->syncCompositeItems($article->id);
            }

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
     * Fields: shipping_line, transport_mode, pol_terminal, parent_item
     */
    private function parseArticleInfo(array $rawData): array
    {
        $info = [];

        // Extract from extraFields if available using flexible field mapping
        $extraFields = $rawData['extraFields'] ?? [];
        
        // Use RobawsFieldMapper for robust field extraction
        $shippingLine = $this->fieldMapper->getStringValue($extraFields, 'shipping_line');
        if ($shippingLine !== null) {
            $info['shipping_line'] = $shippingLine;
            
            // Try to find carrier using CarrierLookupService
            $carrierLookup = app(\App\Services\Carrier\CarrierLookupService::class);
            $carrier = $carrierLookup->findByCodeOrName($shippingLine);
            if ($carrier) {
                $info['shipping_carrier_id'] = $carrier->id;
            }
            
            // Map shipping line to carrier code for applicable_carriers (backward compatibility)
            $carrierCode = $this->mapShippingLineToCarrierCode($shippingLine);
            if ($carrierCode) {
                $info['applicable_carriers'] = [$carrierCode];
            }
        }
        
        $transportMode = $this->fieldMapper->getStringValue($extraFields, 'transport_mode');
        if ($transportMode !== null) {
            $normalizedMode = $this->normalizeTransportMode($transportMode);
            if ($normalizedMode) {
                $info['transport_mode'] = $normalizedMode;
                $services = $this->mapTransportModeToApplicableServices(
                    $normalizedMode,
                    $rawData['description'] ?? '',
                    $rawData['code'] ?? null
                );
                $info['applicable_services'] = $services;
                $info['service_type'] = $this->selectPrimaryService($services)
                    ?? $this->normalizeServiceTypeFromMode($normalizedMode);
            }
        }

        $articleType = $this->fieldMapper->getStringValue($extraFields, 'article_type');
        if ($articleType !== null) {
            $info['article_type'] = $this->normalizeArticleType($articleType);
        }

        $costSide = $this->fieldMapper->getStringValue($extraFields, 'cost_side');
        if ($costSide !== null) {
            $info['cost_side'] = $this->normalizeCostSide($costSide, $info['article_type'] ?? null);
        }

        $polTerminal = $this->fieldMapper->getStringValue($extraFields, 'pol_terminal');
        if ($polTerminal !== null) {
            $info['pol_terminal'] = $polTerminal;
        }

        $polCode = $this->fieldMapper->getStringValue($extraFields, 'pol_code');
        if ($polCode !== null) {
            $info['pol_code'] = $this->normalizePortCode($polCode);
        }

        $podCode = $this->fieldMapper->getStringValue($extraFields, 'pod_code');
        if ($podCode !== null) {
            $info['pod_code'] = $this->normalizePortCode($podCode);
        }
        
        $parentItem = $this->fieldMapper->getBooleanValue($extraFields, 'parent_item');
        if ($parentItem !== null) {
            $info['is_parent_item'] = $parentItem;
        }

        $isMandatory = $this->fieldMapper->getBooleanValue($extraFields, 'is_mandatory');
        if ($isMandatory !== null) {
            $info['is_mandatory'] = $isMandatory;
        }

        $mandatoryCondition = $this->fieldMapper->getStringValue($extraFields, 'mandatory_condition');
        if ($mandatoryCondition !== null) {
            $info['mandatory_condition'] = trim($mandatoryCondition);
        }

        $notes = $this->fieldMapper->getStringValue($extraFields, 'notes');
        if ($notes !== null) {
            $info['notes'] = trim($notes);
        }
        
        // Handle other fields that might exist
        foreach ($extraFields as $fieldName => $field) {
            // Handle multiple value types from Robaws API
            $value = $field['stringValue'] 
                   ?? $field['booleanValue'] 
                   ?? $field['numberValue']
                   ?? $field['value'] 
                   ?? null;

            switch ($fieldName) {
                case 'POL':
                    $info['pol'] = $value;
                    break;
                case 'POD':
                    $info['pod'] = $value;
                    break;
                case 'TYPE':
                case 'COMMODITY TYPE':
                case 'COMMODITY_TYPE':
                    $info['type'] = $value;
                    break;
            }
        }

        // Fallback: try to extract from description or metadata
        if (empty($info['shipping_line'])) {
            $extractedLine = $this->extractShippingLineFromDescription($rawData['description'] ?? $rawData['name'] ?? '');
            if ($extractedLine) {
                $info['shipping_line'] = $extractedLine;
                
                // Try to find carrier for extracted shipping line
                if (empty($info['shipping_carrier_id'])) {
                    $carrierLookup = app(\App\Services\Carrier\CarrierLookupService::class);
                    $carrier = $carrierLookup->findByCodeOrName($extractedLine);
                    if ($carrier) {
                        $info['shipping_carrier_id'] = $carrier->id;
                    }
                }
            }
        }

        if (empty($info['transport_mode'])) {
            $info['transport_mode'] = $this->extractTransportModeFromDescription($rawData['description'] ?? $rawData['name'] ?? '');
            if (!empty($info['transport_mode'])) {
                $services = $this->mapTransportModeToApplicableServices(
                    $info['transport_mode'],
                    $rawData['description'] ?? '',
                    $rawData['code'] ?? null
                );
                $info['applicable_services'] = $services;
                $info['service_type'] = $this->selectPrimaryService($services)
                    ?? $this->normalizeServiceTypeFromMode($info['transport_mode']);
            }
        }

        if (empty($info['cost_side']) && !empty($info['article_type'])) {
            $info['cost_side'] = $this->normalizeCostSide(null, $info['article_type']);
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
        // Robaws API returns extraFields as an object/dictionary with field names as keys
        $extraFields = $rawData['extraFields'] ?? [];
        
        foreach ($extraFields as $fieldName => $field) {
            $value = $field['stringValue'] ?? $field['value'] ?? $field['dateValue'] ?? null;

            switch ($fieldName) {
                case 'ARTICLE_INFO':
                case 'INFO':
                    $info['article_info'] = $value;
                    break;
                case 'UPDATE DATE':
                case 'UPDATE_DATE':
                    $info['update_date'] = $this->normalizeRobawsDate($value, 'update_date');
                    break;
                case 'VALIDITY DATE':
                case 'VALIDITY_DATE':
                    $info['validity_date'] = $this->normalizeRobawsDate($value, 'validity_date');
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
        $items = $rawData['compositeItems']
            ?? $rawData['children']
            ?? $rawData['lineItems']
            ?? $rawData['additionalItems']
            ?? [];

        foreach ($items as $item) {
            $linkedArticle = $item['article'] ?? null;

            $compositeItems[] = [
                'robaws_article_id' => $item['articleId']
                    ?? $item['id']
                    ?? ($linkedArticle['id'] ?? null),
                'name' => $item['description']
                    ?? $item['name']
                    ?? ($linkedArticle['name'] ?? ''),
                'cost_type' => $item['costType']
                    ?? $item['cost_type']
                    ?? ($linkedArticle['costType'] ?? 'Material'),
                'description' => $item['description']
                    ?? ($linkedArticle['description'] ?? ''),
                'unit_type' => $item['unitType']
                    ?? $item['unit_type']
                    ?? ($linkedArticle['unitType'] ?? ''),
                'quantity' => $item['quantity'] ?? 1.00,
                'cost_price' => $item['costPrice']
                    ?? $item['unitPrice']
                    ?? ($linkedArticle['costPrice'] ?? null),
                'is_required' => $item['isRequired']
                    ?? $item['required']
                    ?? true,
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
     * Map shipping line name to carrier code
     */
    private function mapShippingLineToCarrierCode(string $shippingLine): ?string
    {
        $normalized = strtoupper(trim($shippingLine));
        
        // Map shipping line names to carrier codes
        $carrierMapping = [
            'SALLAUM LINES' => 'SLAL',
            'SALLAUM' => 'SLAL',
            'MSC' => 'MSC',
            'MAERSK' => 'MAEU',
            'GRIMALDI' => 'GRIM',
            'CMA CGM' => 'CMDU',
            'HAPAG-LLOYD' => 'HLCU',
            'EVERGREEN' => 'EGLV',
        ];

        return $carrierMapping[$normalized] ?? null;
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
     * Extract transport mode from description
     */
    private function extractTransportModeFromDescription(string $description): ?string
    {
        $desc = Str::upper($description);

        if (str_contains($desc, 'RORO') || str_contains($desc, 'ROLL ON ROLL OFF')) {
            return 'RORO';
        }

        if (str_contains($desc, 'FCL CONSOL')) {
            return 'FCL CONSOL';
        }

        if (str_contains($desc, 'FCL')) {
            return 'FCL';
        }

        if (str_contains($desc, 'LCL')) {
            return 'LCL';
        }

        if (str_contains($desc, 'BREAK BULK') || str_contains($desc, 'BREAKBULK') || str_contains($desc, 'BB')) {
            return 'BB';
        }

        if (str_contains($desc, 'AIR')) {
            return 'AIRFREIGHT';
        }

        if (str_contains($desc, 'ROAD') || str_contains($desc, 'TRUCK')) {
            return 'ROAD TRANSPORT';
        }

        if (str_contains($desc, 'CUSTOMS')) {
            return 'CUSTOMS';
        }

        if (str_contains($desc, 'PORT FORWARDING')) {
            return 'PORT FORWARDING';
        }

        if (str_contains($desc, 'HOMOLOGATION')) {
            return 'HOMOLOGATION';
        }

        if (str_contains($desc, 'VEHICLE PURCHASE')) {
            return 'VEHICLE PURCHASE';
        }

        if (str_contains($desc, 'WAREHOUSE') || str_contains($desc, 'STORAGE')) {
            return 'WAREHOUSE';
        }

        if (str_contains($desc, 'SEAFREIGHT')) {
            return 'SEAFREIGHT';
        }

        return 'OTHER';
    }

    /**
     * Extract metadata from article name with existing context (for supplementing API data)
     */
    private function extractMetadataFromArticleWithContext(RobawsArticleCache $article, array $existingMetadata): array
    {
        $metadata = [];
        
        // Use existing transport mode if available, otherwise extract
        $metadata['transport_mode'] = $existingMetadata['transport_mode']
            ?? $this->extractTransportModeFromDescription($article->article_name);
        
        // Extract POL and POD using centralized parser
        $polPort = null;
        $podPort = null;
        
        $polData = $this->parser->extractPOL($article->article_name);
        if ($polData) {
            $metadata['pol_code'] = $this->normalizePortCode($polData['code'] ?? $polData['formatted'] ?? null);
            if ($polData['terminal']) {
                $metadata['pol_terminal'] = $polData['terminal'];
            }
            // Get Port model for direction detection
            if ($polData['code']) {
                $polPort = $this->portResolver->resolveOne($polData['code']);
                // Fallback to old method if resolver fails
                if (!$polPort) {
                    $polPort = \App\Models\Port::where('code', $polData['code'])->first();
                }
            }
        }
        
        $podData = $this->parser->extractPOD($article->article_name);
        if ($podData) {
            $metadata['pod_name'] = $podData['formatted'];
            $metadata['pod_code'] = $this->normalizePortCode($podData['code'] ?? $podData['formatted'] ?? null);
            // Get Port model for direction detection
            if ($podData['name']) {
                $podPort = $this->portResolver->resolveOne($podData['name']);
                // Fallback to old method if resolver fails
                if (!$podPort) {
                    $podPort = \App\Models\Port::where('name', 'LIKE', '%' . $podData['name'] . '%')->first();
                }
            }
        }
        
        // Smart applicable_services based on POL/POD direction
        if ($polPort && $podPort && $metadata['transport_mode']) {
            $services = $this->getApplicableServicesFromDirection(
                $polPort,
                $podPort,
                $metadata['transport_mode']
            );
            $metadata['applicable_services'] = $services;
            $metadata['service_type'] = $this->selectPrimaryService($services);
        } elseif ($metadata['transport_mode']) {
            $services = $this->mapTransportModeToApplicableServices(
                $metadata['transport_mode'],
                $article->article_name
            );
            $metadata['applicable_services'] = $services;
            $metadata['service_type'] = $this->selectPrimaryService($services)
                ?? $this->normalizeServiceTypeFromMode($metadata['transport_mode']);
        }
        
        return $metadata;
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
        
        // Extract transport mode from description (using existing method)
        $metadata['transport_mode'] = $this->extractTransportModeFromDescription(
            $article->article_name
        );
        
        // Extract POL and POD using centralized parser
        $polPort = null;
        $podPort = null;
        
        $polData = $this->parser->extractPOL($article->article_name);
        if ($polData) {
            $metadata['pol_code'] = $this->normalizePortCode($polData['code'] ?? $polData['formatted'] ?? null);
            if ($polData['terminal']) {
                $metadata['pol_terminal'] = $polData['terminal'];
            }
            // Get Port model for direction detection
            if ($polData['code']) {
                $polPort = $this->portResolver->resolveOne($polData['code']);
                // Fallback to old method if resolver fails
                if (!$polPort) {
                    $polPort = \App\Models\Port::where('code', $polData['code'])->first();
                }
            }
        } else {
            // Fallback to old extraction method if no parentheses code found
            $metadata['pol_terminal'] = $this->extractPolTerminalFromDescription(
                $article->article_name
            );
        }
        
        $podData = $this->parser->extractPOD($article->article_name);
        if ($podData) {
            $metadata['pod_name'] = $podData['formatted'];
            $metadata['pod_code'] = $this->normalizePortCode($podData['code'] ?? $podData['formatted'] ?? null);
            // Get Port model for direction detection
            if ($podData['name']) {
                $podPort = $this->portResolver->resolveOne($podData['name']);
                // Fallback to old method if resolver fails
                if (!$podPort) {
                    $podPort = \App\Models\Port::where('name', 'LIKE', '%' . $podData['name'] . '%')->first();
                }
            }
        }
        
        // NEW: Smart applicable_services based on POL/POD direction
        if ($polPort && $podPort && $metadata['transport_mode']) {
            $services = $this->getApplicableServicesFromDirection(
                $polPort,
                $podPort,
                $metadata['transport_mode']
            );
            $metadata['applicable_services'] = $services;
            $metadata['service_type'] = $this->selectPrimaryService($services);
        } elseif ($metadata['transport_mode']) {
            // Fallback: use transport mode only
            $services = $this->mapTransportModeToApplicableServices(
                $metadata['transport_mode'],
                $article->article_name
            );
            $metadata['applicable_services'] = $services;
            $metadata['service_type'] = $this->selectPrimaryService($services)
                ?? $this->normalizeServiceTypeFromMode($metadata['transport_mode']);
        }
        
        // Cannot determine parent status from description alone
        // Only the Robaws API "PARENT ITEM" checkbox is authoritative
        $metadata['is_parent_item'] = null;
        
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
    
    /**
     * Determine applicable services based on POL/POD direction
     * Uses port flags (is_european_origin, is_african_destination) to detect EXPORT vs IMPORT
     */
    private function getApplicableServicesFromDirection(
        \App\Models\Port $polPort,
        \App\Models\Port $podPort,
        string $transportMode
    ): array {
        // Detect direction
        $isExport = $polPort->is_european_origin && $podPort->is_african_destination;
        $isImport = $podPort->is_european_origin && $polPort->is_african_destination;
        
        $baseService = $this->mapTransportModeToBaseService($transportMode);
        
        $services = [];
        
        if ($isExport) {
            // Europe → Africa = EXPORT only
            $services[] = $baseService . '_EXPORT';
            $services = array_merge($services, $this->getConsolVariants($baseService, 'EXPORT'));
        } elseif ($isImport) {
            // Africa → Europe = IMPORT only
            $services[] = $baseService . '_IMPORT';
            $services = array_merge($services, $this->getConsolVariants($baseService, 'IMPORT'));
        } else {
            // Unknown direction: include both
            $services[] = $baseService . '_EXPORT';
            $services[] = $baseService . '_IMPORT';
            $services = array_merge($services, $this->getConsolVariants($baseService, 'EXPORT'));
            $services = array_merge($services, $this->getConsolVariants($baseService, 'IMPORT'));
        }
        
        return array_values(array_unique($services));
    }
    
    /**
     * Get consolidation variants for FCL transport mode
     */
    private function getConsolVariants(string $baseService, string $direction): array
    {
        if ($baseService !== 'FCL') {
            return [];
        }

        return match ($direction) {
            'EXPORT' => ['FCL_EXPORT_CONSOL'],
            'IMPORT' => ['FCL_IMPORT_CONSOL'],
            default => [],
        };
    }
    
    /**
     * Fallback: populate applicable_services from transport mode only (no direction info)
     */
    private function getApplicableServicesFromType(string $serviceType): array
    {
        // Legacy fallback to support previous behaviour; now defers to transport mode mapping
        return $this->mapTransportModeToApplicableServices($serviceType);
    }

    /**
     * Normalize transport mode string to canonical values
     */
    private function normalizeTransportMode(?string $mode): ?string
    {
        if (!$mode) {
            return null;
        }

        $normalized = Str::upper(trim($mode));

        $candidate = match (true) {
            str_contains($normalized, 'RORO') => 'RORO',
            str_contains($normalized, 'FCL CONS') => 'FCL CONSOL',
            str_contains($normalized, 'FCL') => 'FCL',
            str_contains($normalized, 'LCL') => 'LCL',
            str_contains($normalized, 'BREAK') || $normalized === 'BB' => 'BB',
            str_contains($normalized, 'AIR') => 'AIRFREIGHT',
            str_contains($normalized, 'ROAD') || str_contains($normalized, 'TRUCK') => 'ROAD TRANSPORT',
            str_contains($normalized, 'CUSTOMS') => 'CUSTOMS',
            str_contains($normalized, 'PORT FORWARD') => 'PORT FORWARDING',
            str_contains($normalized, 'HOMOLOGATION') => 'HOMOLOGATION',
            str_contains($normalized, 'VEHICLE') => 'VEHICLE PURCHASE',
            str_contains($normalized, 'WAREHOUSE') || str_contains($normalized, 'STORAGE') => 'WAREHOUSE',
            str_contains($normalized, 'SEA') => 'SEAFREIGHT',
            default => $normalized,
        };

        $allowed = [
            'RORO',
            'FCL',
            'FCL CONSOL',
            'LCL',
            'BB',
            'AIRFREIGHT',
            'ROAD TRANSPORT',
            'CUSTOMS',
            'PORT FORWARDING',
            'VEHICLE PURCHASE',
            'HOMOLOGATION',
            'WAREHOUSE',
            'SEAFREIGHT',
            'OTHER',
        ];

        return in_array($candidate, $allowed, true) ? $candidate : 'OTHER';
    }

    /**
     * Map transport mode to base service keyword (used for applicable services)
     */
    private function mapTransportModeToBaseService(string $transportMode): string
    {
        return match (strtoupper(trim($transportMode))) {
            'RORO' => 'RORO',
            'FCL', 'FCL CONSOL' => 'FCL',
            'LCL' => 'LCL',
            'BB', 'BREAK BULK', 'BREAKBULK' => 'BB',
            'AIRFREIGHT', 'AIR' => 'AIR',
            default => strtoupper(trim($transportMode)),
        };
    }

    /**
     * Map transport mode to default applicable services (no direction info)
     */
    private function mapTransportModeToApplicableServices(string $transportMode, string $description = ''): array
    {
        $mode = strtoupper(trim($transportMode));

        return match ($mode) {
            'RORO' => ['RORO_EXPORT', 'RORO_IMPORT'],
            'FCL CONSOL' => ['FCL_EXPORT_CONSOL', 'FCL_IMPORT_CONSOL'],
            'FCL' => ['FCL_EXPORT', 'FCL_IMPORT'],
            'LCL' => ['LCL_EXPORT', 'LCL_IMPORT'],
            'BB', 'BREAK BULK', 'BREAKBULK' => ['BB_EXPORT', 'BB_IMPORT'],
            'AIRFREIGHT', 'AIR' => ['AIR_EXPORT', 'AIR_IMPORT'],
            default => [$mode ?: 'GENERAL'],
        };
    }

    /**
     * Normalize article type to canonical casing.
     */
    private function normalizeArticleType(?string $type): ?string
    {
        if (!$type) {
            return null;
        }

        $upper = Str::upper(trim($type));
        $mapping = [
            'LOCAL CHARGES POL' => 'LOCAL CHARGES POL',
            'LOCAL CHARGES POD' => 'LOCAL CHARGES POD',
            'SEAFREIGHT SURCHARGES' => 'SEAFREIGHT SURCHARGES',
            'SEAFREIGHT' => 'SEAFREIGHT',
            'AIRFREIGHT' => 'AIRFREIGHT',
            'ROAD TRANSPORT' => 'ROAD TRANSPORT',
            'WAREHOUSE' => 'WAREHOUSE',
            'ADMINISTRATIVE' => 'ADMINISTRATIVE',
            'INSPECTION SURCHARGES' => 'INSPECTION SURCHARGES',
        ];

        if (isset($mapping[$upper])) {
            return $mapping[$upper];
        }

        return Str::title(Str::lower($upper));
    }

    /**
     * Normalize cost side using explicit value or inferred from article type.
     */
    private function normalizeCostSide(?string $value, ?string $articleType): ?string
    {
        if ($value) {
            $upper = Str::upper(trim($value));
            $candidates = [
                'POL', 'POD', 'SEA', 'AIR', 'INLAND', 'ADMIN', 'WAREHOUSE',
            ];

            foreach ($candidates as $candidate) {
                if (str_contains($upper, $candidate)) {
                    return $candidate;
                }
            }

            return $upper;
        }

        if ($articleType) {
            $upperType = Str::upper(trim($articleType));

            return match (true) {
                str_contains($upperType, 'LOCAL CHARGES POL') => 'POL',
                str_contains($upperType, 'LOCAL CHARGES POD') => 'POD',
                str_contains($upperType, 'SEAFREIGHT') => 'SEA',
                str_contains($upperType, 'AIR') => 'AIR',
                str_contains($upperType, 'ROAD') => 'INLAND',
                str_contains($upperType, 'WAREHOUSE') => 'WAREHOUSE',
                str_contains($upperType, 'ADMIN') => 'ADMIN',
                default => null,
            };
        }

        return null;
    }

    /**
     * Normalize Robaws metadata date fields, returning Y-m-d or null.
     */
    private function normalizeRobawsDate(mixed $value, string $fieldName): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $sentinels = [
            '-0001-11-30',
            '-0001-11-30 00:00:00',
            '0000-00-00',
            '0000-00-00 00:00:00',
        ];

        if (in_array($raw, $sentinels, true)) {
            Log::debug('Robaws metadata date ignored (sentinel)', [
                'field' => $fieldName,
                'raw_value' => $raw,
            ]);

            return null;
        }

        try {
            $date = \Carbon\Carbon::parse($raw);
        } catch (\Throwable $e) {
            Log::debug('Failed to parse Robaws metadata date', [
                'field' => $fieldName,
                'raw_value' => $raw,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($date->year < 1900) {
            Log::debug('Robaws metadata date rejected (year < 1900)', [
                'field' => $fieldName,
                'raw_value' => $raw,
            ]);

            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * Normalize port code using PortResolutionService
     * Falls back to old behavior if resolution fails (backward compatibility)
     * 
     * @param string|null $raw
     * @return string|null
     */
    private function normalizePortCode(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }

        // Try PortResolutionService first
        try {
            $code = $this->portResolver->normalizeCode($raw);
            if ($code) {
                return $code;
            }
        } catch (\Exception $e) {
            // Log for debugging but continue with fallback
            \Log::debug('PortResolutionService failed for port code normalization', [
                'input' => $raw,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback to old behavior (backward compatibility)
        $clean = trim($raw);

        // If the string contains parentheses (e.g. "Antwerp (ANR), Belgium"),
        // extract the content inside the first pair as the code.
        if (preg_match('/\(([A-Z0-9]{2,5})\)/i', $clean, $match)) {
            return strtoupper($match[1]);
        }

        // Otherwise take the first token (split on whitespace/comma) and trim.
        $token = preg_split('/[\s,]+/', $clean)[0] ?? $clean;

        return strtoupper(substr($token, 0, 5));
    }

    /**
     * Select primary service string from array.
     */
    private function selectPrimaryService(?array $services): ?string
    {
        if (empty($services)) {
            return null;
        }

        $service = reset($services);
        return $service ? strtoupper($service) : null;
    }

    /**
     * Normalize legacy service type from transport mode.
     */
    private function normalizeServiceTypeFromMode(?string $transportMode): ?string
    {
        if (!$transportMode) {
            return null;
        }

        return strtoupper(str_replace(' ', '_', $transportMode));
    }

}

