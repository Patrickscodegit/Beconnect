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
            // Add helper columns for multi-commodity system
            $table->integer('total_commodity_items')->default(0)->after('commodity_type');
            $table->text('robaws_cargo_field')->nullable()->after('total_commodity_items'); // Generated CARGO field
            $table->text('robaws_dim_field')->nullable()->after('robaws_cargo_field'); // Generated DIM_BEF_DELIVERY field
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->dropColumn(['total_commodity_items', 'robaws_cargo_field', 'robaws_dim_field']);
        });
    }
};
