<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('article_id');
        });

        // Set sort_order based on current id order (preserve existing order)
        DB::statement('UPDATE carrier_surcharge_article_maps SET sort_order = id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
