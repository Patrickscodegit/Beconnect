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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained()->onDelete('cascade');
            $table->string('filename');
            $table->string('file_path');
            $table->string('mime_type');
            $table->integer('file_size');
            $table->boolean('has_text_layer')->nullable();
            $table->string('document_type')->nullable();
            $table->integer('page_count')->nullable();
            $table->timestamps();
            
            $table->index(['intake_id', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
