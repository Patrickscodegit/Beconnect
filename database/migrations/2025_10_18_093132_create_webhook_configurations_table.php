<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('robaws'); // 'robaws'
            $table->string('webhook_id')->nullable(); // ID from Robaws
            $table->text('secret'); // For signature verification
            $table->string('url'); // Our endpoint URL
            $table->json('events'); // ['article.created', 'article.updated', 'article.stock-changed']
            $table->boolean('is_active')->default(true);
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
            
            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_configurations');
    }
};