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
            // Add year column after condition field (logical grouping with vehicle/machinery/boat fields)
            $table->integer('year')->nullable()->after('condition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            $table->dropColumn('year');
        });
    }
};
