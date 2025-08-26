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
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('document_id')->nullable()->constrained()->onDelete('set null');
            $table->string('robaws_id')->unique();
            $table->string('quotation_number')->nullable();
            $table->string('status')->default('draft');
            
            // Client information
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_phone')->nullable();
            
            // Shipment details
            $table->string('origin_port')->nullable();
            $table->string('destination_port')->nullable();
            $table->string('cargo_type')->nullable();
            $table->string('container_type')->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->decimal('volume', 10, 2)->nullable();
            $table->integer('pieces')->nullable();
            
            // Financial
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('valid_until')->nullable();
            
            // Robaws data
            $table->json('robaws_data')->nullable();
            
            // Flags
            $table->boolean('auto_created')->default(false);
            $table->boolean('created_from_document')->default(false);
            
            // Status timestamps
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['robaws_id']);
            $table->index(['status']);
            $table->index(['auto_created']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
