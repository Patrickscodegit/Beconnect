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
                $table->jsonb('category_group_ids')->nullable()->after('category_group_id');
            } else {
                $table->json('category_group_ids')->nullable()->after('category_group_id');
            }
        });

        // carrier_transform_rules
        Schema::table('carrier_transform_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('category_group_ids')->nullable()->after('category_group_id');
            } else {
                $table->json('category_group_ids')->nullable()->after('category_group_id');
            }
        });

        // carrier_surcharge_rules
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('category_group_ids')->nullable()->after('category_group_id');
            } else {
                $table->json('category_group_ids')->nullable()->after('category_group_id');
            }
        });

        // carrier_surcharge_article_maps
        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('category_group_ids')->nullable()->after('category_group_id');
            } else {
                $table->json('category_group_ids')->nullable()->after('category_group_id');
            }
        });

        // Create GIN indexes for PostgreSQL only
        if ($useJsonb) {
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_acceptance_rules_category_group_ids_gin ON carrier_acceptance_rules USING GIN (category_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_transform_rules_category_group_ids_gin ON carrier_transform_rules USING GIN (category_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_rules_category_group_ids_gin ON carrier_surcharge_rules USING GIN (category_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_article_maps_category_group_ids_gin ON carrier_surcharge_article_maps USING GIN (category_group_ids)');
        }

        // Data migration: Copy existing category_group_id values to category_group_ids arrays
        $migrateTableData = function ($tableName) {
            DB::table($tableName)
                ->get()
                ->each(function ($record) use ($tableName) {
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
                    
                    // Category Group IDs: migrate if array is NULL or empty AND legacy category_group_id exists
                    $categoryGroupIds = $safeJsonDecode($record->category_group_ids ?? null);
                    if (empty($categoryGroupIds) && !empty($record->category_group_id)) {
                        $updates['category_group_ids'] = json_encode([(int)$record->category_group_id]);
                    }
                    
                    if (!empty($updates)) {
                        DB::table($tableName)
                            ->where('id', $record->id)
                            ->update($updates);
                    }
                });
        };

        // Migrate data for all tables
        $migrateTableData('carrier_acceptance_rules');
        $migrateTableData('carrier_transform_rules');
        $migrateTableData('carrier_surcharge_rules');
        $migrateTableData('carrier_surcharge_article_maps');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) {
            $table->dropColumn('category_group_ids');
        });

        Schema::table('carrier_transform_rules', function (Blueprint $table) {
            $table->dropColumn('category_group_ids');
        });

        Schema::table('carrier_surcharge_rules', function (Blueprint $table) {
            $table->dropColumn('category_group_ids');
        });

        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) {
            $table->dropColumn('category_group_ids');
        });

        // Drop GIN indexes for PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS carrier_acceptance_rules_category_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_transform_rules_category_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_rules_category_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_article_maps_category_group_ids_gin');
        }
    }
};
