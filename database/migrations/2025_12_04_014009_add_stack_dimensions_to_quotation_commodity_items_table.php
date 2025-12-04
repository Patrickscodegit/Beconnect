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
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            $table->decimal('stack_length_cm', 10, 2)->nullable()->after('height_cm');
            $table->decimal('stack_width_cm', 10, 2)->nullable()->after('stack_length_cm');
            $table->decimal('stack_height_cm', 10, 2)->nullable()->after('stack_width_cm');
            $table->decimal('stack_weight_kg', 10, 2)->nullable()->after('stack_height_cm');
            $table->decimal('stack_cbm', 10, 4)->nullable()->after('stack_weight_kg');
            $table->decimal('stack_lm', 10, 4)->nullable()->after('stack_cbm');
            $table->integer('stack_unit_count')->nullable()->after('stack_lm');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            $table->dropColumn([
                'stack_length_cm',
                'stack_width_cm',
                'stack_height_cm',
                'stack_weight_kg',
                'stack_cbm',
                'stack_lm',
                'stack_unit_count',
            ]);
        });
    }
};
