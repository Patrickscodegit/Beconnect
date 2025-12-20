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
        // Find Dakar parent articles with their commodity_type
        $dakarParents = DB::table('robaws_articles_cache')
            ->where(function ($query) {
                $query->where('pod', 'LIKE', '%Dakar%')
                      ->orWhere('pod', 'LIKE', '%DKR%')
                      ->orWhere('pod_code', 'DKR');
            })
            ->where('is_parent_article', true)
            ->select('id', 'commodity_type', 'article_name')
            ->get();

        // Find the three Dakar waiver articles
        $waivers = DB::table('robaws_articles_cache')
            ->whereIn('article_name', [
                'FCL - Waiver (BESC) Dakar - Senegal',
                'Waiver (BESC) Dakar - Senegal c/sv/bv',
                'Waiver (BESC) Dakar - Senegal LM'
            ])
            ->pluck('id', 'article_name')
            ->toArray();

        $fclWaiverId = $waivers['FCL - Waiver (BESC) Dakar - Senegal'] ?? null;
        $csvbvWaiverId = $waivers['Waiver (BESC) Dakar - Senegal c/sv/bv'] ?? null;
        $lmWaiverId = $waivers['Waiver (BESC) Dakar - Senegal LM'] ?? null;

        // Condition JSON: only show if POD is DKR and in_transit_to is empty
        $conditions = json_encode([
            'route' => [
                'pod' => ['DKR']
            ],
            'in_transit_to_empty' => true
        ]);

        // Process each Dakar parent article
        foreach ($dakarParents as $parent) {
            $parentId = $parent->id;
            $commodityType = $parent->commodity_type;
            
            // Determine which waiver should be attached based on commodity_type
            $waiverToAttach = null;
            $waiversToDetach = [];

            if (in_array($commodityType, ['Car', 'Small Van', 'Big Van'])) {
                // Car, Small Van, Big Van → attach c/sv/bv waiver
                $waiverToAttach = $csvbvWaiverId;
                $waiversToDetach = [$lmWaiverId, $fclWaiverId];
            } elseif ($commodityType === 'LM Cargo') {
                // LM Cargo → attach LM waiver
                $waiverToAttach = $lmWaiverId;
                $waiversToDetach = [$csvbvWaiverId, $fclWaiverId];
            } elseif ($commodityType === null) {
                // NULL (e.g., STATIC Seafreight) → attach LM waiver
                $waiverToAttach = $lmWaiverId;
                $waiversToDetach = [$csvbvWaiverId, $fclWaiverId];
            } else {
                // Unknown commodity type - skip or handle as needed
                continue;
            }

            // Detach incorrect waivers
            if (!empty($waiversToDetach)) {
                DB::table('article_children')
                    ->where('parent_article_id', $parentId)
                    ->whereIn('child_article_id', array_filter($waiversToDetach))
                    ->delete();
            }

            // Attach or update the correct waiver
            if ($waiverToAttach) {
                $exists = DB::table('article_children')
                    ->where('parent_article_id', $parentId)
                    ->where('child_article_id', $waiverToAttach)
                    ->exists();

                if (!$exists) {
                    // Get the next sort_order for this parent
                    $maxSortOrder = DB::table('article_children')
                        ->where('parent_article_id', $parentId)
                        ->max('sort_order') ?? 0;

                    DB::table('article_children')->insert([
                        'parent_article_id' => $parentId,
                        'child_article_id' => $waiverToAttach,
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
                        ->where('child_article_id', $waiverToAttach)
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
        // Revert to original state: attach all 3 waivers to all Dakar parents
        // This is the same logic as the original migration
        
        // Find Dakar parent articles
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
};
