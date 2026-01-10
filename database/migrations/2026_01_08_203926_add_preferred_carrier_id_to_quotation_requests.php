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
        Schema::table('quotation_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('quotation_requests', 'preferred_carrier_id')) {
                $table->foreignId('preferred_carrier_id')
                      ->nullable()
                      ->after('selected_schedule_id')
                      ->constrained('shipping_carriers')
                      ->onDelete('set null');
                
                $table->index('preferred_carrier_id');
            }
        });
        
        // Data migration: Convert existing preferred_carrier strings to IDs
        $useIlike = DB::getDriverName() === 'pgsql';
        
        if ($useIlike) {
            DB::statement("
                UPDATE quotation_requests
                SET preferred_carrier_id = (
                    SELECT id FROM shipping_carriers 
                    WHERE code = quotation_requests.preferred_carrier 
                    LIMIT 1
                )
                WHERE preferred_carrier IS NOT NULL
            ");
        } else {
            // SQLite/MySQL compatible
            DB::statement("
                UPDATE quotation_requests
                SET preferred_carrier_id = (
                    SELECT id FROM shipping_carriers 
                    WHERE code = quotation_requests.preferred_carrier 
                    LIMIT 1
                )
                WHERE preferred_carrier IS NOT NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->dropForeign(['preferred_carrier_id']);
            $table->dropIndex(['preferred_carrier_id']);
            $table->dropColumn('preferred_carrier_id');
        });
    }
};
