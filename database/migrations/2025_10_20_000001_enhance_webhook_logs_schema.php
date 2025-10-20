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
        Schema::table('robaws_webhook_logs', function (Blueprint $table) {
            // Add retry counter
            $table->integer('retry_count')->default(0)->after('status');
            
            // Add processing duration in milliseconds
            $table->integer('processing_duration_ms')->nullable()->after('retry_count');
            
            // Add related entity tracking
            $table->unsignedBigInteger('article_id')->nullable()->after('robaws_id');
            $table->foreign('article_id')
                ->references('id')
                ->on('robaws_articles_cache')
                ->onDelete('set null');
            
            // Add index for article_id
            $table->index('article_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_webhook_logs', function (Blueprint $table) {
            $table->dropForeign(['article_id']);
            $table->dropIndex(['article_id']);
            $table->dropColumn(['retry_count', 'processing_duration_ms', 'article_id']);
        });
    }
};

