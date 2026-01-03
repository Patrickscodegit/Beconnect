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
        Schema::table('ports', function (Blueprint $table) {
            $table->string('iata_code', 3)->nullable()->after('unlocode');
            $table->string('icao_code', 4)->nullable()->after('iata_code');
            
            $table->index('iata_code');
            $table->index('icao_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->dropIndex(['iata_code']);
            $table->dropIndex(['icao_code']);
            $table->dropColumn(['iata_code', 'icao_code']);
        });
    }
};
