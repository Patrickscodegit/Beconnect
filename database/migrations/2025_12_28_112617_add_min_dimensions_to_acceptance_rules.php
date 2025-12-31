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
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) {
            // Add minimum dimension/weight fields
            $table->decimal('min_length_cm', 10, 2)->nullable()->after('max_length_cm');
            $table->decimal('min_width_cm', 10, 2)->nullable()->after('max_width_cm');
            $table->decimal('min_height_cm', 10, 2)->nullable()->after('max_height_cm');
            $table->decimal('min_cbm', 10, 4)->nullable()->after('max_cbm');
            $table->decimal('min_weight_kg', 10, 2)->nullable()->after('max_weight_kg');
            
            // Add flag to distinguish hard rejection vs warnings
            $table->boolean('min_is_hard')->default(false)->after('min_weight_kg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) {
            $table->dropColumn([
                'min_length_cm',
                'min_width_cm',
                'min_height_cm',
                'min_cbm',
                'min_weight_kg',
                'min_is_hard',
            ]);
        });
    }
};
