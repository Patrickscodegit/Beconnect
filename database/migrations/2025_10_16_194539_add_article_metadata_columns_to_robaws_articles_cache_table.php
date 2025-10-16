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
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Article Info metadata (from Robaws ARTICLE INFO section)
            $table->string('shipping_line')->nullable()->after('article_name');
            $table->string('service_type')->nullable()->after('shipping_line');
            $table->string('pol_terminal')->nullable()->after('service_type');
            $table->boolean('is_parent_item')->default(false)->after('is_parent_article');
            
            // Important Info metadata (from Robaws IMPORTANT INFO section)
            $table->text('article_info')->nullable()->after('description');
            $table->date('update_date')->nullable()->after('article_info');
            $table->date('validity_date')->nullable()->after('update_date');
            
            // Indexes for filtering performance
            $table->index('shipping_line');
            $table->index('service_type');
            $table->index('pol_terminal');
            $table->index('is_parent_item');
            $table->index(['shipping_line', 'service_type', 'pol_terminal'], 'article_filter_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['shipping_line', 'service_type', 'pol_terminal']);
            $table->dropIndex(['is_parent_item']);
            $table->dropIndex(['pol_terminal']);
            $table->dropIndex(['service_type']);
            $table->dropIndex(['shipping_line']);
            
            // Drop columns
            $table->dropColumn([
                'shipping_line',
                'service_type',
                'pol_terminal',
                'is_parent_item',
                'article_info',
                'update_date',
                'validity_date',
            ]);
        });
    }
};
