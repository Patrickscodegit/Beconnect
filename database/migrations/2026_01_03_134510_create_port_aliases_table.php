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
        Schema::create('port_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('port_id')->constrained('ports')->onDelete('cascade');
            $table->string('alias', 255);
            $table->string('alias_normalized', 255);
            $table->string('alias_type', 50)->nullable()->comment('name_variant, code_variant, typo, combined, unlocode');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // UNIQUE constraint on alias_normalized - ONE normalized alias points to ONE port
            $table->unique('alias_normalized');
            
            // Indexes for efficient lookups
            $table->index('alias_normalized');
            $table->index('port_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('port_aliases');
    }
};

