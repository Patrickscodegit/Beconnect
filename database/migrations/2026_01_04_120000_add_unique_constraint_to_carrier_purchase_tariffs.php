<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds unique constraint to prevent duplicate tariffs for the same
     * mapping and effective_from date.
     * 
     * This prevents the seeder from creating duplicates when run multiple times.
     */
    public function up(): void
    {
        // First, clean up duplicates by keeping only the most recent one for each (mapping_id, effective_from) pair
        $duplicates = \DB::select("
            SELECT carrier_article_mapping_id, effective_from, COUNT(*) as count
            FROM carrier_purchase_tariffs
            WHERE effective_from IS NOT NULL
            GROUP BY carrier_article_mapping_id, effective_from
            HAVING COUNT(*) > 1
        ");
        
        foreach ($duplicates as $dup) {
            // Keep the most recent tariff (highest ID) and delete the others
            \DB::statement("
                DELETE FROM carrier_purchase_tariffs
                WHERE id NOT IN (
                    SELECT MAX(id)
                    FROM carrier_purchase_tariffs
                    WHERE carrier_article_mapping_id = ? AND effective_from = ?
                )
                AND carrier_article_mapping_id = ? AND effective_from = ?
            ", [
                $dup->carrier_article_mapping_id,
                $dup->effective_from,
                $dup->carrier_article_mapping_id,
                $dup->effective_from
            ]);
        }
        
        Schema::table('carrier_purchase_tariffs', function (Blueprint $table) {
            // Add unique constraint on (carrier_article_mapping_id, effective_from)
            // This ensures only one tariff per mapping per effective date
            $table->unique(
                ['carrier_article_mapping_id', 'effective_from'],
                'unique_mapping_effective_from'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_purchase_tariffs', function (Blueprint $table) {
            $table->dropUnique('unique_mapping_effective_from');
        });
    }
};

