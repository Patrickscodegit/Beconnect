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
        Schema::table('quotation_request_articles', function (Blueprint $table) {
            if (!Schema::hasColumn('quotation_request_articles', 'unit_type')) {
                $table->string('unit_type', 50)->default('unit')->after('quantity');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_request_articles', function (Blueprint $table) {
            if (Schema::hasColumn('quotation_request_articles', 'unit_type')) {
                $table->dropColumn('unit_type');
            }
        });
    }
};
