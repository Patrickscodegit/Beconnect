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
        Schema::table('shipping_schedules', function (Blueprint $table) {
            $table->string('voyage_number', 20)->nullable()->after('vessel_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_schedules', function (Blueprint $table) {
            $table->dropColumn('voyage_number');
        });
    }
};