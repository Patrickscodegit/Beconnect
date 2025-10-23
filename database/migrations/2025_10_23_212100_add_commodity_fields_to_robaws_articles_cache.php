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
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Add commodity type field - extracted from Robaws "Type" field
            $table->string('commodity_type', 100)->nullable()->after('is_parent_item');
            
            // Add POD code field - extracted from POD field (e.g., "Dakar (DKR), Senegal" → "DKR")
            $table->string('pod_code', 10)->nullable()->after('pol_code');
            
            // Add indexes for efficient filtering
            $table->index('commodity_type', 'idx_articles_commodity');
            $table->index(['pol_code', 'pod_code'], 'idx_articles_pol_pod');
            
            // Composite index for smart article selection queries
            $table->index(
                ['is_parent_item', 'shipping_line', 'service_type', 'pol_code', 'pod_code', 'commodity_type'],
                'idx_articles_parent_match'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_articles_parent_match');
            $table->dropIndex('idx_articles_pol_pod');
            $table->dropIndex('idx_articles_commodity');
            
            // Drop columns
            $table->dropColumn(['commodity_type', 'pod_code']);
        });
    }
};
