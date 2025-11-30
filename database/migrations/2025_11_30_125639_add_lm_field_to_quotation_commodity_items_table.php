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
            $table->decimal('lm', 10, 4)->nullable()->after('cbm')->comment('Linear Meter - Auto-calculated from (length × width) / 2.5');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            $table->dropColumn('lm');
        });
    }
};
