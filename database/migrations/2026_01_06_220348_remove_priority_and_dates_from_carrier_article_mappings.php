<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carrier_article_mappings', function (Blueprint $table) {
            // Drop the composite index that includes priority
            // Laravel auto-generates index name as: carrier_article_mappings_carrier_id_is_active_priority_index
            $table->dropIndex(['carrier_id', 'is_active', 'priority']);
            
            // Drop columns
            $table->dropColumn(['priority', 'effective_from', 'effective_to']);
            
            // Recreate index without priority
            $table->index(['carrier_id', 'is_active'], 'carrier_article_mappings_carrier_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_article_mappings', function (Blueprint $table) {
            // Drop the new index
            $table->dropIndex('carrier_article_mappings_carrier_active_index');
            
            // Recreate columns
            $table->integer('priority')->default(0)->after('vessel_classes');
            $table->date('effective_from')->nullable()->after('priority');
            $table->date('effective_to')->nullable()->after('effective_from');
            
            // Recreate original index
            $table->index(['carrier_id', 'is_active', 'priority']);
        });
    }
};
