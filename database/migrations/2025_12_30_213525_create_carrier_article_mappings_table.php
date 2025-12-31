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
        $driver = Schema::getConnection()->getDriverName();
        $useJsonb = $driver === 'pgsql';
        
        Schema::create('carrier_article_mappings', function (Blueprint $table) use ($useJsonb) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->foreignId('article_id')->constrained('robaws_articles_cache')->onDelete('cascade');
            
            // Arrays-first design (no legacy single-value columns)
            // Use jsonb for PostgreSQL (required for GIN indexes), json for SQLite
            if ($useJsonb) {
                $table->jsonb('port_ids')->nullable();
                $table->jsonb('port_group_ids')->nullable();
                $table->jsonb('vehicle_categories')->nullable();
                $table->jsonb('category_group_ids')->nullable();
                $table->jsonb('vessel_names')->nullable();
                $table->jsonb('vessel_classes')->nullable();
            } else {
                $table->json('port_ids')->nullable();
                $table->json('port_group_ids')->nullable();
                $table->json('vehicle_categories')->nullable();
                $table->json('category_group_ids')->nullable();
                $table->json('vessel_names')->nullable();
                $table->json('vessel_classes')->nullable();
            }
            
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            
            $table->timestamps();
            
            // Standard indexes
            $table->index(['carrier_id', 'is_active', 'priority']);
            $table->index(['carrier_id', 'article_id', 'is_active']);
        });
        
        // PostgreSQL GIN indexes for JSONB columns (only for PostgreSQL)
        if ($useJsonb) {
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_port_ids_gin ON carrier_article_mappings USING GIN (port_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_port_group_ids_gin ON carrier_article_mappings USING GIN (port_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_vehicle_categories_gin ON carrier_article_mappings USING GIN (vehicle_categories)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_category_group_ids_gin ON carrier_article_mappings USING GIN (category_group_ids)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_vessel_names_gin ON carrier_article_mappings USING GIN (vessel_names)');
            DB::statement('CREATE INDEX IF NOT EXISTS carrier_article_mappings_vessel_classes_gin ON carrier_article_mappings USING GIN (vessel_classes)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        // Drop GIN indexes if PostgreSQL
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_port_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_port_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_vehicle_categories_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_category_group_ids_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_vessel_names_gin');
            DB::statement('DROP INDEX IF EXISTS carrier_article_mappings_vessel_classes_gin');
        }
        
        Schema::dropIfExists('carrier_article_mappings');
    }
};

