<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_wmis')) {
            return;
        }

        Schema::create('vehicle_wmis', function (Blueprint $table) {
            $table->id();
            $table->string('wmi', 10)->unique();
            $table->string('manufacturer')->nullable();
            $table->string('country')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_wmis');
    }
};


