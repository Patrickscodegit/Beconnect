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
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->string('por')->nullable()->after('routing')->comment('Place of Receipt');
            $table->string('pol')->nullable()->after('por')->comment('Port of Loading');
            $table->string('pod')->nullable()->after('pol')->comment('Port of Discharge');
            $table->string('fdest')->nullable()->after('pod')->comment('Final Destination');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->dropColumn(['por', 'pol', 'pod', 'fdest']);
        });
    }
};
