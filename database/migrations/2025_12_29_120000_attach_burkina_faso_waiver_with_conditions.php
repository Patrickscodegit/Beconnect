<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find the Burkina Faso waiver article
        $waiverArticle = DB::table('robaws_articles_cache')
            ->where('article_name', 'Waiver Burkina Faso')
            ->first();

        if (!$waiverArticle) {
            Log::warning('Burkina Faso waiver article not found in robaws_articles_cache');
            return;
        }

        $waiverArticleId = $waiverArticle->id;

        // Find all parent articles (seafreight articles)
        $parentArticles = DB::table('robaws_articles_cache')
            ->where('is_parent_article', true)
            ->pluck('id')
            ->toArray();

        // Condition JSON: only show if in_transit_to = "Burkina Faso"
        $conditions = json_encode([
            'in_transit_to' => ['Burkina Faso']
        ]);

        // Attach waiver to each parent article
        foreach ($parentArticles as $parentId) {
            // Check if relationship already exists
            $exists = DB::table('article_children')
                ->where('parent_article_id', $parentId)
                ->where('child_article_id', $waiverArticleId)
                ->exists();

            if (!$exists) {
                // Get the next sort_order for this parent
                $maxSortOrder = DB::table('article_children')
                    ->where('parent_article_id', $parentId)
                    ->max('sort_order') ?? 0;

                DB::table('article_children')->insert([
                    'parent_article_id' => $parentId,
                    'child_article_id' => $waiverArticleId,
                    'sort_order' => $maxSortOrder + 1,
                    'is_required' => false,
                    'is_conditional' => true,
                    'child_type' => 'conditional',
                    'conditions' => $conditions,
                    'cost_type' => 'Service',
                    'default_quantity' => 1.0,
                    'default_cost_price' => null,
                    'unit_type' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Update existing relationship to ensure correct conditions
                DB::table('article_children')
                    ->where('parent_article_id', $parentId)
                    ->where('child_article_id', $waiverArticleId)
                    ->update([
                        'is_conditional' => true,
                        'child_type' => 'conditional',
                        'conditions' => $conditions,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Find the Burkina Faso waiver article
        $waiverArticle = DB::table('robaws_articles_cache')
            ->where('article_name', 'Waiver Burkina Faso')
            ->first();

        if (!$waiverArticle) {
            return;
        }

        $waiverArticleId = $waiverArticle->id;

        // Remove all relationships for this waiver article
        DB::table('article_children')
            ->where('child_article_id', $waiverArticleId)
            ->delete();
    }
};

