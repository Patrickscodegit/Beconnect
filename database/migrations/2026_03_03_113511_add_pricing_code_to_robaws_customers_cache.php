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
        Schema::table('robaws_customers_cache', function (Blueprint $table) {
            $table->string('pricing_code', 1)->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_customers_cache', function (Blueprint $table) {
            $table->dropColumn('pricing_code');
        });
    }
};
