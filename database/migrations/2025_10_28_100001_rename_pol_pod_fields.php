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
            // Rename for clarity - these store full format "City, Country (CODE)", not just codes
            $table->renameColumn('pol_code', 'pol');
            $table->renameColumn('pod_name', 'pod');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Restore original column names
            $table->renameColumn('pol', 'pol_code');
            $table->renameColumn('pod', 'pod_name');
        });
    }
};

