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

        // Find admin articles dynamically (works in both local and production)
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
            \Log::error('Could not find all admin articles in migration', [
                'admin_75' => $admin75Id ?: 'NOT FOUND',
                'admin_100' => $admin100Id ?: 'NOT FOUND',
                'admin_110' => $admin110Id ?: 'NOT FOUND',
                'admin_115' => $admin115Id ?: 'NOT FOUND',
                'admin_125' => $admin125Id ?: 'NOT FOUND',
                'admin75_object' => $admin75 ? 'EXISTS' : 'NULL',
                'admin100_object' => $admin100 ? 'EXISTS' : 'NULL',
                'admin110_object' => $admin110 ? 'EXISTS' : 'NULL',
                'admin115_object' => $admin115 ? 'EXISTS' : 'NULL',
                'admin125_object' => $admin125 ? 'EXISTS' : 'NULL',
            ]);
            throw new \Exception('Could not find all required admin articles. Please verify admin articles exist in robaws_articles_cache table.');
        }

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
