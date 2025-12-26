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
        Schema::create('carrier_acceptance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->foreignId('port_id')->nullable()->constrained('ports')->onDelete('cascade');
            $table->string('vehicle_category', 50)->nullable(); // NULL = applies to all categories
            $table->foreignId('category_group_id')->nullable()->constrained('carrier_category_groups')->onDelete('cascade');
            $table->string('vessel_name', 100)->nullable(); // Specific vessel name (e.g., "Piranha")
            $table->string('vessel_class', 50)->nullable(); // Vessel class (e.g., "RoRo", "Container")
            
            // Hard limits (in cm/kg, nullable)
            $table->decimal('max_length_cm', 10, 2)->nullable();
            $table->decimal('max_width_cm', 10, 2)->nullable();
            $table->decimal('max_height_cm', 10, 2)->nullable();
            $table->decimal('max_cbm', 10, 4)->nullable();
            $table->decimal('max_weight_kg', 10, 2)->nullable();
            
            // Operational requirements
            $table->boolean('must_be_empty')->default(false); // "must be delivered empty"
            $table->boolean('must_be_self_propelled')->default(true);
            $table->enum('allow_accessories', ['NONE', 'ACCESSORIES_OF_UNIT_ONLY', 'UNRESTRICTED'])->default('UNRESTRICTED');
            $table->boolean('complete_vehicles_only')->default(false);
            $table->boolean('allows_stacked')->default(false);
            $table->boolean('allows_piggy_back')->default(false);
            
            // Soft limits ("upon request" - requires approval but allowed)
            $table->decimal('soft_max_height_cm', 10, 2)->nullable();
            $table->boolean('soft_height_requires_approval')->default(false);
            $table->decimal('soft_max_weight_kg', 10, 2)->nullable();
            $table->boolean('soft_weight_requires_approval')->default(false);
            
            // Destination-specific terms
            $table->boolean('is_free_out')->default(false); // "Free Out" destinations
            $table->boolean('requires_waiver')->default(false); // Waiver required
            $table->boolean('waiver_provided_by_carrier')->default(false); // e.g., "Conakry waiver provided by Grimaldi"
            
            $table->text('notes')->nullable();
            
            // Versioning and precedence
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['carrier_id', 'port_id', 'vehicle_category', 'category_group_id', 'vessel_name', 'vessel_class', 'priority']);
            $table->index(['vessel_name', 'vessel_class']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_acceptance_rules');
    }
};
