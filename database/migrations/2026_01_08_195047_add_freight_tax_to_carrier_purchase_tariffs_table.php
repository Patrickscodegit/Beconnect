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
            // Freight Tax (separate from Port Additional)
            $table->decimal('freight_tax_amount', 12, 2)->nullable()->after('port_additional_unit');
            $table->string('freight_tax_unit')->nullable()->after('freight_tax_amount'); // LUMPSUM|LM
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_purchase_tariffs', function (Blueprint $table) {
            $table->dropColumn([
                'freight_tax_amount',
                'freight_tax_unit',
            ]);
        });
    }
};
