<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration fixes the issue where port_group_ids columns were created as 'json'
     * instead of 'jsonb' in PostgreSQL, which prevents GIN index creation.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        
        // Only needed for PostgreSQL
        if ($driver !== 'pgsql') {
            return;
        }

        $tables = [
            'carrier_acceptance_rules',
            'carrier_transform_rules',
            'carrier_surcharge_rules',
            'carrier_surcharge_article_maps',
        ];

        foreach ($tables as $tableName) {
            // Check if column exists and get its type
            $columnInfo = DB::selectOne("
                SELECT data_type 
                FROM information_schema.columns 
                WHERE table_name = ? 
                AND column_name = 'port_group_ids'
            ", [$tableName]);

            if ($columnInfo && $columnInfo->data_type === 'json') {
                // Convert json to jsonb
                DB::statement("ALTER TABLE {$tableName} ALTER COLUMN port_group_ids TYPE jsonb USING port_group_ids::jsonb");
            }
        }

        // Create GIN indexes (they will be created if they don't exist)
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_acceptance_rules_port_group_ids_gin ON carrier_acceptance_rules USING GIN (port_group_ids)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_transform_rules_port_group_ids_gin ON carrier_transform_rules USING GIN (port_group_ids)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_rules_port_group_ids_gin ON carrier_surcharge_rules USING GIN (port_group_ids)');
        DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_article_maps_port_group_ids_gin ON carrier_surcharge_article_maps USING GIN (port_group_ids)');
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

        // Drop indexes
        DB::statement('DROP INDEX IF EXISTS carrier_acceptance_rules_port_group_ids_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_transform_rules_port_group_ids_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_surcharge_rules_port_group_ids_gin');
        DB::statement('DROP INDEX IF EXISTS carrier_surcharge_article_maps_port_group_ids_gin');

        // Convert jsonb back to json (if needed)
        $tables = [
            'carrier_acceptance_rules',
            'carrier_transform_rules',
            'carrier_surcharge_rules',
            'carrier_surcharge_article_maps',
        ];

        foreach ($tables as $tableName) {
            $columnInfo = DB::selectOne("
                SELECT data_type 
                FROM information_schema.columns 
                WHERE table_name = ? 
                AND column_name = 'port_group_ids'
            ", [$tableName]);

            if ($columnInfo && $columnInfo->data_type === 'jsonb') {
                DB::statement("ALTER TABLE {$tableName} ALTER COLUMN port_group_ids TYPE json USING port_group_ids::json");
            }
        }
    }
};
