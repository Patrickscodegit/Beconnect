<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotation_additional_services')) {
            return;
        }

        Schema::create('quotation_additional_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('robaws_article_cache_id')->constrained('robaws_articles_cache')->cascadeOnDelete();
            $table->string('service_category')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_selected')->default(false);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['quotation_request_id', 'service_category'], 'qas_quotation_category_idx');
            $table->unique(['quotation_request_id', 'robaws_article_cache_id'], 'qas_unique_article_per_quote');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_additional_services');
    }
};


