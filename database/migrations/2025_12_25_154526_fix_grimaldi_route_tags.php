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
        // Fix Grimaldi's route tags - change from mediterranean_routes to africa_routes
        // Grimaldi operates West Africa routes, not Mediterranean routes
        DB::table('shipping_carriers')
            ->where('code', 'GRIMALDI')
            ->update([
                'specialization' => json_encode([
                    'africa_routes' => true,
                    'vehicle_transportation' => true
                ])
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert Grimaldi's route tags back to mediterranean_routes
        DB::table('shipping_carriers')
            ->where('code', 'GRIMALDI')
            ->update([
                'specialization' => json_encode([
                    'mediterranean_routes' => true,
                    'vehicle_transportation' => true
                ])
            ]);
    }
};
