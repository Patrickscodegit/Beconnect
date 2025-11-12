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
        if (!Schema::hasTable('quotation_requests')) {
            return;
        }

        Schema::table('quotation_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('quotation_requests', 'pricing_tier_id')) {
                $table->foreignId('pricing_tier_id')
                    ->nullable()
                    ->after('customer_role')
                    ->constrained('pricing_tiers')
                    ->nullOnDelete()
                    ->comment('Selected pricing tier (A/B/C) determines margin %');

                $table->index('pricing_tier_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('quotation_requests') || !Schema::hasColumn('quotation_requests', 'pricing_tier_id')) {
            return;
        }

        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->dropForeign(['pricing_tier_id']);
            $table->dropIndex(['pricing_tier_id']);
            $table->dropColumn('pricing_tier_id');
        });
    }
};

