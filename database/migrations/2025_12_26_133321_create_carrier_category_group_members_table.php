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
        Schema::create('carrier_category_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_category_group_id')->constrained('carrier_category_groups')->onDelete('cascade');
            
            $table->string('vehicle_category', 50)->notNull(); // One of 22 config keys
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->unique(['carrier_category_group_id', 'vehicle_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_category_group_members');
    }
};
