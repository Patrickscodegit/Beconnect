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
        Schema::create('carrier_transform_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->foreignId('port_id')->nullable()->constrained('ports')->onDelete('cascade');
            $table->string('vehicle_category', 50)->nullable();
            $table->foreignId('category_group_id')->nullable()->constrained('carrier_category_groups')->onDelete('cascade');
            $table->string('vessel_name', 100)->nullable();
            $table->string('vessel_class', 50)->nullable();
            
            // Transform type (enum - no arbitrary eval)
            $table->enum('transform_code', ['OVERWIDTH_LM_RECALC'])->notNull(); // Start with this, add more as needed
            
            // Parameters (JSON, preset fields only)
            // {
            //   "trigger_width_gt_cm": 260,  // carrier-specific: 250/255/260 etc.
            //   "divisor_cm": 250            // usually 250 (2.5m). Keep configurable for future.
            // }
            $table->json('params')->notNull();
            
            // Versioning and precedence
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['carrier_id', 'port_id', 'vehicle_category', 'category_group_id', 'vessel_name', 'vessel_class', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_transform_rules');
    }
};
