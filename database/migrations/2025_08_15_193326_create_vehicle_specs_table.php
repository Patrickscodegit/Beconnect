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
        Schema::create('vehicle_specs', function (Blueprint $table) {
            $table->id();
            $table->string('make');
            $table->string('model');
            $table->string('variant')->nullable();
            $table->integer('year');
            $table->decimal('length_m', 5, 2);
            $table->decimal('width_m', 5, 2);
            $table->decimal('height_m', 5, 2);
            $table->decimal('wheelbase_m', 5, 2);
            $table->integer('weight_kg');
            $table->integer('engine_cc');
            $table->enum('fuel_type', ['petrol', 'diesel', 'hybrid', 'phev', 'electric']);
            $table->foreignId('wmi_id')->constrained('vin_wmis')->onDelete('cascade');
            $table->timestamps();
            
            $table->index(['make', 'model', 'year']);
            $table->index(['fuel_type', 'year']);
            $table->index(['wmi_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_specs');
    }
};
