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
        Schema::create('carrier_port_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->string('code', 50); // e.g., 'WAF', 'MED'
            $table->string('display_name', 255); // e.g., 'West Africa', 'Mediterranean'
            $table->json('aliases')->nullable();
            $table->integer('priority')->default(0);
            $table->integer('sort_order')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['carrier_id', 'code']); // IMPORTANT: per-carrier uniqueness
            $table->index(['carrier_id', 'is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_port_groups');
    }
};
