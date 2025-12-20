<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Robaws\ArticleSyncEnhancementService;
use App\Services\Robaws\ArticleNameParser;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $enhancementService = app(ArticleSyncEnhancementService::class);
        $nameParser = app(ArticleNameParser::class);

        // Find all admin articles dynamically (works in both local and production)
        $admin75 = DB::table('robaws_articles_cache')
            ->where('article_name', 'Admin 75')
            ->where('unit_price', 75)
            ->first();
        $admin100 = DB::table('robaws_articles_cache')
            ->where('article_name', 'Admin 100')
            ->where('unit_price', 100)
            ->first();
        $admin110 = DB::table('robaws_articles_cache')
            ->where('article_name', 'Admin 110')
            ->where('unit_price', 110)
            ->first();
        $admin115 = DB::table('robaws_articles_cache')
            ->where('article_name', 'Admin')
            ->where('unit_price', 115)
            ->first();
        $admin125 = DB::table('robaws_articles_cache')
            ->where('article_name', 'Admin 125')
            ->where('unit_price', 125)
            ->first();

        // Extract IDs safely
        $admin75Id = $admin75 ? $admin75->id : null;
        $admin100Id = $admin100 ? $admin100->id : null;
        $admin110Id = $admin110 ? $admin110->id : null;
        $admin115Id = $admin115 ? $admin115->id : null;
        $admin125Id = $admin125 ? $admin125->id : null;

        if (!$admin75Id || !$admin100Id || !$admin110Id || !$admin115Id || !$admin125Id) {
            Log::error('Could not find all admin articles in migration', [
                'admin_75' => $admin75Id ?: 'NOT FOUND',
                'admin_100' => $admin100Id ?: 'NOT FOUND',
                'admin_110' => $admin110Id ?: 'NOT FOUND',
                'admin_115' => $admin115Id ?: 'NOT FOUND',
                'admin_125' => $admin125Id ?: 'NOT FOUND',
            ]);
            throw new \Exception('Could not find all required admin articles. Please verify admin articles exist in robaws_articles_cache table.');
        }

        $allAdminIds = [$admin75Id, $admin100Id, $admin110Id, $admin115Id, $admin125Id];

        // Step 1: Remove all existing admin article attachments
        $removedCount = DB::table('article_children')
            ->whereIn('child_article_id', $allAdminIds)
            ->delete();

        Log::info('Removed existing admin article attachments', [
            'relationships_deleted' => $removedCount
        ]);

        // Step 2: Get all parent articles
        $parentArticles = DB::table('robaws_articles_cache')
            ->where('is_parent_article', true)
            ->get();

        Log::info('Processing parent articles for admin article attachment', [
            'parent_count' => $parentArticles->count()
        ]);

        $podCodeUpdated = 0;
        $commodityTypeUpdated = 0;
        $adminAttachments = [
            'admin_75' => 0,
            'admin_100' => 0,
            'admin_110' => 0,
            'admin_115' => 0,
            'admin_125' => 0,
        ];

        // Step 3: Process each parent article
        foreach ($parentArticles as $parent) {
            $updates = [];
            $podCode = $parent->pod_code;
            $commodityType = $parent->commodity_type;

            // Step 3a: Populate missing pod_code
            if (empty($podCode)) {
                // Try extracting from pod field
                if (!empty($parent->pod)) {
                    $podCode = $enhancementService->extractPodCode($parent->pod);
                }
                
                // If still empty, try extracting from article name
                if (empty($podCode) && !empty($parent->article_name)) {
                    $podData = $nameParser->extractPOD($parent->article_name);
                    if ($podData && !empty($podData['code'])) {
                        $podCode = $podData['code'];
                    }
                }

                if (!empty($podCode)) {
                    $updates['pod_code'] = $podCode;
                    $podCodeUpdated++;
                }
            }

            // Step 3b: Populate missing commodity_type
            if (empty($commodityType)) {
                $articleData = [
                    'article_name' => $parent->article_name,
                    'name' => $parent->article_name,
                    'article_info' => $parent->article_info,
                ];
                
                $commodityType = $enhancementService->extractCommodityType($articleData);
                
                if (!empty($commodityType)) {
                    $updates['commodity_type'] = $commodityType;
                    $commodityTypeUpdated++;
                }
            }

            // Update parent article if we found missing data
            if (!empty($updates)) {
                $updates['updated_at'] = now();
                DB::table('robaws_articles_cache')
                    ->where('id', $parent->id)
                    ->update($updates);
                
                // Refresh pod_code and commodity_type for admin article selection
                if (isset($updates['pod_code'])) {
                    $podCode = $updates['pod_code'];
                }
                if (isset($updates['commodity_type'])) {
                    $commodityType = $updates['commodity_type'];
                }
            }

            // Step 4: Determine correct admin article based on POD and commodity type
            $selectedAdminId = null;
            $selectedAdminKey = null;

            // Normalize POD code to uppercase for comparison
            $podCodeUpper = !empty($podCode) ? strtoupper(trim($podCode)) : null;
            $commodityTypeNormalized = !empty($commodityType) ? $this->normalizeCommodityType($commodityType) : null;

            // Apply conditional logic (same as QuotationRequestArticle::addChildArticles)
            // Priority order: 110, 115, 125, 100, 75
            if ($podCodeUpper === 'PNR') {
                $selectedAdminId = $admin110Id;
                $selectedAdminKey = 'admin_110';
            } elseif ($podCodeUpper === 'LAD') {
                $selectedAdminId = $admin115Id;
                $selectedAdminKey = 'admin_115';
            } elseif ($podCodeUpper === 'FNA') {
                $selectedAdminId = $admin125Id;
                $selectedAdminKey = 'admin_125';
            } elseif ($podCodeUpper === 'DKR' && $commodityTypeNormalized === 'LM Cargo') {
                $selectedAdminId = $admin100Id;
                $selectedAdminKey = 'admin_100';
            } else {
                // Default to Admin 75
                $selectedAdminId = $admin75Id;
                $selectedAdminKey = 'admin_75';
            }

            // Step 5: Attach the selected admin article
            if ($selectedAdminId) {
                // Check if relationship already exists (shouldn't, but just in case)
                $exists = DB::table('article_children')
                    ->where('parent_article_id', $parent->id)
                    ->where('child_article_id', $selectedAdminId)
                    ->exists();

                if (!$exists) {
                    // Get the next sort_order for this parent
                    $maxSortOrder = DB::table('article_children')
                        ->where('parent_article_id', $parent->id)
                        ->max('sort_order') ?? 0;

                    DB::table('article_children')->insert([
                        'parent_article_id' => $parent->id,
                        'child_article_id' => $selectedAdminId,
                        'sort_order' => $maxSortOrder + 1,
                        'is_required' => true,
                        'is_conditional' => false, // Not conditional anymore - it's the correct one
                        'child_type' => 'mandatory', // Mandatory since it's the correct admin for this parent
                        'conditions' => null, // No conditions needed - it's the correct one
                        'cost_type' => 'Service',
                        'default_quantity' => 1.0,
                        'default_cost_price' => null,
                        'unit_type' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $adminAttachments[$selectedAdminKey]++;
                }
            }
        }

        Log::info('Completed admin article attachment migration', [
            'parent_articles_processed' => $parentArticles->count(),
            'pod_codes_populated' => $podCodeUpdated,
            'commodity_types_populated' => $commodityTypeUpdated,
            'admin_attachments' => $adminAttachments,
            'total_attachments' => array_sum($adminAttachments),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Find the admin articles
        $adminArticleIds = DB::table('robaws_articles_cache')
            ->whereIn('article_name', [
                'Admin 75',
                'Admin 100',
                'Admin 110',
                'Admin', // Admin 115
                'Admin 125'
            ])
            ->pluck('id')
            ->toArray();

        // Remove all relationships for these admin articles
        $deleted = DB::table('article_children')
            ->whereIn('child_article_id', $adminArticleIds)
            ->delete();

        Log::info('Removed admin article attachments (rollback)', [
            'relationships_deleted' => $deleted
        ]);
    }

    /**
     * Normalize commodity type to match the format used in conditions
     * 
     * @param string|null $commodityType
     * @return string|null
     */
    private function normalizeCommodityType(?string $commodityType): ?string
    {
        if (empty($commodityType)) {
            return null;
        }

        $normalized = strtolower(trim($commodityType));
        
        // Map to the format used in conditions ("LM Cargo")
        $typeMap = [
            'lm cargo' => 'LM Cargo',
            'lm' => 'LM Cargo',
            'lane meter' => 'LM Cargo',
            'lanemeter' => 'LM Cargo',
        ];

        return $typeMap[$normalized] ?? $commodityType;
    }
};
