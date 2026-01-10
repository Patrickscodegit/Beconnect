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
        Schema::table('shipping_carriers', function (Blueprint $table) {
            $table->foreignId('robaws_supplier_id')
                  ->nullable()
                  ->after('code')
                  ->constrained('robaws_suppliers_cache')
                  ->onDelete('set null');
            
            $table->index('robaws_supplier_id');
        });
        
        // Data migration: Match existing shipping_carriers to robaws_suppliers_cache
        // This will run after suppliers are synced, so we'll do it in a separate step
        // For now, just add the column - matching will happen after supplier sync
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_carriers', function (Blueprint $table) {
            $table->dropForeign(['robaws_supplier_id']);
            $table->dropIndex(['robaws_supplier_id']);
            $table->dropColumn('robaws_supplier_id');
        });
    }
};
