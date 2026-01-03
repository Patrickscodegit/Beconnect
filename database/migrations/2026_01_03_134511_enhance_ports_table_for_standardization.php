<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds standardization columns to ports table.
     * IMPORTANT: Keep existing ports.type field AS-IS (pol/pod/both) - do NOT rename or convert.
     * port_category is a separate field for port type classification.
     */
    public function up(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->string('unlocode', 5)->nullable()->after('code')->comment('UN/LOCODE standard');
            $table->string('country_code', 2)->nullable()->after('country')->comment('ISO 3166-1 alpha-2');
            $table->string('port_category', 20)->default('UNKNOWN')->after('port_type')->comment('SEA_PORT, AIRPORT, ICD, UNKNOWN');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->dropColumn([
                'unlocode',
                'country_code',
                'port_category',
            ]);
        });
    }
};

