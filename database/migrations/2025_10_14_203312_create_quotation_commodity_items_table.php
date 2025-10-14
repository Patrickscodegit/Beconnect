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
        Schema::create('quotation_commodity_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->onDelete('cascade');
            $table->integer('line_number'); // 1, 2, 3...
            
            // Commodity Type & Category
            $table->enum('commodity_type', ['vehicles', 'machinery', 'boat', 'general_cargo']);
            $table->string('category')->nullable(); // Vehicle: car, suv | Machinery: on_wheels, etc.
            
            // Vehicle/Machinery specific
            $table->string('make')->nullable();
            $table->string('type_model')->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('condition')->nullable(); // new, used, damaged
            $table->decimal('wheelbase_cm', 10, 2)->nullable();
            
            // Universal fields (ALL stored in metric)
            $table->integer('quantity')->default(1);
            
            // Dimensions - SEPARATE fields for better integration
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->decimal('cbm', 10, 4)->nullable(); // Auto-calculated: (L × W × H) / 1,000,000
            
            $table->decimal('weight_kg', 10, 2)->nullable();
            
            // General Cargo specific
            $table->decimal('bruto_weight_kg', 10, 2)->nullable(); // Gross weight
            $table->decimal('netto_weight_kg', 10, 2)->nullable(); // Net weight
            
            // Checkboxes
            $table->boolean('has_parts')->default(false); // Machinery
            $table->text('parts_description')->nullable();
            $table->boolean('has_trailer')->default(false); // Boat
            $table->boolean('has_wooden_cradle')->default(false); // Boat
            $table->boolean('has_iron_cradle')->default(false); // Boat
            $table->boolean('is_forkliftable')->default(false); // General Cargo
            $table->boolean('is_hazardous')->default(false); // General Cargo
            $table->boolean('is_unpacked')->default(false); // General Cargo
            $table->boolean('is_ispm15')->default(false); // General Cargo - ISPM15 wood
            
            // Pricing (calculated per unit)
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('line_total', 10, 2)->nullable(); // unit_price * quantity
            
            // Additional info
            $table->text('extra_info')->nullable();
            
            // File attachments (JSON array) - SIMPLE APPROACH
            $table->json('attachments')->nullable();
            /* Example structure:
            [
                {
                    "file": "quotations/123/item_1_photo.jpg",
                    "type": "photo",
                    "name": "car_photo.jpg",
                    "size": 245678,
                    "uploaded_at": "2025-01-14 10:30:00"
                },
                {
                    "file": "quotations/123/item_1_invoice.pdf",
                    "type": "invoice", 
                    "name": "purchase_invoice.pdf",
                    "size": 89234,
                    "uploaded_at": "2025-01-14 10:31:00"
                }
            ]
            */
            
            // Metadata
            $table->string('input_unit_system')->default('metric'); // 'metric' or 'us'
            
            $table->timestamps();
            
            $table->index(['quotation_request_id', 'line_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_commodity_items');
    }
};
