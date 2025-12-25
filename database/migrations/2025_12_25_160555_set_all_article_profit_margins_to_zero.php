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
        // Set all article profit margins to empty object (will use config default of 0%)
        // This clears any article-specific margins so they all use the config default
        DB::table('robaws_articles_cache')
            ->whereNotNull('profit_margins')
            ->update(['profit_margins' => json_encode([])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse - profit_margins were cleared, original values unknown
        // Leave empty
    }
};
