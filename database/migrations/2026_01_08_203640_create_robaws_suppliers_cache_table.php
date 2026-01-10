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
        Schema::create('robaws_suppliers_cache', function (Blueprint $table) {
            $table->id();
            $table->string('robaws_supplier_id')->unique();
            $table->string('name')->index();
            $table->string('code')->nullable()->index(); // Supplier code (if available in Robaws)
            $table->string('supplier_type')->nullable()->index(); // shipping_line, vendor, etc.
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
            $table->string('supplier_category')->nullable(); // company, individual, etc.
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable(); // Store full Robaws data
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_pushed_to_robaws_at')->nullable();
            $table->timestamps();
            
            $table->index(['supplier_type', 'is_active']);
            $table->index(['last_synced_at']);
            $table->index(['name', 'email']); // For duplicate detection
            $table->index(['code', 'is_active']); // For code lookups
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('robaws_suppliers_cache');
    }
};
