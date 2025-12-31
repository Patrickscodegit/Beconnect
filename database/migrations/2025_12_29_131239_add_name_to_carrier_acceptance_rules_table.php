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
            $table->string('name', 255)->nullable()->after('carrier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_acceptance_rules', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
