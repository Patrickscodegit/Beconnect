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
            // Remove customer_type - it's a quotation property, not an article property
            $table->dropColumn('customer_type');
            
            // Remove applicable_carriers - each article has one shipping_line
            // Note: applicable_carriers may be used at quotation level for multi-carrier routes
            $table->dropColumn('applicable_carriers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Restore customer_type
            $table->string('customer_type')->nullable()->after('category');
            
            // Restore applicable_carriers as JSON
            $table->json('applicable_carriers')->nullable()->after('category');
        });
    }
};

