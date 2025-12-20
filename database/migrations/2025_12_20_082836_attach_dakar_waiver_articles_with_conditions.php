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
        // Find Dakar parent articles (seafreight articles with POD = DKR)
        $dakarParentIds = DB::table('robaws_articles_cache')
            ->where(function ($query) {
                $query->where('pod', 'LIKE', '%Dakar%')
                      ->orWhere('pod', 'LIKE', '%DKR%')
                      ->orWhere('pod_code', 'DKR');
            })
            ->where('is_parent_article', true)
            ->pluck('id')
            ->toArray();

        // Find the three Dakar waiver articles
        $waiverArticleIds = DB::table('robaws_articles_cache')
            ->whereIn('article_name', [
                'FCL - Waiver (BESC) Dakar - Senegal',
                'Waiver (BESC) Dakar - Senegal c/sv/bv',
                'Waiver (BESC) Dakar - Senegal LM'
            ])
            ->pluck('id')
            ->toArray();

        // Condition JSON: only show if POD is DKR and in_transit_to is empty
        $conditions = json_encode([
            'route' => [
                'pod' => ['DKR']
            ],
            'in_transit_to_empty' => true
        ]);

        // Attach each waiver article to each Dakar parent article
        foreach ($dakarParentIds as $parentId) {
            foreach ($waiverArticleIds as $waiverId) {
                // Check if relationship already exists
                $exists = DB::table('article_children')
                    ->where('parent_article_id', $parentId)
                    ->where('child_article_id', $waiverId)
                    ->exists();

                if (!$exists) {
                    // Get the next sort_order for this parent
                    $maxSortOrder = DB::table('article_children')
                        ->where('parent_article_id', $parentId)
                        ->max('sort_order') ?? 0;

                    DB::table('article_children')->insert([
                        'parent_article_id' => $parentId,
                        'child_article_id' => $waiverId,
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
                    // Update existing relationship to set conditional type and conditions
                    DB::table('article_children')
                        ->where('parent_article_id', $parentId)
                        ->where('child_article_id', $waiverId)
                        ->update([
                            'is_conditional' => true,
                            'child_type' => 'conditional',
                            'conditions' => $conditions,
                            'updated_at' => now(),
                        ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Find the three Dakar waiver articles
        $waiverArticleIds = DB::table('robaws_articles_cache')
            ->whereIn('article_name', [
                'FCL - Waiver (BESC) Dakar - Senegal',
                'Waiver (BESC) Dakar - Senegal c/sv/bv',
                'Waiver (BESC) Dakar - Senegal LM'
            ])
            ->pluck('id')
            ->toArray();

        // Remove all relationships for these waiver articles
        DB::table('article_children')
            ->whereIn('child_article_id', $waiverArticleIds)
            ->delete();
    }
};
