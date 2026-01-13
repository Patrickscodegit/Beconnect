<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Copy article_id from carrier_surcharge_article_maps to carrier_surcharge_rules
     * Match by event_code and carrier_id
     */
    public function up(): void
    {
        // Match article maps to rules by event_code and carrier_id
        // If multiple maps exist for same event_code, use first (most specific would require more complex logic)
        DB::statement("
            UPDATE carrier_surcharge_rules AS rules
            SET article_id = (
                SELECT article_id
                FROM carrier_surcharge_article_maps AS maps
                WHERE maps.event_code = rules.event_code
                  AND maps.carrier_id = rules.carrier_id
                  AND maps.is_active = true
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1
                FROM carrier_surcharge_article_maps AS maps
                WHERE maps.event_code = rules.event_code
                  AND maps.carrier_id = rules.carrier_id
                  AND maps.is_active = true
            )
        ");
    }

    /**
     * Reverse the migrations.
     * Note: This cannot restore article maps, only clear article_id from rules
     */
    public function down(): void
    {
        DB::table('carrier_surcharge_rules')->update(['article_id' => null]);
    }
};
