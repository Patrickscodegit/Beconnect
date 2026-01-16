<?php

namespace App\Services\Quotation;

use App\Models\RobawsArticleCache;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Robaws\RobawsArticleProvider;
use App\Services\Robaws\ArticleNameParser;
use App\Services\Robaws\ArticleSyncEnhancementService;
use App\Services\Robaws\RobawsFieldMapper;
use App\Services\Robaws\ArticleTransportModeResolver;
use App\Services\Ports\PortResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RobawsArticlesSyncService
{
    protected RobawsApiClient $apiClient;
    protected ArticleNameParser $parser;
    protected RobawsArticleProvider $articleProvider;
    protected ArticleSyncEnhancementService $enhancementService;
    protected RobawsFieldMapper $fieldMapper;
    protected ArticleTransportModeResolver $transportModeResolver;
    protected PortResolutionService $portResolver;

    public function __construct(
        RobawsApiClient $apiClient, 
        ArticleNameParser $parser,
        RobawsArticleProvider $articleProvider,
        ArticleSyncEnhancementService $enhancementService,
        RobawsFieldMapper $fieldMapper,
        ArticleTransportModeResolver $transportModeResolver,
        PortResolutionService $portResolver
    )
    {
        $this->apiClient = $apiClient;
        $this->parser = $parser;
        $this->articleProvider = $articleProvider;
        $this->enhancementService = $enhancementService;
        $this->fieldMapper = $fieldMapper;
        $this->transportModeResolver = $transportModeResolver;
        $this->portResolver = $portResolver;
    }

    /**
     * Sync articles directly from Robaws Articles API
     */
    public function sync(): array
    {
        Log::info('Starting direct articles API sync');

        $synced = 0;
        $errors = 0;
        $totalArticles = 0;

        $this->apiClient->forEachArticlePage(function (array $articles, int $page, int $totalItems) use (&$synced, &$errors, &$totalArticles) {
            $totalArticles = $totalItems;
            foreach ($articles as $articleData) {
                try {
                    // Only fetch full details if extraFields not in list API response
                    // List API may not include extraFields, so we check first
                    $needsFullDetails = empty($articleData['extraFields']);
                    $this->processArticle($articleData, fetchFullDetails: $needsFullDetails);
                    $synced++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::warning('Failed to process article', [
                        'article_id' => $articleData['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }
            return true;
        });
        
        Log::info('Direct articles API sync completed', [
            'total_articles' => $totalArticles,
            'synced' => $synced,
            'errors' => $errors
        ]);
        
        return [
            'success' => true,
            'total' => $totalArticles,
            'synced' => $synced,
            'errors' => $errors
        ];
    }
    
    /**
     * Incremental sync - only fetch articles changed since last sync
     * This is the recommended approach for nightly scheduled syncs
     */
    public function syncIncremental(): array
    {
        Log::info('Starting incremental articles sync');
        
        // Get the last sync timestamp
        $lastSyncTimestamp = RobawsArticleCache::max('last_modified_at') ?? RobawsArticleCache::max('last_synced_at');
        
        if (!$lastSyncTimestamp) {
            Log::info('No previous sync found, running full sync');
            return $this->sync();
        }
        
        $lastSync = \Carbon\Carbon::parse($lastSyncTimestamp);
        $lastSyncFormatted = $lastSync->toIso8601String();
        
        Log::info('Fetching articles modified since', ['last_sync' => $lastSyncFormatted]);
        
        // Fetch articles modified since last sync
        $result = $this->apiClient->getArticles([
            'lastModified' => $lastSyncFormatted,
            'page' => 0,
            'size' => 100
        ]);
        
        if (!$result['success']) {
            Log::error('Incremental sync failed', ['error' => $result['error'] ?? 'Unknown']);
            throw new \RuntimeException('Failed to fetch modified articles from Robaws API');
        }
        
        $data = $result['data'];
        $articles = $data['items'] ?? [];
        $synced = 0;
        $errors = 0;
        
        // Handle pagination if there are more results
        $totalPages = $data['totalPages'] ?? 1;
        for ($page = 1; $page < $totalPages; $page++) {
            $pageResult = $this->apiClient->getArticles([
                'lastModified' => $lastSyncFormatted,
                'page' => $page,
                'size' => 100
            ]);
            
            if ($pageResult['success']) {
                $articles = array_merge($articles, $pageResult['data']['items'] ?? []);
            }
        }
        
        Log::info('Found modified articles', ['count' => count($articles)]);
        
        // Process each modified article (minimal API calls - use existing data when available)
        foreach ($articles as $articleData) {
            try {
                // Only fetch full details if extraFields not in incremental API response
                // Incremental API may include extraFields for changed articles
                $needsFullDetails = empty($articleData['extraFields']);
                $this->processArticle($articleData, fetchFullDetails: $needsFullDetails);
                
                // Extract metadata from stored data (no API call - webhooks handle real-time updates)
                // If article was just updated, it may have extraFields from the incremental API
                $article = \App\Models\RobawsArticleCache::where('robaws_article_id', $articleData['id'])->first();
                if ($article) {
                    $this->articleProvider->syncArticleMetadata(
                        $article->id,
                        useApi: false  // ✅ No API call - use stored data
                    );
                }
                
                $synced++;
                
                if ($synced % 10 === 0) {
                    Log::info('Incremental sync progress', [
                        'processed' => $synced,
                        'total' => count($articles),
                        'note' => 'Fast sync - minimal API calls (webhooks handle real-time)',
                        'api_calls_made' => $needsFullDetails ? 1 : 0
                    ]);
                }
            } catch (\RuntimeException $e) {
                // Handle daily quota exceeded
                if (str_contains($e->getMessage(), 'Daily API quota')) {
                    Log::critical('Sync stopped: Daily API quota exhausted', [
                        'synced' => $synced,
                        'remaining' => count($articles) - $synced
                    ]);
                    break; // Stop sync to preserve quota
                }
                // Re-throw other runtime exceptions
                throw $e;
            } catch (\Exception $e) {
                $errors++;
                Log::warning('Failed to process modified article', [
                    'article_id' => $articleData['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Incremental sync completed', [
            'total_modified' => count($articles),
            'synced' => $synced,
            'errors' => $errors
        ]);
        
        return [
            'success' => true,
            'total' => count($articles),
            'synced' => $synced,
            'errors' => $errors,
            'last_sync' => $lastSyncFormatted
        ];
    }

    /**
     * Process article from webhook event (zero API calls - uses webhook data directly)
     * Webhook payload contains full article data including extraFields, so no API calls needed
     */
    public function processArticleFromWebhook(array $articleData, string $event): void
    {
        $startTime = microtime(true);
        
        Log::info('Processing webhook event', [
            'event' => $event,
            'article_id' => $articleData['id'] ?? null,
            'article_name' => $articleData['name'] ?? null,
            'has_extraFields' => !empty($articleData['extraFields'])
        ]);
        
        // Webhook includes full article data with extraFields - no API call needed!
        // Use fetchFullDetails: false since webhook data already has everything
        $this->processArticle($articleData, fetchFullDetails: false);
        
        // Extract metadata directly from webhook payload (zero API calls)
        if (isset($articleData['id'])) {
            try {
                // Find article by robaws_article_id to get cache ID
                $article = \App\Models\RobawsArticleCache::where('robaws_article_id', $articleData['id'])->first();
                
                if ($article) {
                    // Use webhook data directly - no API call
                    $this->articleProvider->syncArticleMetadataFromWebhook(
                        $article->id,
                        $articleData
                    );
                } else {
                    // Article not in cache yet - it was just created by processArticle
                    // Find it again after processArticle creates it
                    $article = \App\Models\RobawsArticleCache::where('robaws_article_id', $articleData['id'])->first();
                    
                    if ($article) {
                        $this->articleProvider->syncArticleMetadataFromWebhook(
                            $article->id,
                            $articleData
                        );
                    } else {
                        Log::warning('Article not found in cache after processing webhook', [
                            'article_id' => $articleData['id']
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to sync metadata from webhook', [
                    'article_id' => $articleData['id'],
                    'error' => $e->getMessage()
                ]);
                // Don't throw - webhook processing should continue even if metadata sync fails
            }
        }
        
        $processingTime = (microtime(true) - $startTime) * 1000;
        
        Log::info('Webhook event processed successfully (zero API calls)', [
            'event' => $event,
            'article_id' => $articleData['id'] ?? null,
            'processing_time_ms' => round($processingTime, 2),
            'api_calls_made' => 0
        ]);
    }

    /**
     * Process and store article from Robaws API
     * 
     * @param array $article Article data from Robaws API
     * @param bool $fetchFullDetails Whether to fetch full details with extraFields (for incremental sync)
     */
    protected function processArticle(array $article, bool $fetchFullDetails = false): void
    {
        // Map Robaws API article fields to our cache structure
        $articleName = $article['name'] ?? $article['description'] ?? 'Unnamed Article';
        
        $isParent = $this->extractParentItemFromArticle($article);
        
        $data = [
            'robaws_article_id' => $article['id'],
            'article_code' => $article['code'] ?? $article['articleNumber'] ?? $article['id'],
            'article_name' => $articleName,
            'description' => $article['description'] ?? $article['notes'] ?? null,
            'category' => $article['category'] ?? 'general',
            'unit_price' => $article['salePrice'] ?? $article['price'] ?? $article['unitPrice'] ?? 0,
            'currency' => $article['currency'] ?? 'EUR',
            'unit_type' => $article['unit'] ?? $article['unitType'] ?? 'piece',
            'is_active' => $article['active'] ?? true,
            'is_parent_article' => $isParent,
            'is_parent_item' => $isParent,
            'service_type' => null,
            
            // Standard Robaws fields - Sales & Display
            'sales_name' => $article['saleName'] ?? null,
            'brand' => $article['brand'] ?? null,
            'barcode' => $article['barcode'] ?? null,
            'article_number' => $article['articleNumber'] ?? null,
            
            // Detailed pricing
            'cost_price' => $article['costPrice'] ?? null,
            'sale_price_strategy' => $article['salePriceStrategy'] ?? null,
            'cost_price_strategy' => $article['costPriceStrategy'] ?? null,
            'margin' => $article['margin'] ?? null,
            
            // Product attributes
            'weight_kg' => $article['weightKg'] ?? $article['weight'] ?? null,
            'vat_tariff_id' => $article['vatTariffId'] ?? null,
            'stock_article' => $article['stockArticle'] ?? false,
            'time_operation' => $article['timeOperation'] ?? false,
            'installation' => $article['installation'] ?? false,
            'wappy' => $article['wappy'] ?? false,
            
            // Images & media
            'image_id' => $article['imageId'] ?? null,
            
            // Composite items (store as JSON)
            'composite_items' => $this->extractCompositeItems($article),
            
            // Existing metadata
            'is_surcharge' => false,
            'requires_manual_review' => false,
            'min_quantity' => 1,
            'max_quantity' => 999999,
            'last_synced_at' => now(),
            'last_modified_at' => isset($article['lastModified']) ? \Carbon\Carbon::parse($article['lastModified']) : now(),
        ];
        
        // Parse article code if available
        if ($data['article_code']) {
            $parsed = $this->parseArticleCode($data['article_code'], $articleName);
            $data = array_merge($data, $parsed);
        }
        
        // Populate POL/POD strings from primary fields when present
        $data['pol'] = $article['pol'] ?? $article['origin'] ?? $data['pol'] ?? null;
        $data['pod'] = $article['pod'] ?? $article['destination'] ?? $data['pod'] ?? null;
        $data['pol_code'] = $article['pol_code'] ?? $article['originCode'] ?? $data['pol_code'] ?? null;
        $data['pod_code'] = $article['pod_code'] ?? $article['destinationCode'] ?? $data['pod_code'] ?? null;
        
        if (!empty($data['pol'])) {
            $data['pol'] = trim($data['pol']);
        }
        
        if (!empty($data['pod'])) {
            $data['pod'] = trim($data['pod']);
        }
        
        // Extract enhanced fields for Smart Article Selection
        try {
            $data['commodity_type'] = $this->enhancementService->extractCommodityType($article);
            $data['pod_code'] = $this->enhancementService->extractPodCode($article['pod'] ?? $article['destination'] ?? '');
            $data['pol_code'] = $this->enhancementService->extractPolCode($article['pol'] ?? $article['origin'] ?? '');
        } catch (\Exception $e) {
            Log::debug('Failed to extract enhanced fields', [
                'article_id' => $article['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            // Non-critical - continue without enhanced fields
            $data['commodity_type'] = null;
            $data['pod_code'] = null;
            $data['pol_code'] = null;
        }
        
        // Supplement POL/POD metadata using parser if still missing
        $namePorts = $this->extractPolPodFromArticleName($articleName);
        foreach (['pol', 'pod', 'pol_code', 'pod_code', 'pol_terminal'] as $key) {
            if (empty($data[$key]) && !empty($namePorts[$key])) {
                $data[$key] = $namePorts[$key];
            }
        }
        
        // Fetch full article details including extraFields only if:
        // 1. fetchFullDetails is true AND
        // 2. extraFields are not already in article data (webhook/list API may include them)
        $metadata = [];
        $hasExtraFields = !empty($article['extraFields']);
        
        if ($fetchFullDetails && !$hasExtraFields) {
            // Only fetch if extraFields not already available
            try {
                Log::debug('Fetching full article details (extraFields missing)', [
                    'article_id' => $data['robaws_article_id'],
                    'has_extraFields_in_data' => $hasExtraFields
                ]);
                
                $fullDetails = $this->fetchFullArticleDetails($data['robaws_article_id']);
                if ($fullDetails) {
                    // Extract and store all metadata from full details
                    $metadata = $this->extractMetadataFromFullDetails($fullDetails, $articleName);
                    $data = array_merge($data, $metadata);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch full article details during sync', [
                    'article_id' => $data['robaws_article_id'],
                    'error' => $e->getMessage()
                ]);
                // Continue with basic data only
            }
        } elseif ($hasExtraFields) {
            // extraFields already in article data (from webhook or list API) - use them directly
            Log::debug('Using extraFields from article data (no API call needed)', [
                'article_id' => $data['robaws_article_id'],
                'extraFields_count' => count($article['extraFields'] ?? [])
            ]);
            
            $metadata = $this->extractMetadataFromFullDetails($article, $articleName);
            $data = array_merge($data, $metadata);
        }

        $resolvedTransportMode = $this->transportModeResolver->resolve($articleName, [
            'transport_mode' => $data['transport_mode'] ?? $metadata['transport_mode'] ?? null,
            'shipping_line' => $data['shipping_line'] ?? $metadata['shipping_line'] ?? $article['shippingLine'] ?? null,
            'commodity_type' => $data['commodity_type'] ?? $metadata['commodity_type'] ?? ($article['commodity_type'] ?? null),
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? $article['description'] ?? null,
            'article_code' => $data['article_code'] ?? null,
        ]);

        if ($resolvedTransportMode) {
            $data['transport_mode'] = $resolvedTransportMode;
        }

        if (empty($data['service_type']) && !empty($data['transport_mode'])) {
            $data['service_type'] = $this->normalizeServiceTypeFromMode($data['transport_mode']);
        }

        if (!empty($data['applicable_services'])) {
            $data['service_type'] = $data['service_type'] ?? $this->selectPrimaryService($data['applicable_services']);
        }

        if (!empty($data['article_type'])) {
            $data['article_type'] = Str::upper(trim($data['article_type']));
        }

        if (isset($data['is_mandatory'])) {
            $data['is_mandatory'] = (bool) $data['is_mandatory'];
        }

        if (!empty($data['mandatory_condition'])) {
            $data['mandatory_condition'] = trim((string) $data['mandatory_condition']);
        }

        if (!empty($data['cost_side'])) {
            $data['cost_side'] = Str::upper(trim($data['cost_side']));
        } elseif (!empty($data['article_type'])) {
            $data['cost_side'] = $this->guessCostSide($data['article_type']);
        }

        if (!empty($data['pol_code'])) {
            if (preg_match('/\(/', $data['pol_code']) || strlen($data['pol_code']) > 4) {
                $data['pol_code'] = $this->enhancementService->extractPolCode($data['pol_code']) ?? $data['pol_code'];
            }
            $data['pol_code'] = Str::upper(trim($data['pol_code']));
        }

        if (!empty($data['pod_code'])) {
            if (preg_match('/\(/', $data['pod_code']) || strlen($data['pod_code']) > 4) {
                $data['pod_code'] = $this->enhancementService->extractPodCode($data['pod_code']) ?? $data['pod_code'];
            }
            $data['pod_code'] = Str::upper(trim($data['pod_code']));
        }

        // Resolve port foreign keys using PortResolutionService
        // Resolve POL port
        if (!empty($data['pol_code'])) {
            $polPort = $this->portResolver->resolveOne($data['pol_code'], 'SEA');
            $data['pol_port_id'] = $polPort?->id;
        } else {
            $data['pol_port_id'] = null;
        }

        // Resolve POD port
        if (!empty($data['pod_code'])) {
            $podPort = $this->portResolver->resolveOne($data['pod_code'], 'SEA');
            $data['pod_port_id'] = $podPort?->id;
        } else {
            $data['pod_port_id'] = null;
        }

        // Set requires_manual_review flag
        $hasPolCode = !empty($data['pol_code']);
        $hasPodCode = !empty($data['pod_code']);
        $polResolved = !empty($data['pol_port_id']);
        $podResolved = !empty($data['pod_port_id']);

        if (($hasPolCode && !$polResolved) || ($hasPodCode && !$podResolved)) {
            $data['requires_manual_review'] = true;
        } elseif ((!$hasPolCode && !$hasPodCode) || ($polResolved && (!$hasPodCode || $podResolved))) {
            // Both resolved (or no codes to resolve) - clear flag
            $data['requires_manual_review'] = false;
        }
        
        // Upsert the article with all metadata (including pol_port_id, pod_port_id, requires_manual_review)
        $article = RobawsArticleCache::updateOrCreate(
            ['robaws_article_id' => $data['robaws_article_id']],
            $data
        );
        
        // Sync composite items as relational links if this is a parent article
        if (!empty($data['composite_items']) && $data['is_parent_article']) {
            try {
                $this->syncCompositeItemsAsRelations($article, $data['composite_items']);
            } catch (\Exception $e) {
                Log::warning('Failed to sync composite items as relations', [
                    'article_id' => $article->robaws_article_id,
                    'article_name' => $article->article_name,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the whole sync if composite items fail
            }
        }
    }

    /**
     * Parse article code to extract service type and customer type
     * Examples: BWFCLIMP, BWA-FCL, CIB-RORO-IMP, GANRLAG
     */
    protected function parseArticleCode(string $code, string $description = ''): array
    {
        $result = [
            'transport_mode' => null,
            'service_type' => null,
            'customer_type' => null,
            'applicable_services' => [],
            'carriers' => [],
        ];
        
        $code = strtoupper($code);
        $description = strtoupper($description);
        
        // Detect customer type
        if (str_contains($code, 'CIB') || str_contains($description, 'CIB')) {
            $result['customer_type'] = 'CIB';
        } elseif (str_contains($code, 'FORWARD') || str_contains($description, 'FORWARD')) {
            $result['customer_type'] = 'FORWARDERS';
        } elseif (str_contains($code, 'PRIVATE') || str_contains($description, 'PRIVATE')) {
            $result['customer_type'] = 'PRIVATE';
        } elseif (str_contains($code, 'HOLLANDICO') || str_contains($description, 'HOLLANDICO')) {
            $result['customer_type'] = 'HOLLANDICO';
        }
        
        // Detect carriers
        $carrierCodes = ['GANRLAG', 'GRIMALDI', 'MSC', 'CMA', 'MAERSK', 'COSCO'];
        foreach ($carrierCodes as $carrier) {
            if (str_contains($code, $carrier) || str_contains($description, $carrier)) {
                $result['carriers'][] = $carrier;
            }
        }
        
        // Detect transport mode / service type
        if (str_contains($code, 'RORO') || str_contains($description, 'RORO')) {
            $result['transport_mode'] = 'RORO';
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['applicable_services'] = ['RORO_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['applicable_services'] = ['RORO_EXPORT'];
            } else {
                $result['applicable_services'] = ['RORO_IMPORT', 'RORO_EXPORT'];
            }
        } elseif (str_contains($code, 'FCL') || str_contains($description, 'FCL')) {
            $result['transport_mode'] = str_contains($code . $description, 'CONSOL') ? 'FCL CONSOL' : 'FCL';
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['applicable_services'] = ['FCL_IMPORT', 'FCL_IMPORT_CONSOL'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['applicable_services'] = ['FCL_EXPORT', 'FCL_EXPORT_CONSOL'];
            } else {
                $result['applicable_services'] = ['FCL_IMPORT', 'FCL_EXPORT', 'FCL_IMPORT_CONSOL', 'FCL_EXPORT_CONSOL'];
            }
        } elseif (str_contains($code, 'LCL') || str_contains($description, 'LCL')) {
            $result['transport_mode'] = 'LCL';
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['applicable_services'] = ['LCL_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['applicable_services'] = ['LCL_EXPORT'];
            } else {
                $result['applicable_services'] = ['LCL_IMPORT', 'LCL_EXPORT'];
            }
        } elseif (str_contains($code, 'BB') || str_contains($description, 'BREAK BULK') || str_contains($description, 'BREAKBULK')) {
            $result['transport_mode'] = 'BB';
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['applicable_services'] = ['BB_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['applicable_services'] = ['BB_EXPORT'];
            } else {
                $result['applicable_services'] = ['BB_IMPORT', 'BB_EXPORT'];
            }
        } elseif (str_contains($code, 'AIR') || str_contains($description, 'AIRFREIGHT') || str_contains($description, 'AIR FREIGHT')) {
            $result['transport_mode'] = 'AIRFREIGHT';
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['applicable_services'] = ['AIR_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['applicable_services'] = ['AIR_EXPORT'];
            } else {
                $result['applicable_services'] = ['AIR_IMPORT', 'AIR_EXPORT'];
            }
        } elseif (str_contains($code, 'ROAD') || str_contains($description, 'ROAD') || str_contains($description, 'TRUCK')) {
            $result['transport_mode'] = 'ROAD TRANSPORT';
        } elseif (str_contains($code, 'CUSTOMS') || str_contains($description, 'CUSTOMS')) {
            $result['transport_mode'] = 'CUSTOMS';
        } elseif (str_contains($code, 'PORT') && str_contains($description, 'FORWARDING')) {
            $result['transport_mode'] = 'PORT FORWARDING';
        } elseif (str_contains($code, 'HOMOLOGATION') || str_contains($description, 'HOMOLOGATION')) {
            $result['transport_mode'] = 'HOMOLOGATION';
        } elseif (str_contains($code, 'VEHICLE') && str_contains($description, 'PURCHASE')) {
            $result['transport_mode'] = 'VEHICLE PURCHASE';
        } elseif (str_contains($code, 'WAREHOUSE') || str_contains($description, 'WAREHOUSE')) {
            $result['transport_mode'] = 'WAREHOUSE';
        }

        if (!$result['transport_mode']) {
            if (str_contains($description, 'RORO')) {
                $result['transport_mode'] = 'RORO';
            } elseif (str_contains($description, 'FCL CONSOL')) {
                $result['transport_mode'] = 'FCL CONSOL';
            } elseif (str_contains($description, 'FCL')) {
                $result['transport_mode'] = 'FCL';
            } elseif (str_contains($description, 'LCL')) {
                $result['transport_mode'] = 'LCL';
            } elseif (str_contains($description, 'BREAKBULK') || str_contains($description, 'BREAK BULK') || str_contains($description, 'BB')) {
                $result['transport_mode'] = 'BB';
            } elseif (str_contains($description, 'AIR')) {
                $result['transport_mode'] = 'AIRFREIGHT';
            }
        }
        
        if (!empty($result['applicable_services'])) {
            $result['service_type'] = $this->selectPrimaryService($result['applicable_services']);
        }

        if (!$result['service_type'] && $result['transport_mode']) {
            $result['service_type'] = $this->normalizeServiceTypeFromMode($result['transport_mode']);
        }

        return $result;
    }

    /**
     * Choose first applicable service from list.
     */
    protected function selectPrimaryService(array $services): ?string
    {
        $service = reset($services);
        return $service ? strtoupper($service) : null;
    }

    /**
     * Normalize legacy service_type value from transport mode.
     */
    protected function normalizeServiceTypeFromMode(string $transportMode): string
    {
        return strtoupper(str_replace(' ', '_', $transportMode));
    }

    /**
     * Clear all cached articles
     */
    public function clearCache(): void
    {
        Log::info('Clearing article cache');
        RobawsArticleCache::query()->delete();
    }

    /**
     * Clear and rebuild cache from Robaws API
     * 
     * @param bool $syncMetadata Whether to automatically sync metadata after rebuild
     */
    public function rebuildCache(bool $syncMetadata = true): array
    {
        Log::info('Rebuilding article cache from Robaws API', [
            'sync_metadata' => $syncMetadata
        ]);
        
        DB::beginTransaction();
        try {
            $this->clearCache();
            $result = $this->sync();
            DB::commit();
            
            Log::info('Cache rebuild completed', $result);
            
            // Automatically sync metadata for all articles (synchronous)
            if ($syncMetadata && $result['success']) {
                Log::info('Starting synchronous metadata sync after rebuild', [
                    'total_articles' => $result['total']
                ]);
                
                $this->syncAllMetadata();
            }
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Cache rebuild failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Sync metadata for all articles synchronously
     * 
     * @param bool $useApi Whether to use Robaws API (slower, more complete) or fast fallback extraction
     */
    public function syncAllMetadata(bool $useApi = false): array
    {
        $articles = RobawsArticleCache::all();
        $total = $articles->count();
        $successCount = 0;
        $failCount = 0;
        
        Log::info('Starting synchronous metadata sync', [
            'total_articles' => $total,
            'use_api' => $useApi
        ]);
        
        foreach ($articles as $index => $article) {
            try {
                $provider = app(\App\Services\Robaws\RobawsArticleProvider::class);
                
                // Use fast fallback extraction by default (no API calls)
                // API will still be used automatically for parent items (composite articles)
                $provider->syncArticleMetadata($article->id, useApi: $useApi);
                
                $successCount++;
                
                // Log progress every 100 articles
                if (($index + 1) % 100 === 0) {
                    Log::info('Metadata sync progress', [
                        'processed' => $index + 1,
                        'total' => $total,
                        'progress_percent' => round((($index + 1) / $total) * 100, 1)
                    ]);
                }
                
            } catch (\Exception $e) {
                $failCount++;
                Log::warning('Failed to sync metadata for article', [
                    'article_id' => $article->id,
                    'article_name' => $article->article_name,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Completed synchronous metadata sync', [
            'total' => $total,
            'success' => $successCount,
            'failed' => $failCount
        ]);
        
        return [
            'total' => $total,
            'success' => $successCount,
            'failed' => $failCount
        ];
    }
    
    /**
     * Fetch full article details including extraFields from Robaws API
     */
    protected function fetchFullArticleDetails(string $articleId): ?array
    {
        try {
            $provider = app(RobawsArticleProvider::class);
            return $provider->getArticleDetails($articleId);
        } catch (\Exception $e) {
            Log::debug('Failed to fetch full article details', [
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Extract metadata from full article details (including extraFields)
     * This replicates the logic from RobawsArticleProvider::parseArticleMetadata
     */
    protected function extractMetadataFromFullDetails(array $fullDetails, string $articleName): array
    {
        $metadata = [];
        
        // Parse extraFields for metadata
        $extraFields = $fullDetails['extraFields'] ?? [];
        
        foreach ($extraFields as $fieldName => $field) {
            // Handle multiple value types: stringValue, booleanValue, numberValue, or direct value
            $value = $field['stringValue'] 
                   ?? $field['booleanValue'] 
                   ?? $field['numberValue']
                   ?? $field['value'] 
                   ?? null;
            
            switch ($fieldName) {
                // These are now handled by RobawsFieldMapper for robust extraction
                // Keep the switch for other fields not in the mapper
                case 'SHIPPING LINE':
                case 'SHIPPING_LINE':
                    $metadata['shipping_line'] = $value;
                    break;
                case 'TRANSPORT MODE':
                case 'TRANSPORT_MODE':
                case 'SERVICE TYPE': // Backwards compatibility
                case 'SERVICE_TYPE':
                    $metadata['transport_mode'] = $value;
                    break;
                case 'POL TERMINAL':
                case 'POL_TERMINAL':
                    $metadata['pol_terminal'] = $value;
                    break;
                case 'POL CODE':
                case 'POL_CODE':
                    $metadata['pol_code'] = $value;
                    break;
                case 'POD CODE':
                case 'POD_CODE':
                    $metadata['pod_code'] = $value;
                    break;
                case 'PARENT ITEM':
                case 'PARENT_ITEM':
                    // Robaws returns 1/0 for checkbox, convert to boolean
                    $metadata['is_parent_item'] = (bool) ((int) $value);
                    break;
                case 'POL':
                    $metadata['pol'] = $value;
                    break;
                case 'POD':
                    $metadata['pod'] = $value;
                    break;
                case 'ARTICLE TYPE':
                case 'ARTICLE_TYPE':
                    $metadata['article_type'] = $value;
                    break;
                case 'COST SIDE':
                case 'COST_SIDE':
                    $metadata['cost_side'] = $value;
                    break;
                case 'IS MANDATORY':
                case 'IS_MANDATORY':
                    $metadata['is_mandatory'] = (bool) ((int) $value);
                    break;
                case 'MANDATORY CONDITION':
                case 'MANDATORY_CONDITION':
                    $metadata['mandatory_condition'] = $value;
                    break;
                case 'NOTES':
                case 'NOTE':
                    $metadata['notes'] = $value;
                    break;
                case 'TYPE':
                case 'COMMODITY TYPE':
                case 'COMMODITY_TYPE':
                    $metadata['type'] = $value;
                    break;
                case 'ARTICLE_INFO':
                case 'INFO':
                    $metadata['article_info'] = $value;
                    break;
                case 'UPDATE DATE':
                case 'UPDATE_DATE':
                    $metadata['update_date'] = $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
                    break;
                case 'VALIDITY DATE':
                case 'VALIDITY_DATE':
                    $metadata['validity_date'] = $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
                    break;
            }
        }
        
        // Extract POL/POD from article name using the same logic as RobawsArticleProvider
        $polPodData = $this->extractPolPodFromArticleName($articleName);
        $metadata = array_merge($metadata, $polPodData);
        
        // Extract transport mode from description if not found in extraFields
        if (empty($metadata['transport_mode'])) {
            $metadata['transport_mode'] = $this->extractTransportModeFromDescription($fullDetails['description'] ?? $articleName);
        }
        
        // Extract shipping line from description if not found in extraFields
        if (empty($metadata['shipping_line'])) {
            $metadata['shipping_line'] = $this->extractShippingLineFromDescription($fullDetails['description'] ?? $articleName);
        }
        
        // Extract enhanced fields for Smart Article Selection using extraFields data
        try {
            // Use TYPE from Robaws extraFields if available, otherwise parse from name
            if (!empty($metadata['type'])) {
                $metadata['commodity_type'] = $this->enhancementService->extractCommodityType(['type' => $metadata['type']]);
            } else {
                $metadata['commodity_type'] = $this->enhancementService->extractCommodityType(['article_name' => $articleName]);
            }
            
            // Use POD from Robaws extraFields if available
            if (!empty($metadata['pod'])) {
                $metadata['pod_code'] = $this->enhancementService->extractPodCode($metadata['pod']);
            } elseif (!empty($metadata['pod_name'])) {
                $metadata['pod_code'] = $this->enhancementService->extractPodCode($metadata['pod_name']);
            }
            
            if (!empty($metadata['pol'])) {
                $metadata['pol_code'] = $this->enhancementService->extractPolCode($metadata['pol']);
            } elseif (!empty($metadata['origin'])) {
                $metadata['pol_code'] = $this->enhancementService->extractPolCode($metadata['origin']);
            }
        } catch (\Exception $e) {
            Log::debug('Failed to extract enhanced fields from full details', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Supplement missing metadata with name parsing
        $namePorts = $this->extractPolPodFromArticleName($articleName);
        foreach (['pol', 'pod', 'pol_code', 'pod_code', 'pol_terminal'] as $key) {
            if (empty($metadata[$key]) && !empty($namePorts[$key])) {
                $metadata[$key] = $namePorts[$key];
            }
        }
        
        return $metadata;
    }
    
    /**
     * Extract POL/POD from article name using centralized parser
     */
    protected function extractPolPodFromArticleName(string $articleName): array
    {
        $data = [];
        
        // Use centralized parser for POL
        $polData = $this->parser->extractPOL($articleName);
        if ($polData) {
            if (!empty($polData['code'])) {
                $data['pol_code'] = $polData['code'];
            }

            if (!empty($polData['formatted'])) {
                $data['pol'] = $polData['formatted'];
            }

            if ($polData['terminal']) {
                $data['pol_terminal'] = $polData['terminal'];
            }
        }
        
        // Use centralized parser for POD
        $podData = $this->parser->extractPOD($articleName);
        if ($podData) {
            if (!empty($podData['code'])) {
                $data['pod_code'] = $podData['code'];
            }

            if (!empty($podData['formatted'])) {
                $data['pod'] = $podData['formatted'];
            }
        }
        
        return $data;
    }

    /**
     * Guess cost side based on article type when not explicitly provided
     */
    protected function guessCostSide(?string $articleType): ?string
    {
        if (!$articleType) {
            return null;
        }

        $type = Str::upper(trim($articleType));

        return match (true) {
            str_contains($type, 'LOCAL CHARGES POL') => 'POL',
            str_contains($type, 'LOCAL CHARGES POD') => 'POD',
            str_contains($type, 'SEAFREIGHT SURCHARGES') => 'SEA',
            str_contains($type, 'SEAFREIGHT') => 'SEA',
            str_contains($type, 'AIRFREIGHT') => 'AIR',
            str_contains($type, 'ROAD TRANSPORT') => 'INLAND',
            str_contains($type, 'INSPECTION SURCHARGES') => 'POL',
            str_contains($type, 'ADMINISTRATIVE') || str_contains($type, 'MISC') => 'ADMIN',
            str_contains($type, 'WAREHOUSE') => 'WAREHOUSE',
            default => null,
        };
    }
    
    /**
     * Extract transport mode from description
     */
    protected function extractTransportModeFromDescription(string $description): ?string
    {
        $desc = strtoupper($description);

        if (str_contains($desc, 'RORO')) {
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

        if (str_contains($desc, 'ROAD')) {
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

        if (str_contains($desc, 'WAREHOUSE')) {
            return 'WAREHOUSE';
        }

        if (str_contains($desc, 'SEAFREIGHT')) {
            return 'SEAFREIGHT';
        }

        return null;
    }

    /**
     * Extract shipping line from description
     */
    protected function extractShippingLineFromDescription(string $description): ?string
    {
        $knownCarriers = config('quotation.known_carriers', []);
        $desc = strtoupper($description);
        
        foreach ($knownCarriers as $carrier) {
            if (str_contains($desc, strtoupper($carrier))) {
                return $carrier;
            }
        }
        
        return null;
    }
    
    /**
     * Extract Parent Item boolean from article data
     * Handles both API format (custom_fields) and webhook format (extraFields)
     */
    protected function extractParentItemFromArticle(array $article): bool
    {
        // Try custom_fields first (API format)
        if (isset($article['custom_fields']['parent_item'])) {
            return (bool) $article['custom_fields']['parent_item'];
        }
        
        // Try extraFields (webhook format)
        // The field ID is C965754A-4523-4916-A127-3522DE1A7001
        if (isset($article['extraFields']['C965754A-4523-4916-A127-3522DE1A7001']['booleanValue'])) {
            return (bool) $article['extraFields']['C965754A-4523-4916-A127-3522DE1A7001']['booleanValue'];
        }
        
        // Try alternative webhook format (extraFields with parent_item key)
        if (isset($article['extraFields']['parent_item'])) {
            $field = $article['extraFields']['parent_item'];
            return (bool) ($field['booleanValue'] ?? $field['value'] ?? false);
        }
        
        return false;
    }
    
    /**
     * Extract composite items/surcharges from article data
     * Stores the structure from Robaws without creating child relationships
     * 
     * @param array $article
     * @return array|null
     */
    protected function extractCompositeItems(array $article): ?array
    {
        // Check multiple possible field names for composite items
        $compositeItems = $article['compositeItems'] ?? 
                          $article['lines'] ?? 
                          $article['articleLines'] ?? 
                          $article['additionalItems'] ?? 
                          $article['items'] ?? 
                          null;
        
        if (empty($compositeItems) || !is_array($compositeItems)) {
            return null;
        }
        
        // Extract relevant data from each composite item
        $items = [];
        foreach ($compositeItems as $item) {
            $items[] = [
                'id' => $item['id'] ?? null,
                'article_id' => $item['articleId'] ?? null,
                'name' => $item['name'] ?? $item['description'] ?? null,
                'article_number' => $item['articleNumber'] ?? null,
                'quantity' => $item['quantity'] ?? 1,
                'unit_type' => $item['unitType'] ?? null,
                'cost_price' => $item['costPrice'] ?? null,
                'cost_type' => $item['costType'] ?? $item['type'] ?? 'Material',
                'description' => $item['description'] ?? null,
                'is_required' => $item['isRequired'] ?? true,
            ];
        }
        
        return $items;
    }
    
    /**
     * Sync composite items as relational links for offer creation
     * Creates or updates the article_children pivot table
     * 
     * @param \App\Models\RobawsArticleCache $parent
     * @param array $compositeItems
     * @return int Number of composite items synced
     */
    protected function syncCompositeItemsAsRelations(RobawsArticleCache $parent, array $compositeItems): int
    {
        if (empty($compositeItems)) {
            return 0;
        }
        
        $syncedCount = 0;
        $pivotData = [];
        
        foreach ($compositeItems as $index => $itemData) {
            $child = $this->findOrCreateChildArticle($itemData);
            
            if ($child) {
                // Prepare pivot data for sync
                $pivotData[$child->id] = [
                    'sort_order' => $index + 1,
                    'is_required' => $itemData['is_required'] ?? true,
                    'is_conditional' => false,
                    'cost_type' => $itemData['cost_type'] ?? 'Material',
                    'default_quantity' => $itemData['quantity'] ?? 1.0,
                    'default_cost_price' => $itemData['cost_price'] ?? null,
                    'unit_type' => $itemData['unit_type'] ?? null,
                ];
                
                $syncedCount++;
            }
        }
        
        // Sync all at once (more efficient than attach in loop)
        if (!empty($pivotData)) {
            $parent->children()->sync($pivotData);
            
            Log::info('Synced composite items as relations', [
                'parent_article_id' => $parent->robaws_article_id,
                'parent_name' => $parent->article_name,
                'children_count' => $syncedCount
            ]);
        }
        
        return $syncedCount;
    }
    
    /**
     * Find or create a child article from composite item data
     * 
     * @param array $itemData
     * @return \App\Models\RobawsArticleCache|null
     */
    protected function findOrCreateChildArticle(array $itemData): ?RobawsArticleCache
    {
        // Try to find by Robaws article ID first
        $articleId = $itemData['article_id'] ?? $itemData['id'] ?? null;
        
        if ($articleId) {
            $child = RobawsArticleCache::where('robaws_article_id', $articleId)->first();
            
            if ($child) {
                return $child;
            }
        }
        
        // Try to find by article number
        if (!empty($itemData['article_number'])) {
            $child = RobawsArticleCache::where('article_number', $itemData['article_number'])->first();
            
            if ($child) {
                return $child;
            }
        }
        
        // Try to find by name (for existing surcharges)
        $name = $itemData['name'] ?? null;
        if ($name) {
            $child = RobawsArticleCache::where('article_name', $name)
                ->where('is_surcharge', true)
                ->first();
            
            if ($child) {
                return $child;
            }
        }
        
        // Create new surcharge article if not found
        try {
            return RobawsArticleCache::create([
                'robaws_article_id' => $articleId ?? ('COMPOSITE_' . uniqid()),
                'article_number' => $itemData['article_number'] ?? null,
                'article_name' => $name ?? 'Unnamed Surcharge',
                'description' => $itemData['description'] ?? '',
                'category' => 'miscellaneous',
                'unit_price' => $itemData['cost_price'] ?? 0,
                'cost_price' => $itemData['cost_price'] ?? 0,
                'unit_type' => $itemData['unit_type'] ?? null,
                'currency' => 'EUR',
                'is_surcharge' => true,
                'is_active' => true,
                'last_synced_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create child article', [
                'item_data' => $itemData,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}


