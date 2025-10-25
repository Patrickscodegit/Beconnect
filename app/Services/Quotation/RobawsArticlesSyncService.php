<?php

namespace App\Services\Quotation;

use App\Models\RobawsArticleCache;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Robaws\RobawsArticleProvider;
use App\Services\Robaws\ArticleNameParser;
use App\Services\Robaws\ArticleSyncEnhancementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsArticlesSyncService
{
    protected RobawsApiClient $apiClient;
    protected ArticleNameParser $parser;
    protected RobawsArticleProvider $articleProvider;
    protected ArticleSyncEnhancementService $enhancementService;

    public function __construct(
        RobawsApiClient $apiClient, 
        ArticleNameParser $parser,
        RobawsArticleProvider $articleProvider,
        ArticleSyncEnhancementService $enhancementService
    )
    {
        $this->apiClient = $apiClient;
        $this->parser = $parser;
        $this->articleProvider = $articleProvider;
        $this->enhancementService = $enhancementService;
    }

    /**
     * Sync articles directly from Robaws Articles API
     */
    public function sync(): array
    {
        Log::info('Starting direct articles API sync');
        
        $result = $this->apiClient->getAllArticlesPaginated();
        
        if (!$result['success']) {
            Log::error('Articles API sync failed', ['error' => $result['error'] ?? 'Unknown']);
            throw new \RuntimeException('Failed to fetch articles from Robaws API');
        }
        
        $articles = $result['articles'];
        $synced = 0;
        $errors = 0;
        
        foreach ($articles as $articleData) {
            try {
                $this->processArticle($articleData);
                $synced++;
            } catch (\Exception $e) {
                $errors++;
                Log::warning('Failed to process article', [
                    'article_id' => $articleData['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Direct articles API sync completed', [
            'total_articles' => count($articles),
            'synced' => $synced,
            'errors' => $errors
        ]);
        
        return [
            'success' => true,
            'total' => count($articles),
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
        
        // Process each modified article (NO API calls - webhooks handle real-time)
        foreach ($articles as $articleData) {
            try {
                // Only process basic data, skip API call for extraFields
                $this->processArticle($articleData, fetchFullDetails: false);
                
                // Extract metadata from stored data
                $this->articleProvider->syncArticleMetadata(
                    $articleData['id'],
                    useApi: false
                );
                
                $synced++;
                
                if ($synced % 10 === 0) {
                    Log::info('Incremental sync progress', [
                        'processed' => $synced,
                        'total' => count($articles),
                        'note' => 'Fast sync - no API calls (webhooks handle real-time)'
                    ]);
                }
            } catch (\RuntimeException $e) {
                // Handle daily quota exceeded (shouldn't happen with no API calls)
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
     * Process article from webhook event (no API calls needed)
     */
    public function processArticleFromWebhook(array $articleData, string $event): void
    {
        Log::info('Processing webhook event', [
            'event' => $event,
            'article_id' => $articleData['id'] ?? null,
            'article_name' => $articleData['name'] ?? null
        ]);
        
        // Webhook includes full article data - no API call needed!
        $this->processArticle($articleData, fetchFullDetails: false);
        
        // Extract metadata from the article name
        if (isset($articleData['id'])) {
            try {
                $this->articleProvider->syncArticleMetadata(
                    $articleData['id'],
                    useApi: false // Use webhook data, not API
                );
            } catch (\Exception $e) {
                Log::warning('Failed to sync metadata from webhook', [
                    'article_id' => $articleData['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info('Webhook event processed successfully', [
            'event' => $event,
            'article_id' => $articleData['id'] ?? null
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
        
        $data = [
            'robaws_article_id' => $article['id'],
            'article_code' => $article['code'] ?? $article['articleNumber'] ?? $article['id'],
            'article_name' => $articleName,
            'description' => $article['description'] ?? $article['notes'] ?? null,
            'category' => $article['category'] ?? 'general',
            'unit_price' => $article['price'] ?? $article['unitPrice'] ?? $article['salePrice'] ?? 0,
            'currency' => $article['currency'] ?? 'EUR',
            'unit_type' => $article['unit'] ?? $article['unitType'] ?? 'piece',
            'is_active' => $article['active'] ?? true,
            'is_parent_article' => $this->extractParentItemFromArticle($article),
            
            // Standard Robaws fields - Sales & Display
            'sales_name' => $article['saleName'] ?? null,
            'brand' => $article['brand'] ?? null,
            'barcode' => $article['barcode'] ?? null,
            'article_number' => $article['articleNumber'] ?? null,
            
            // Detailed pricing
            'sale_price' => $article['salePrice'] ?? null,
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
        
        // Extract enhanced fields for Smart Article Selection
        try {
            $data['commodity_type'] = $this->enhancementService->extractCommodityType($article);
            $data['pod_code'] = $this->enhancementService->extractPodCode($article['pod'] ?? $article['destination'] ?? '');
        } catch (\Exception $e) {
            Log::debug('Failed to extract enhanced fields', [
                'article_id' => $article['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            // Non-critical - continue without enhanced fields
            $data['commodity_type'] = null;
            $data['pod_code'] = null;
        }
        
        // Fetch full article details including extraFields if requested (for incremental sync)
        if ($fetchFullDetails) {
            try {
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
        }
        
        // Upsert the article with all metadata
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
        
        // Detect service type
        if (str_contains($code, 'RORO') || str_contains($description, 'RORO')) {
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['service_type'] = 'RORO_IMPORT';
                $result['applicable_services'] = ['RORO_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['service_type'] = 'RORO_EXPORT';
                $result['applicable_services'] = ['RORO_EXPORT'];
            } else {
                $result['applicable_services'] = ['RORO_IMPORT', 'RORO_EXPORT'];
            }
        } elseif (str_contains($code, 'FCL') || str_contains($description, 'FCL')) {
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['service_type'] = 'FCL_IMPORT';
                $result['applicable_services'] = ['FCL_IMPORT', 'FCL_IMPORT_CONSOL'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['service_type'] = 'FCL_EXPORT';
                $result['applicable_services'] = ['FCL_EXPORT', 'FCL_EXPORT_CONSOL'];
            } else {
                $result['applicable_services'] = ['FCL_IMPORT', 'FCL_EXPORT', 'FCL_IMPORT_CONSOL', 'FCL_EXPORT_CONSOL'];
            }
        } elseif (str_contains($code, 'LCL') || str_contains($description, 'LCL')) {
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['service_type'] = 'LCL_IMPORT';
                $result['applicable_services'] = ['LCL_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['service_type'] = 'LCL_EXPORT';
                $result['applicable_services'] = ['LCL_EXPORT'];
            } else {
                $result['applicable_services'] = ['LCL_IMPORT', 'LCL_EXPORT'];
            }
        } elseif (str_contains($code, 'BB') || str_contains($description, 'BREAK BULK')) {
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['service_type'] = 'BB_IMPORT';
                $result['applicable_services'] = ['BB_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['service_type'] = 'BB_EXPORT';
                $result['applicable_services'] = ['BB_EXPORT'];
            } else {
                $result['applicable_services'] = ['BB_IMPORT', 'BB_EXPORT'];
            }
        } elseif (str_contains($code, 'AIR') || str_contains($description, 'AIR')) {
            if (str_contains($code, 'IMP') || str_contains($description, 'IMPORT')) {
                $result['service_type'] = 'AIR_IMPORT';
                $result['applicable_services'] = ['AIR_IMPORT'];
            } elseif (str_contains($code, 'EXP') || str_contains($description, 'EXPORT')) {
                $result['service_type'] = 'AIR_EXPORT';
                $result['applicable_services'] = ['AIR_EXPORT'];
            } else {
                $result['applicable_services'] = ['AIR_IMPORT', 'AIR_EXPORT'];
            }
        }
        
        return $result;
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
                case 'SHIPPING LINE':
                    $metadata['shipping_line'] = $value;
                    break;
                case 'SERVICE TYPE':
                    $metadata['service_type'] = $value;
                    break;
                case 'POL TERMINAL':
                    $metadata['pol_terminal'] = $value;
                    break;
                case 'PARENT ITEM':
                    // Robaws returns 1/0 for checkbox, convert to boolean
                    $metadata['is_parent_item'] = (bool) ((int) $value);
                    break;
                case 'POL':
                    $metadata['pol'] = $value;
                    break;
                case 'POD':
                    $metadata['pod'] = $value;
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
        
        // Extract service type from description if not found in extraFields
        if (empty($metadata['service_type'])) {
            $metadata['service_type'] = $this->extractServiceTypeFromDescription($fullDetails['description'] ?? $articleName);
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
            }
        } catch (\Exception $e) {
            Log::debug('Failed to extract enhanced fields from full details', [
                'error' => $e->getMessage()
            ]);
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
            $data['pol_code'] = $polData['formatted'];
            if ($polData['terminal']) {
                $data['pol_terminal'] = $polData['terminal'];
            }
        }
        
        // Use centralized parser for POD
        $podData = $this->parser->extractPOD($articleName);
        if ($podData) {
            $data['pod_name'] = $podData['formatted'];
        }
        
        return $data;
    }
    
    /**
     * Extract service type from description
     */
    protected function extractServiceTypeFromDescription(string $description): ?string
    {
        $desc = strtoupper($description);
        
        if (str_contains($desc, 'EXPORT')) {
            return str_contains($desc, 'FCL') ? 'FCL EXPORT' : 'EXPORT';
        } elseif (str_contains($desc, 'IMPORT')) {
            return str_contains($desc, 'FCL') ? 'FCL IMPORT' : 'IMPORT';
        } elseif (str_contains($desc, 'RORO')) {
            return 'RORO';
        } elseif (str_contains($desc, 'FCL')) {
            return 'FCL';
        } elseif (str_contains($desc, 'STATIC')) {
            return 'STATIC CARGO';
        } elseif (str_contains($desc, 'SEAFREIGHT')) {
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


