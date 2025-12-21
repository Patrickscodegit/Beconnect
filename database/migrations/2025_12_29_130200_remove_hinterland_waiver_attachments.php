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
        // Find all hinterland waiver article IDs
        $hinterlandWaiverIds = DB::table('robaws_articles_cache')
            ->where('is_hinterland_waiver', true)
            ->pluck('id')
            ->toArray();

        if (empty($hinterlandWaiverIds)) {
            Log::info('No hinterland waivers found to remove attachments for');
            return;
        }

        // Count attachments before removal
        $countBefore = DB::table('article_children')
            ->whereIn('child_article_id', $hinterlandWaiverIds)
            ->count();

        // Remove all attachments for hinterland waivers
        $deleted = DB::table('article_children')
            ->whereIn('child_article_id', $hinterlandWaiverIds)
            ->delete();

        Log::info('Removed hinterland waiver attachments', [
            'waiver_ids' => $hinterlandWaiverIds,
            'attachments_removed' => $deleted,
            'count_before' => $countBefore,
        ]);
    }

    /**
     * Reverse the migrations.
     * Note: This will not restore the original attachments as we don't store
     * which parent articles they were attached to. The attachments would need
     * to be recreated manually if needed.
     */
    public function down(): void
    {
        Log::warning('Cannot automatically restore hinterland waiver attachments. They would need to be recreated manually.');
        // Intentionally left empty - attachments cannot be automatically restored
    }
};

