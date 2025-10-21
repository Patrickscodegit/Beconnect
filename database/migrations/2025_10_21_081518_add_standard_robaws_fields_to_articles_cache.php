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
            // Sales & Display
            $table->string('sales_name')->nullable()->after('article_name');
            $table->string('brand')->nullable()->after('sales_name');
            $table->string('barcode')->nullable()->after('brand');
            $table->string('article_number')->nullable()->after('barcode');
            
            // Pricing Details (after unit_price)
            $table->decimal('sale_price', 10, 2)->nullable()->after('unit_price');
            $table->decimal('cost_price', 10, 2)->nullable()->after('sale_price');
            $table->string('sale_price_strategy')->nullable()->after('cost_price');
            $table->string('cost_price_strategy')->nullable()->after('sale_price_strategy');
            $table->decimal('margin', 5, 2)->nullable()->after('cost_price_strategy');
            
            // Product Attributes
            $table->decimal('weight_kg', 10, 2)->nullable()->after('margin');
            $table->string('vat_tariff_id')->nullable()->after('weight_kg');
            $table->boolean('stock_article')->default(false)->after('vat_tariff_id');
            $table->boolean('time_operation')->default(false)->after('stock_article');
            $table->boolean('installation')->default(false)->after('time_operation');
            $table->boolean('wappy')->default(false)->after('installation');
            
            // Images & Media
            $table->string('image_id')->nullable()->after('wappy');
            
            // Composite Items (stored as JSON)
            $table->json('composite_items')->nullable()->after('image_id');
            
            // Indexes for performance
            $table->index('brand');
            $table->index('article_number');
            $table->index('stock_article');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['brand']);
            $table->dropIndex(['article_number']);
            $table->dropIndex(['stock_article']);
            
            // Drop columns
            $table->dropColumn([
                'sales_name',
                'brand',
                'barcode',
                'article_number',
                'sale_price',
                'cost_price',
                'sale_price_strategy',
                'cost_price_strategy',
                'margin',
                'weight_kg',
                'vat_tariff_id',
                'stock_article',
                'time_operation',
                'installation',
                'wappy',
                'image_id',
                'composite_items',
            ]);
        });
    }
};
