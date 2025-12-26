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
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            $table->decimal('chargeable_lm', 10, 4)->nullable()->after('lm');
            $table->json('carrier_rule_meta')->nullable()->after('chargeable_lm'); // Store validation status, violations, approvals, applied rules, etc.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            $table->dropColumn(['chargeable_lm', 'carrier_rule_meta']);
        });
    }
};
