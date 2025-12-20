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
        // Update all articles with "Seafreight" in name to be parent articles
        // Exclude surcharges (is_surcharge = true)
        $updated = DB::table('robaws_articles_cache')
            ->where('article_name', 'LIKE', '%Seafreight%')
            ->where('is_parent_article', false)
            ->where('is_surcharge', false)
            ->update([
                'is_parent_article' => true,
                'updated_at' => now(),
            ]);

        \Log::info('Fixed seafreight articles parent status', [
            'articles_updated' => $updated
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert: Set is_parent_article back to false for articles that were updated
        // Note: This is a best-effort revert - we can't perfectly restore the original state
        // since we don't know which articles were originally parent articles
        // We'll only revert articles that have "Seafreight" in name and are not surcharges
        // This may revert some that were originally parent articles, but it's the best we can do
        
        // Get current parent count before revert
        $currentParents = DB::table('robaws_articles_cache')
            ->where('is_parent_article', true)
            ->count();

        // Revert all seafreight articles that aren't surcharges
        $reverted = DB::table('robaws_articles_cache')
            ->where('article_name', 'LIKE', '%Seafreight%')
            ->where('is_parent_article', true)
            ->where('is_surcharge', false)
            ->update([
                'is_parent_article' => false,
                'updated_at' => now(),
            ]);

        \Log::info('Reverted seafreight articles parent status', [
            'articles_reverted' => $reverted,
            'remaining_parents' => $currentParents - $reverted
        ]);
    }
};
