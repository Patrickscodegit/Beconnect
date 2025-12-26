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
        Schema::create('carrier_surcharge_article_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->foreignId('port_id')->nullable()->constrained('ports')->onDelete('cascade');
            $table->string('vehicle_category', 50)->nullable();
            $table->foreignId('category_group_id')->nullable()->constrained('carrier_category_groups')->onDelete('cascade');
            $table->string('vessel_name', 100)->nullable();
            $table->string('vessel_class', 50)->nullable();
            
            // Link event to article
            $table->string('event_code', 50)->notNull(); // Must match event_code from carrier_surcharge_rules
            $table->foreignId('article_id')->constrained('robaws_articles_cache')->onDelete('cascade');
            
            // Quantity calculation mode (usually matches calc_mode from surcharge rule)
            $table->enum('qty_mode', [
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
            
            // Additional parameters for quantity calculation (JSON, nullable)
            $table->json('params')->nullable();
            
            // Versioning
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['carrier_id', 'port_id', 'vehicle_category', 'category_group_id']);
            $table->index('event_code');
            $table->index('article_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_surcharge_article_maps');
    }
};
