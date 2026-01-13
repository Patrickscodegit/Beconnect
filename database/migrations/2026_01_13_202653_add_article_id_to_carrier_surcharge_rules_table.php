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
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) {
            $table->foreignId('article_id')->nullable()->after('event_code')->constrained('robaws_articles_cache')->onDelete('set null');
            $table->index('article_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) {
            $table->dropForeign(['article_id']);
            $table->dropIndex(['article_id']);
            $table->dropColumn('article_id');
        });
    }
};
