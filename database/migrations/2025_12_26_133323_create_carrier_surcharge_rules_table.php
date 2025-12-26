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
        Schema::create('carrier_surcharge_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->foreignId('port_id')->nullable()->constrained('ports')->onDelete('cascade');
            $table->string('vehicle_category', 50)->nullable();
            $table->foreignId('category_group_id')->nullable()->constrained('carrier_category_groups')->onDelete('cascade');
            $table->string('vessel_name', 100)->nullable();
            $table->string('vessel_class', 50)->nullable();
            
            // Event code (enum - no arbitrary eval)
            $table->string('event_code', 50)->notNull(); // 'TRACKING_PERCENT', 'CONAKRY_PORT_ADDITIONAL', 'TOWING', 'TANK_INSPECTION', 'OVERHEIGHT', 'OVERWEIGHT', 'STACKED', 'PIGGYBACK', 'OVERWIDTH_LM_BASIS', 'OVERWIDTH_STEP_BLOCKS'
            $table->string('name', 255)->notNull(); // Display name
            
            // Calculation mode (enum - preset only)
            $table->enum('calc_mode', [
                'FLAT',
                'PER_UNIT',
                'PERCENT_OF_BASIC_FREIGHT',
                'WEIGHT_TIER',
                'PER_TON_ABOVE',
                'PER_TANK',
                'PER_LM',
                'WIDTH_STEP_BLOCKS',
                'WIDTH_LM_BASIS'
            ])->notNull();
            
            // Calculation parameters (JSON)
            $table->json('params')->notNull(); // e.g., {"percentage": 10} for TRACKING_PERCENT, {"tiers": [...]} for WEIGHT_TIER
            
            // Versioning and precedence
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['carrier_id', 'port_id', 'vehicle_category', 'category_group_id', 'vessel_name', 'vessel_class', 'priority']);
            $table->index('event_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_surcharge_rules');
    }
};
