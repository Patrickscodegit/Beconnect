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
        // Mark "Waiver Burkina Faso" as hinterland waiver
        $waiver = DB::table('robaws_articles_cache')
            ->where('article_name', 'Waiver Burkina Faso')
            ->first();

        if ($waiver) {
            DB::table('robaws_articles_cache')
                ->where('id', $waiver->id)
                ->update(['is_hinterland_waiver' => true]);
            
            Log::info('Marked Waiver Burkina Faso as hinterland waiver', [
                'article_id' => $waiver->id,
            ]);
        } else {
            Log::warning('Waiver Burkina Faso not found when marking as hinterland waiver');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Unmark hinterland waivers
        DB::table('robaws_articles_cache')
            ->where('is_hinterland_waiver', true)
            ->update(['is_hinterland_waiver' => false]);
    }
};

