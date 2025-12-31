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
        $driver = DB::getDriverName();
        $useJsonb = $driver === 'pgsql';

        // carrier_acceptance_rules
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_ids')->nullable();
                $table->jsonb('vehicle_categories')->nullable();
                $table->jsonb('vessel_names')->nullable();
                $table->jsonb('vessel_classes')->nullable();
            } else {
                $table->json('port_ids')->nullable();
                $table->json('vehicle_categories')->nullable();
                $table->json('vessel_names')->nullable();
                $table->json('vessel_classes')->nullable();
            }
        });

        // carrier_transform_rules
        Schema::table('carrier_transform_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_ids')->nullable();
                $table->jsonb('vehicle_categories')->nullable();
                $table->jsonb('vessel_names')->nullable();
                $table->jsonb('vessel_classes')->nullable();
            } else {
                $table->json('port_ids')->nullable();
                $table->json('vehicle_categories')->nullable();
                $table->json('vessel_names')->nullable();
                $table->json('vessel_classes')->nullable();
            }
        });

        // carrier_surcharge_rules
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_ids')->nullable();
                $table->jsonb('vehicle_categories')->nullable();
                $table->jsonb('vessel_names')->nullable();
                $table->jsonb('vessel_classes')->nullable();
            } else {
                $table->json('port_ids')->nullable();
                $table->json('vehicle_categories')->nullable();
                $table->json('vessel_names')->nullable();
                $table->json('vessel_classes')->nullable();
            }
        });

        // carrier_surcharge_article_maps
        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_ids')->nullable();
                $table->jsonb('vehicle_categories')->nullable();
                $table->jsonb('vessel_names')->nullable();
                $table->jsonb('vessel_classes')->nullable();
            } else {
                $table->json('port_ids')->nullable();
                $table->json('vehicle_categories')->nullable();
                $table->json('vessel_names')->nullable();
                $table->json('vessel_classes')->nullable();
            }
        });

        // Create GIN indexes for PostgreSQL only
        if ($useJsonb) {
            // carrier_acceptance_rules
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_acceptance_rules_port_ids_gin ON carrier_acceptance_rules USING GIN (port_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_acceptance_rules_vehicle_categories_gin ON carrier_acceptance_rules USING GIN (vehicle_categories)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_acceptance_rules_vessel_names_gin ON carrier_acceptance_rules USING GIN (vessel_names)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_acceptance_rules_vessel_classes_gin ON carrier_acceptance_rules USING GIN (vessel_classes)');

            // carrier_transform_rules
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_transform_rules_port_ids_gin ON carrier_transform_rules USING GIN (port_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_transform_rules_vehicle_categories_gin ON carrier_transform_rules USING GIN (vehicle_categories)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_transform_rules_vessel_names_gin ON carrier_transform_rules USING GIN (vessel_names)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_transform_rules_vessel_classes_gin ON carrier_transform_rules USING GIN (vessel_classes)');

            // carrier_surcharge_rules
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_rules_port_ids_gin ON carrier_surcharge_rules USING GIN (port_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_rules_vehicle_categories_gin ON carrier_surcharge_rules USING GIN (vehicle_categories)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_rules_vessel_names_gin ON carrier_surcharge_rules USING GIN (vessel_names)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_rules_vessel_classes_gin ON carrier_surcharge_rules USING GIN (vessel_classes)');

            // carrier_surcharge_article_maps
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_article_maps_port_ids_gin ON carrier_surcharge_article_maps USING GIN (port_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_article_maps_vehicle_categories_gin ON carrier_surcharge_article_maps USING GIN (vehicle_categories)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_article_maps_vessel_names_gin ON carrier_surcharge_article_maps USING GIN (vessel_names)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_article_maps_vessel_classes_gin ON carrier_surcharge_article_maps USING GIN (vessel_classes)');
        }

        // Data migration: Copy legacy single values to arrays
        // Use a cross-database approach: fetch and update in PHP
        
        $migrateTableData = function ($tableName, $hasVehicleCategory = false) {
            DB::table($tableName)
                ->get()
                ->each(function ($record) use ($tableName, $hasVehicleCategory) {
                    $updates = [];
                    
                    // Helper function to safely decode JSON (handles both strings and already-decoded arrays)
                    $safeJsonDecode = function ($value) {
                        if ($value === null || $value === 'null') {
                            return [];
                        }
                        // If already an array, return as-is
                        if (is_array($value)) {
                            return $value;
                        }
                        // If string, try to decode
                        if (is_string($value)) {
                            return json_decode($value, true) ?? [];
                        }
                        return [];
                    };
                    
                    // Port IDs: migrate if array is NULL or empty AND legacy port_id exists
                    $portIds = $safeJsonDecode($record->port_ids);
                    if (empty($portIds) && !empty($record->port_id)) {
                        $updates['port_ids'] = json_encode([$record->port_id]);
                    }
                    
                    // Vehicle Categories (if applicable)
                    if ($hasVehicleCategory) {
                        $vehicleCategories = $safeJsonDecode($record->vehicle_categories ?? null);
                        if (empty($vehicleCategories) && !empty($record->vehicle_category)) {
                            $updates['vehicle_categories'] = json_encode([$record->vehicle_category]);
                        }
                    }
                    
                    // Vessel Names
                    $vesselNames = $safeJsonDecode($record->vessel_names ?? null);
                    if (empty($vesselNames) && !empty($record->vessel_name)) {
                        $updates['vessel_names'] = json_encode([$record->vessel_name]);
                    }
                    
                    // Vessel Classes
                    $vesselClasses = $safeJsonDecode($record->vessel_classes ?? null);
                    if (empty($vesselClasses) && !empty($record->vessel_class)) {
                        $updates['vessel_classes'] = json_encode([$record->vessel_class]);
                    }
                    
                    if (!empty($updates)) {
                        DB::table($tableName)
                            ->where('id', $record->id)
                            ->update($updates);
                    }
                });
        };

        // Migrate data for all tables
        $migrateTableData('carrier_acceptance_rules', true);
        $migrateTableData('carrier_transform_rules', true);
        $migrateTableData('carrier_surcharge_rules', true);
        $migrateTableData('carrier_surcharge_article_maps', true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) {
            $table->dropColumn(['port_ids', 'vehicle_categories', 'vessel_names', 'vessel_classes']);
        });

        Schema::table('carrier_transform_rules', function (Blueprint $table) {
            $table->dropColumn(['port_ids', 'vehicle_categories', 'vessel_names', 'vessel_classes']);
        });

        Schema::table('carrier_surcharge_rules', function (Blueprint $table) {
            $table->dropColumn(['port_ids', 'vehicle_categories', 'vessel_names', 'vessel_classes']);
        });

        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) {
            $table->dropColumn(['port_ids', 'vehicle_categories', 'vessel_names', 'vessel_classes']);
        });

        // Drop GIN indexes for PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS carrier_acceptance_rules_port_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_acceptance_rules_vehicle_categories_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_acceptance_rules_vessel_names_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_acceptance_rules_vessel_classes_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_transform_rules_port_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_transform_rules_vehicle_categories_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_transform_rules_vessel_names_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_transform_rules_vessel_classes_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_rules_port_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_rules_vehicle_categories_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_rules_vessel_names_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_rules_vessel_classes_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_article_maps_port_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_article_maps_vehicle_categories_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_article_maps_vessel_names_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_article_maps_vessel_classes_gin');
        }
    }
};
