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
        Schema::create('robaws_customers_cache', function (Blueprint $table) {
            $table->id();
            $table->string('robaws_client_id')->unique();
            $table->string('name')->index();
            $table->string('role')->nullable()->index(); // FORWARDER, POV, BROKER, etc. (from custom field)
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->text('address')->nullable();
            $table->string('street')->nullable();
            $table->string('street_number')->nullable();
            $table->string('city')->nullable()->index();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable()->index();
            $table->string('country_code', 2)->nullable();
            $table->string('vat_number')->nullable()->index();
            $table->string('website')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('client_type')->nullable(); // company, individual
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable(); // Store full Robaws data
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_pushed_to_robaws_at')->nullable();
            $table->timestamps();
            
            $table->index(['role', 'is_active']);
            $table->index(['last_synced_at']);
            $table->index(['name', 'email']); // For duplicate detection
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('robaws_customers_cache');
    }
};
