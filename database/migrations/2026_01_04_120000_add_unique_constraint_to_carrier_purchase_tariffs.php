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

