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
        Schema::create('extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->onDelete('cascade');
            $table->json('raw_json');
            $table->decimal('confidence', 3, 2)->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamps();
            
            $table->index(['intake_id']);
            $table->index(['confidence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extractions');
    }
};
