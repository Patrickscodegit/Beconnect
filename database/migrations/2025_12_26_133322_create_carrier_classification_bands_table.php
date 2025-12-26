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
        Schema::create('carrier_classification_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->foreignId('port_id')->nullable()->constrained('ports')->onDelete('cascade');
            $table->string('vessel_name', 100)->nullable(); // Optional vessel-specific classification
            $table->string('vessel_class', 50)->nullable(); // Optional vessel class-specific classification
            
            // Outcome: which vehicle category this band classifies cargo into
            $table->string('outcome_vehicle_category', 50)->notNull(); // Must match keys from config('quotation.commodity_types.vehicles.categories')
            
            // Classification criteria
            $table->decimal('min_cbm', 10, 4)->nullable();
            $table->decimal('max_cbm', 10, 4)->nullable();
            $table->decimal('max_height_cm', 10, 2)->nullable();
            
            // Logic: OR means "meets ANY criteria", AND means "meets ALL criteria"
            $table->enum('rule_logic', ['OR', 'AND'])->default('OR'); // e.g., "13 cbm OR height <= 170" = OR
            
            // Versioning and precedence
            $table->integer('priority')->default(0); // Higher priority = checked first
            $table->date('effective_from')->nullable(); // NULL = no start date limit
            $table->date('effective_to')->nullable(); // NULL = no end date limit
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['carrier_id', 'port_id', 'vessel_name', 'vessel_class', 'priority']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_classification_bands');
    }
};
