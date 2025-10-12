<?php

namespace App\Services\Quotation;

use App\Models\RobawsArticleCache;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsArticlesSyncService
{
    protected RobawsApiClient $apiClient;

    public function __construct(RobawsApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
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
     * Process and store article from Robaws API
     */
    protected function processArticle(array $article): void
    {
        // Map Robaws API article fields to our cache structure
        $articleName = $article['name'] ?? $article['description'] ?? 'Unnamed Article';
        
        $data = [
            'robaws_article_id' => $article['id'],
            'article_code' => $article['code'] ?? $article['articleNumber'] ?? $article['id'],
            'article_name' => $articleName,
            'description' => $article['description'] ?? $article['notes'] ?? null,
            'category' => $article['category'] ?? 'general',
            'unit_price' => $article['price'] ?? $article['unitPrice'] ?? 0,
            'currency' => $article['currency'] ?? 'EUR',
            'unit_type' => $article['unit'] ?? 'piece',
            'is_active' => $article['active'] ?? true,
            'is_parent_article' => false,
            'is_surcharge' => false,
            'requires_manual_review' => false,
            'min_quantity' => 1,
            'max_quantity' => 999999,
            'last_synced_at' => now(),
        ];
        
        // Parse article code if available
        if ($data['article_code']) {
            $parsed = $this->parseArticleCode($data['article_code'], $articleName);
            $data = array_merge($data, $parsed);
        }
        
        // Upsert the article
        RobawsArticleCache::updateOrCreate(
            ['robaws_article_id' => $data['robaws_article_id']],
            $data
        );
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
     */
    public function rebuildCache(): array
    {
        Log::info('Rebuilding article cache from Robaws API');
        
        DB::beginTransaction();
        try {
            $this->clearCache();
            $result = $this->sync();
            DB::commit();
            
            Log::info('Cache rebuild completed', $result);
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Cache rebuild failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}

