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
            // POL in schedule format: "Antwerp, Belgium (ANR)"
            $table->string('pol_code')->nullable()->after('pol_terminal');
            
            // POD in schedule format: "Conakry, Guinea (CKY)"
            $table->string('pod_name')->nullable()->after('pol_code');
            
            // Indexes for filtering
            $table->index('pol_code');
            $table->index('pod_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            $table->dropIndex(['pol_code']);
            $table->dropIndex(['pod_name']);
            $table->dropColumn(['pol_code', 'pod_name']);
        });
    }
};