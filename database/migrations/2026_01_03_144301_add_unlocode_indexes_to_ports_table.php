<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds indexes for UN/LOCODE lookups and country_code+code composite lookups.
     * These indexes improve performance for port matching during UN/LOCODE sync.
     */
    public function up(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            // Index on unlocode for fast UN/LOCODE lookups
            $table->index('unlocode', 'idx_ports_unlocode');
            
            // Composite index on (country_code, code) for matching strategy
            // This supports the priority 2 matching: country_code + code
            $table->index(['country_code', 'code'], 'idx_ports_country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->dropIndex('idx_ports_unlocode');
            $table->dropIndex('idx_ports_country_code');
        });
    }
};

