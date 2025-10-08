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
            // Drop the old unique constraint that prevented multiple voyages
            $table->dropUnique(['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name']);

            // Add new unique constraint that includes ETS (sailing date)
            // This allows the same vessel to have multiple voyages on the same route
            // Each voyage is uniquely identified by its sailing date
            $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name', 'ets_pol'], 'shipping_schedules_unique_voyage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_schedules', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique('shipping_schedules_unique_voyage');

            // Restore the old unique constraint (note: this may fail if duplicate data exists)
            $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name']);
        });
    }
};

