<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Make is_parent_item nullable because we can only determine parent status
     * from the Robaws API "PARENT ITEM" checkbox. When API is unavailable,
     * we cannot reliably determine parent status from article description alone.
     */
    public function up(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            $table->boolean('is_parent_item')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            $table->boolean('is_parent_item')->default(false)->change();
        });
    }
};
