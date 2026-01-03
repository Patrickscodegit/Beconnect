<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carrier_purchase_tariffs', function (Blueprint $table) {
            // Add unit fields for surcharges that currently don't have them
            $table->string('port_additional_unit')->nullable()->after('port_additional_amount'); // LUMPSUM|LM
            $table->string('admin_fxe_unit')->nullable()->after('admin_fxe_amount'); // LUMPSUM|LM
            $table->string('measurement_costs_unit')->nullable()->after('measurement_costs_amount'); // LUMPSUM|LM
            $table->string('iccm_unit')->nullable()->after('iccm_amount'); // LUMPSUM|LM
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_purchase_tariffs', function (Blueprint $table) {
            $table->dropColumn([
                'port_additional_unit',
                'admin_fxe_unit',
                'measurement_costs_unit',
                'iccm_unit',
            ]);
        });
    }
};
