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

        // Add port_group_ids to carrier_acceptance_rules
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_group_ids')->nullable()->after('port_ids');
            } else {
                $table->json('port_group_ids')->nullable()->after('port_ids');
            }
        });
        
        // Add port_group_ids to carrier_transform_rules
        Schema::table('carrier_transform_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_group_ids')->nullable()->after('port_ids');
            } else {
                $table->json('port_group_ids')->nullable()->after('port_ids');
            }
        });
        
        // Add port_group_ids to carrier_surcharge_rules
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_group_ids')->nullable()->after('port_ids');
            } else {
                $table->json('port_group_ids')->nullable()->after('port_ids');
            }
        });
        
        // Add port_group_ids to carrier_surcharge_article_maps
        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) use ($useJsonb) {
            if ($useJsonb) {
                $table->jsonb('port_group_ids')->nullable()->after('port_ids');
            } else {
                $table->json('port_group_ids')->nullable()->after('port_ids');
            }
        });
        
        // Create GIN indexes for PostgreSQL only
        if ($useJsonb) {
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_acceptance_rules_port_group_ids_gin ON carrier_acceptance_rules USING GIN (port_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_transform_rules_port_group_ids_gin ON carrier_transform_rules USING GIN (port_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_rules_port_group_ids_gin ON carrier_surcharge_rules USING GIN (port_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_surcharge_article_maps_port_group_ids_gin ON carrier_surcharge_article_maps USING GIN (port_group_ids)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS carrier_acceptance_rules_port_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_transform_rules_port_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_rules_port_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_surcharge_article_maps_port_group_ids_gin');
        }
        
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) {
            $table->dropColumn('port_group_ids');
        });
        
        Schema::table('carrier_transform_rules', function (Blueprint $table) {
            $table->dropColumn('port_group_ids');
        });
        
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) {
            $table->dropColumn('port_group_ids');
        });
        
        Schema::table('carrier_surcharge_article_maps', function (Blueprint $table) {
            $table->dropColumn('port_group_ids');
        });
    }
};
