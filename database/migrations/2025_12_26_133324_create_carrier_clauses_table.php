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
        Schema::create('carrier_clauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers')->onDelete('cascade');
            $table->foreignId('port_id')->nullable()->constrained('ports')->onDelete('cascade');
            $table->string('vessel_name', 100)->nullable();
            $table->string('vessel_class', 50)->nullable();
            
            $table->enum('clause_type', ['LEGAL', 'OPERATIONAL', 'LIABILITY'])->notNull();
            $table->text('text')->notNull();
            
            // Versioning
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['carrier_id', 'port_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_clauses');
    }
};
