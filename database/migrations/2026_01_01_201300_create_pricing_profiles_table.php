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
        Schema::create('pricing_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('currency', 3)->default('EUR');
            $table->foreignId('carrier_id')
                ->nullable()
                ->constrained('shipping_carriers')
                ->nullOnDelete();
            $table->string('robaws_client_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['carrier_id', 'is_active']);
            $table->index(['robaws_client_id', 'is_active']);
            
            // Foreign key for robaws_client_id (string column, not id)
            $table->foreign('robaws_client_id')
                ->references('robaws_client_id')
                ->on('robaws_customers_cache')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_profiles');
    }
};