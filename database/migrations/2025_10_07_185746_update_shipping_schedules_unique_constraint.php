<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = 'shipping_schedules';
        $carrierFk = 'shipping_schedules_carrier_id_foreign';
        $polFk = 'shipping_schedules_pol_id_foreign';
        $podFk = 'shipping_schedules_pod_id_foreign';

        $foreignKeyExists = function (string $constraintName) use ($tableName): bool {
            return DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $tableName)
                ->where('CONSTRAINT_NAME', $constraintName)
                ->exists();
        };

        Schema::table($tableName, function (Blueprint $table) use ($foreignKeyExists, $carrierFk, $polFk, $podFk) {
            // Drop foreign keys that depend on the existing index (if present)
            if ($foreignKeyExists($carrierFk)) {
                $table->dropForeign($carrierFk);
            }
            if ($foreignKeyExists($polFk)) {
                $table->dropForeign($polFk);
            }
            if ($foreignKeyExists($podFk)) {
                $table->dropForeign($podFk);
            }
        });

        Schema::table($tableName, function (Blueprint $table) use ($foreignKeyExists, $carrierFk, $polFk, $podFk) {
            // Check if the old unique constraint exists before trying to drop it
            $oldIndexName = 'shipping_schedules_carrier_id_pol_id_pod_id_service_name_unique';
            
            if (Schema::hasIndex('shipping_schedules', $oldIndexName)) {
                $table->dropUnique(['carrier_id', 'pol_id', 'pod_id', 'service_name']);
            }
            
            // Add new unique constraint that includes vessel_name to allow multiple schedules per route
            // Note: voyage_number will be added in a later migration
            $newIndexName = 'shipping_schedules_route_vessel_unique';
            
            if (!Schema::hasIndex('shipping_schedules', $newIndexName)) {
                $table->unique(
                    ['carrier_id', 'pol_id', 'pod_id', 'service_name', 'vessel_name'],
                    $newIndexName
                );
            }

            // Re-add foreign keys after index updates (if missing)
            if (!$foreignKeyExists($carrierFk)) {
                $table->foreign('carrier_id')->references('id')->on('shipping_carriers');
            }
            if (!$foreignKeyExists($polFk)) {
                $table->foreign('pol_id')->references('id')->on('ports');
            }
            if (!$foreignKeyExists($podFk)) {
                $table->foreign('pod_id')->references('id')->on('ports');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = 'shipping_schedules';
        $carrierFk = 'shipping_schedules_carrier_id_foreign';
        $polFk = 'shipping_schedules_pol_id_foreign';
        $podFk = 'shipping_schedules_pod_id_foreign';

        $foreignKeyExists = function (string $constraintName) use ($tableName): bool {
            return DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $tableName)
                ->where('CONSTRAINT_NAME', $constraintName)
                ->exists();
        };

        Schema::table($tableName, function (Blueprint $table) use ($foreignKeyExists, $carrierFk, $polFk, $podFk) {
            // Drop foreign keys that depend on the existing index (if present)
            if ($foreignKeyExists($carrierFk)) {
                $table->dropForeign($carrierFk);
            }
            if ($foreignKeyExists($polFk)) {
                $table->dropForeign($polFk);
            }
            if ($foreignKeyExists($podFk)) {
                $table->dropForeign($podFk);
            }
        });

        Schema::table($tableName, function (Blueprint $table) use ($foreignKeyExists, $carrierFk, $polFk, $podFk) {
            // Check if the new unique constraint exists before trying to drop it
            $newIndexName = 'shipping_schedules_route_vessel_unique';
            
            if (Schema::hasIndex('shipping_schedules', $newIndexName)) {
                $table->dropUnique($newIndexName);
            }
            
            // Restore the old unique constraint only if it doesn't exist
            $oldIndexName = 'shipping_schedules_carrier_id_pol_id_pod_id_service_name_unique';
            
            if (!Schema::hasIndex('shipping_schedules', $oldIndexName)) {
                $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name']);
            }

            // Re-add foreign keys after index updates (if missing)
            if (!$foreignKeyExists($carrierFk)) {
                $table->foreign('carrier_id')->references('id')->on('shipping_carriers');
            }
            if (!$foreignKeyExists($polFk)) {
                $table->foreign('pol_id')->references('id')->on('ports');
            }
            if (!$foreignKeyExists($podFk)) {
                $table->foreign('pod_id')->references('id')->on('ports');
            }
        });
    }
};
