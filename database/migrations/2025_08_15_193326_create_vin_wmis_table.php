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
        Schema::create('vin_wmis', function (Blueprint $table) {
            $table->id();
            $table->string('wmi')->unique();
            $table->string('manufacturer');
            $table->string('country');
            $table->string('country_code', 2)->comment('ISO 3166-1 alpha-2');
            $table->integer('start_year');
            $table->integer('end_year')->nullable();
            $table->date('verified_at');
            $table->string('verified_by');
            $table->timestamps();
            
            $table->index(['wmi', 'start_year']);
            $table->index(['manufacturer', 'country_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vin_wmis');
    }
};
