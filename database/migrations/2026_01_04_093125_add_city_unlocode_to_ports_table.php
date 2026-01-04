<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds city_unlocode column to ports table for canonical city grouping.
     * This enables multi-facility cities (e.g., Jeddah airport + seaport) to share the same city UN/LOCODE.
     */
    public function up(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->string('city_unlocode', 5)
                ->nullable()
                ->after('unlocode')
                ->comment('Canonical city UN/LOCODE for multi-facility cities');
        });

        // Add index for efficient lookups
        Schema::table('ports', function (Blueprint $table) {
            $table->index('city_unlocode');
        });

        // Backfill: set city_unlocode = unlocode where unlocode is not null
        DB::table('ports')
            ->whereNotNull('unlocode')
            ->where('unlocode', '!=', '')
            ->update([
                'city_unlocode' => DB::raw('unlocode')
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->dropIndex(['city_unlocode']);
            $table->dropColumn('city_unlocode');
        });
    }
};
