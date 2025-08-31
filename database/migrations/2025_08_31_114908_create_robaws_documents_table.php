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
        Schema::create('robaws_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('robaws_offer_id')->index();
            $table->string('robaws_document_id')->nullable()->index();
            $table->string('sha256', 64)->index();
            $table->string('filename');
            $table->unsignedBigInteger('filesize');
            $table->timestamps();
            $table->unique(['robaws_offer_id', 'sha256']); // idempotent per offer
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('robaws_documents');
    }
};
