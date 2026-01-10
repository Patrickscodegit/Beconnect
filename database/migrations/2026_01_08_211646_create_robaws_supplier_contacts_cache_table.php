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
        Schema::create('robaws_supplier_contacts_cache', function (Blueprint $table) {
            $table->id();
            $table->string('robaws_contact_id')->unique();
            $table->string('robaws_supplier_id'); // Reference to robaws_supplier_id (string), not id
            $table->foreign('robaws_supplier_id')
                  ->references('robaws_supplier_id')
                  ->on('robaws_suppliers_cache')
                  ->onDelete('cascade');
            $table->string('name')->nullable(); // First name
            $table->string('surname')->nullable(); // Last name
            $table->string('full_name')->nullable()->index(); // Denormalized full name
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable(); // tel
            $table->string('mobile')->nullable(); // gsm
            $table->string('position')->nullable();
            $table->string('title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('receives_quotes')->default(false);
            $table->boolean('receives_invoices')->default(false);
            $table->json('metadata')->nullable(); // Full Robaws contact data
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            
            $table->index('robaws_supplier_id');
            $table->index('is_primary');
            $table->index(['email', 'robaws_supplier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('robaws_supplier_contacts_cache');
    }
};
