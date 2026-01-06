<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find all parent articles
        $parentArticles = DB::table('robaws_articles_cache')
            ->where('is_parent_article', true)
            ->pluck('id')
            ->toArray();

        // Helper function to find or create admin article
        $findOrCreateAdminArticle = function($articleName, $unitPrice, $robawsArticleIdSuffix) {
            $article = DB::table('robaws_articles_cache')
                ->where('article_name', $articleName)
                ->where('unit_price', $unitPrice)
                ->first();

            if ($article) {
                return $article->id;
            }

            // Generate unique robaws_article_id
            $baseRobawsId = 'ADMIN_' . str_replace(' ', '_', strtoupper($articleName)) . '_' . $unitPrice;
            $robawsArticleId = $baseRobawsId;
            $counter = 1;
            
            // Ensure unique robaws_article_id
            while (DB::table('robaws_articles_cache')->where('robaws_article_id', $robawsArticleId)->exists()) {
                $robawsArticleId = $baseRobawsId . '_' . $counter;
                $counter++;
            }

            // Create missing admin article
            $articleId = DB::table('robaws_articles_cache')->insertGetId([
                'robaws_article_id' => $robawsArticleId,
                'article_name' => $articleName,
                'description' => $articleName . ' fee',
                'category' => 'general',
                'unit_price' => $unitPrice,
                'currency' => 'EUR',
                'is_parent_article' => false,
                'is_surcharge' => true,
                'is_active' => true,
                'requires_manual_review' => false,
                'min_quantity' => 1,
                'max_quantity' => 1,
                'last_synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            \Log::info('Created missing admin article in migration', [
                'article_name' => $articleName,
                'unit_price' => $unitPrice,
                'article_id' => $articleId,
                'robaws_article_id' => $robawsArticleId,
            ]);

            return $articleId;
        };

        // Find or create admin articles
        $admin75Id = $findOrCreateAdminArticle('Admin 75', 75, '75');
        $admin100Id = $findOrCreateAdminArticle('Admin 100', 100, '100');
        $admin110Id = $findOrCreateAdminArticle('Admin 110', 110, '110');
        $admin115Id = $findOrCreateAdminArticle('Admin', 115, '115');
        $admin125Id = $findOrCreateAdminArticle('Admin 125', 125, '125');

        // Define conditions for each admin article
        $conditions = [
            'admin_110' => json_encode([
                'route' => ['pod' => ['PNR']]
            ]),
            'admin_115' => json_encode([
                'route' => ['pod' => ['LAD']]
            ]),
            'admin_125' => json_encode([
                'route' => ['pod' => ['FNA']]
            ]),
            'admin_100' => json_encode([
                'route' => ['pod' => ['DKR']],
                'commodity' => ['LM Cargo']
            ]),
            'admin_75' => json_encode([]), // Empty conditions = always matches if no other matches
        ];

        $adminMapping = [
            'admin_110' => $admin110Id,
            'admin_115' => $admin115Id,
            'admin_125' => $admin125Id,
            'admin_100' => $admin100Id,
            'admin_75' => $admin75Id,
        ];

        $attachedCount = 0;

        // Attach each admin article to all parent articles
        foreach ($adminMapping as $adminKey => $adminId) {
            $conditionJson = $conditions[$adminKey];

            foreach ($parentArticles as $parentId) {
                // Check if relationship already exists
                $exists = DB::table('article_children')
                    ->where('parent_article_id', $parentId)
                    ->where('child_article_id', $adminId)
                    ->exists();

                if (!$exists) {
                    // Get the next sort_order for this parent
                    $maxSortOrder = DB::table('article_children')
                        ->where('parent_article_id', $parentId)
                        ->max('sort_order') ?? 0;

                    DB::table('article_children')->insert([
                        'parent_article_id' => $parentId,
                        'child_article_id' => $adminId,
                        'sort_order' => $maxSortOrder + 1,
                        'is_required' => true,
                        'is_conditional' => true,
                        'child_type' => 'conditional',
                        'conditions' => $conditionJson,
                        'cost_type' => 'Service',
                        'default_quantity' => 1.0,
                        'default_cost_price' => null,
                        'unit_type' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $attachedCount++;
                } else {
                    // Update existing relationship to ensure correct conditions
                    DB::table('article_children')
                        ->where('parent_article_id', $parentId)
                        ->where('child_article_id', $adminId)
                        ->update([
                            'is_required' => true,
                            'is_conditional' => true,
                            'child_type' => 'conditional',
                            'conditions' => $conditionJson,
                            'default_quantity' => 1.0,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        \Log::info('Attached conditional admin articles to all parent articles', [
            'parent_count' => count($parentArticles),
            'admin_articles' => 5,
            'relationships_created' => $attachedCount,
            'total_relationships' => count($parentArticles) * 5,
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

        \Log::info('Removed admin article attachments', [
            'relationships_deleted' => $deleted
        ]);
    }
};
