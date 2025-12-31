<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration fixes the issue where carrier_article_mappings table columns
     * were created as 'json' instead of 'jsonb' in PostgreSQL, which prevents GIN index creation.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        
        // Only needed for PostgreSQL
        if ($driver !== 'pgsql') {
            return;
        }

        // Check if table exists
        $tableExists = Schema::hasTable('carrier_article_mappings');
        
        if (!$tableExists) {
            // Table doesn't exist yet, the next migration run will create it correctly
            return;
        }

        $jsonColumns = [
            'port_ids',
            'port_group_ids',
            'vehicle_categories',
            'category_group_ids',
            'vessel_names',
            'vessel_classes',
        ];

        foreach ($jsonColumns as $columnName) {
            // Check if column exists and get its type
            $columnInfo = DB::selectOne("
                SELECT data_type 
                FROM information_schema.columns 
                WHERE table_name = 'carrier_article_mappings'
                AND column_name = ?
            ", [$columnName]);

            if ($columnInfo && $columnInfo->data_type === 'json') {
                // Convert json to jsonb
                DB::statement("ALTER TABLE carrier_article_mappings ALTER COLUMN {$columnName} TYPE jsonb USING {$columnName}::jsonb");
            }
        }

        // Create GIN indexes (they will be created if they don't exist)
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_port_ids_gin ON carrier_article_mappings USING GIN (port_ids)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_port_group_ids_gin ON carrier_article_mappings USING GIN (port_group_ids)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_vehicle_categories_gin ON carrier_article_mappings USING GIN (vehicle_categories)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_category_group_ids_gin ON carrier_article_mappings USING GIN (category_group_ids)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_vessel_names_gin ON carrier_article_mappings USING GIN (vessel_names)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_vessel_classes_gin ON carrier_article_mappings USING GIN (vessel_classes)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        
        if ($driver !== 'pgsql') {
            return;
        }

        // Check if table exists
        if (!Schema::hasTable('carrier_article_mappings')) {
            return;
        }

        // Drop indexes
        DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_port_ids_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_port_group_ids_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_vehicle_categories_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_category_group_ids_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_vessel_names_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_vessel_classes_gin');

        // Convert jsonb back to json (if needed)
        $jsonColumns = [
            'port_ids',
            'port_group_ids',
            'vehicle_categories',
            'category_group_ids',
            'vessel_names',
            'vessel_classes',
        ];

        foreach ($jsonColumns as $columnName) {
            $columnInfo = DB::selectOne("
                SELECT data_type 
                FROM information_schema.columns 
                WHERE table_name = 'carrier_article_mappings'
                AND column_name = ?
            ", [$columnName]);

            if ($columnInfo && $columnInfo->data_type === 'jsonb') {
                DB::statement("ALTER TABLE carrier_article_mappings ALTER COLUMN {$columnName} TYPE json USING {$columnName}::json");
            }
        }
    }
};
