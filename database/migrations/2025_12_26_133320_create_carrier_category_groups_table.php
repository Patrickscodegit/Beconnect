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
        Schema::create('carrier_category_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            
            $table->string('code', 50)->notNull(); // e.g., 'CARS', 'SMALL_VANS', 'BIG_VANS', 'LM_CARGO', 'HH'
            $table->string('display_name', 255)->notNull(); // e.g., "Cars", "Small Vans", "LM Cargo"
            $table->json('aliases')->nullable(); // ["LM", "LM Cargo", "HH", "High & Heavy"] for search/flexibility
            
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->unique(['carrier_id', 'code']);
            $table->index(['carrier_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_category_groups');
    }
};
