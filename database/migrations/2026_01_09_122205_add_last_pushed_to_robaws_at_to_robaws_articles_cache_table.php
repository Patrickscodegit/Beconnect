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
            if (!Schema::hasColumn('robaws_articles_cache', 'last_pushed_to_robaws_at')) {
                $table->datetime('last_pushed_to_robaws_at')->nullable()->after('last_pushed_validity_date');
                $table->index('last_pushed_to_robaws_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            if (Schema::hasColumn('robaws_articles_cache', 'last_pushed_to_robaws_at')) {
                $table->dropIndex(['last_pushed_to_robaws_at']);
                $table->dropColumn('last_pushed_to_robaws_at');
            }
        });
    }
};
