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
            $table->json('shipping_codes')->nullable()->after('code')->comment('Array of shipping line abbreviations');
            $table->boolean('is_european_origin')->default(false)->after('region')->comment('Flag for POL filtering');
            $table->boolean('is_african_destination')->default(false)->after('is_european_origin')->comment('Flag for POD filtering');
            $table->string('port_type')->default('both')->after('is_african_destination')->comment('pol, pod, or both');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_codes',
                'is_european_origin',
                'is_african_destination',
                'port_type',
            ]);
        });
    }
};
