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
        Schema::create('carrier_purchase_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_article_mapping_id')
                ->constrained('carrier_article_mappings')
                ->onDelete('cascade');
            
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->decimal('base_freight_amount', 12, 2);
            $table->string('base_freight_unit')->default('LUMPSUM'); // LUMPSUM|LM
            $table->string('source')->nullable(); // excel|import|manual
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['carrier_article_mapping_id', 'is_active']);
            $table->index(['carrier_article_mapping_id', 'effective_from', 'effective_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_purchase_tariffs');
    }
};