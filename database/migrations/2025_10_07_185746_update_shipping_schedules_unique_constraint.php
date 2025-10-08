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
        Schema::table('shipping_schedules', function (Blueprint $table) {
            // Drop the old unique constraint
            $table->dropUnique(['carrier_id', 'pol_id', 'pod_id', 'service_name']);
            
            // Add new unique constraint that includes vessel_name and voyage_number to allow multiple schedules per route
            $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name', 'voyage_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_schedules', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique(['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name', 'voyage_number']);
            
            // Restore the old unique constraint
            $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name']);
        });
    }
};