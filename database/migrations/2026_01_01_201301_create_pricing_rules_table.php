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
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_profile_id')
                ->constrained('pricing_profiles')
                ->onDelete('cascade');
            
            $table->string('vehicle_category')->nullable(); // CAR|SMALL_VAN|BIG_VAN|VBV|LM
            $table->string('unit_basis')->default('UNIT'); // UNIT|LM
            $table->string('margin_type')->default('FIXED'); // FIXED|PERCENT
            $table->decimal('margin_value', 12, 2);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Indexes
            $table->index(['pricing_profile_id', 'is_active']);
            $table->index(['pricing_profile_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};