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
            $table->date('update_date')->nullable()->after('effective_to');
            $table->date('validity_date')->nullable()->after('update_date');
            $table->index(['update_date', 'validity_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_purchase_tariffs', function (Blueprint $table) {
            $table->dropIndex(['update_date', 'validity_date']);
            $table->dropColumn(['update_date', 'validity_date']);
        });
    }
};

