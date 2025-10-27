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
        Schema::table('quotation_requests', function (Blueprint $table) {
            // Add pricing_tier_id foreign key
            $table->foreignId('pricing_tier_id')
                  ->nullable()
                  ->after('customer_role')
                  ->constrained('pricing_tiers')
                  ->nullOnDelete()
                  ->comment('Selected pricing tier (A/B/C) determines margin %');
            
            // Add index for performance
            $table->index('pricing_tier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->dropForeign(['pricing_tier_id']);
            $table->dropIndex(['pricing_tier_id']);
            $table->dropColumn('pricing_tier_id');
        });
    }
};

