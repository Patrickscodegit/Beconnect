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
        Schema::table('article_children', function (Blueprint $table) {
            // Add metadata for child relationships (composite items from Robaws)
            $table->string('cost_type')->nullable()->after('child_article_id');
            $table->decimal('default_quantity', 10, 2)->default(1.00)->after('cost_type');
            $table->decimal('default_cost_price', 10, 2)->nullable()->after('default_quantity');
            $table->string('unit_type')->nullable()->after('default_cost_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_children', function (Blueprint $table) {
            $table->dropColumn([
                'cost_type',
                'default_quantity',
                'default_cost_price',
                'unit_type',
            ]);
        });
    }
};
