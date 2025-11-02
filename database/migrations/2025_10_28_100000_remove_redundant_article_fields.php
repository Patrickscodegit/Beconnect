<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support DROP COLUMN - skip for SQLite local dev
        if (DB::getDriverName() === 'sqlite') {
            // For SQLite, these columns likely don't exist in local dev anyway
            // Mark migration as run without executing
            return;
        }
        
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Remove customer_type - it's a quotation property, not an article property
            // Check if column exists before dropping
            if (Schema::hasColumn('robaws_articles_cache', 'customer_type')) {
                $table->dropColumn('customer_type');
            }
            
            // Remove applicable_carriers - each article has one shipping_line
            // Note: applicable_carriers may be used at quotation level for multi-carrier routes
            // Check if column exists before dropping
            if (Schema::hasColumn('robaws_articles_cache', 'applicable_carriers')) {
                $table->dropColumn('applicable_carriers');
            }
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

