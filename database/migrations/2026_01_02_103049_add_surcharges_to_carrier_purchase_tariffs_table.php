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
            // BAF (Bunker Adjustment Factor)
            $table->decimal('baf_amount', 12, 2)->nullable()->after('base_freight_unit');
            $table->string('baf_unit')->nullable()->after('baf_amount'); // LUMPSUM|LM

            // ETS (Emissions Trading Scheme)
            $table->decimal('ets_amount', 12, 2)->nullable()->after('baf_unit');
            $table->string('ets_unit')->nullable()->after('ets_amount'); // LUMPSUM|LM

            // Port Additional (always LUMPSUM)
            $table->decimal('port_additional_amount', 12, 2)->nullable()->after('ets_unit');

            // Admin fee (always LUMPSUM)
            $table->decimal('admin_fxe_amount', 12, 2)->nullable()->after('port_additional_amount');

            // THC (Terminal Handling Charge)
            $table->decimal('thc_amount', 12, 2)->nullable()->after('admin_fxe_amount');
            $table->string('thc_unit')->nullable()->after('thc_amount'); // LUMPSUM|LM

            // Measurement costs (always LUMPSUM)
            $table->decimal('measurement_costs_amount', 12, 2)->nullable()->after('thc_unit');

            // Port-specific surcharges
            $table->decimal('congestion_surcharge_amount', 12, 2)->nullable()->after('measurement_costs_amount');
            $table->string('congestion_surcharge_unit')->nullable()->after('congestion_surcharge_amount'); // LUMPSUM|LM
            $table->decimal('iccm_amount', 12, 2)->nullable()->after('congestion_surcharge_unit'); // Conakry only, LUMPSUM
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_purchase_tariffs', function (Blueprint $table) {
            $table->dropColumn([
                'baf_amount',
                'baf_unit',
                'ets_amount',
                'ets_unit',
                'port_additional_amount',
                'admin_fxe_amount',
                'thc_amount',
                'thc_unit',
                'measurement_costs_amount',
                'congestion_surcharge_amount',
                'congestion_surcharge_unit',
                'iccm_amount',
            ]);
        });
    }
};
