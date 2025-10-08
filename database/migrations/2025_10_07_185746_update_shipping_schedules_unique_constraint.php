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
            // Check if the old unique constraint exists before trying to drop it
            $oldIndexName = 'shipping_schedules_carrier_id_pol_id_pod_id_service_name_unique';
            
            if (Schema::hasIndex('shipping_schedules', $oldIndexName)) {
                $table->dropUnique(['carrier_id', 'pol_id', 'pod_id', 'service_name']);
            }
            
            // Add new unique constraint that includes vessel_name to allow multiple schedules per route
            // Note: voyage_number will be added in a later migration
            $newIndexName = 'shipping_schedules_carrier_id_pol_id_pod_id_service_name_vessel_name_unique';
            
            if (!Schema::hasIndex('shipping_schedules', $newIndexName)) {
                $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_schedules', function (Blueprint $table) {
            // Check if the new unique constraint exists before trying to drop it
            $newIndexName = 'shipping_schedules_carrier_id_pol_id_pod_id_service_name_vessel_name_unique';
            
            if (Schema::hasIndex('shipping_schedules', $newIndexName)) {
                $table->dropUnique(['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name']);
            }
            
            // Restore the old unique constraint only if it doesn't exist
            $oldIndexName = 'shipping_schedules_carrier_id_pol_id_pod_id_service_name_unique';
            
            if (!Schema::hasIndex('shipping_schedules', $oldIndexName)) {
                $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name']);
            }
        });
    }
};