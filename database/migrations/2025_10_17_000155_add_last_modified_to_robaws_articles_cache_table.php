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
            $table->timestamp('last_modified_at')->nullable()->after('last_synced_at');
            $table->index('last_modified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            $table->dropIndex(['last_modified_at']);
            $table->dropColumn('last_modified_at');
        });
    }
};
