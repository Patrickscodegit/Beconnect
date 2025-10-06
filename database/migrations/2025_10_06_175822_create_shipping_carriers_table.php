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
        Schema::create('shipping_carriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 10)->unique();
            $table->string('website_url', 255)->nullable();
            $table->string('api_endpoint', 255)->nullable();
            $table->json('specialization')->nullable();
            $table->json('service_types')->nullable(); // RORO, FCL, LCL, BREAKBULK
            $table->enum('service_level', ['Premium', 'Standard', 'Regional'])->default('Standard');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_carriers');
    }
};