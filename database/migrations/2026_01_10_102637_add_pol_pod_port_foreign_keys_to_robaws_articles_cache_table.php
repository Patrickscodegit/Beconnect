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
            // Add pol_port_id foreign key (nullable)
            if (!Schema::hasColumn('robaws_articles_cache', 'pol_port_id')) {
                $table->foreignId('pol_port_id')
                    ->nullable()
                    ->after('pol_code')
                    ->constrained('ports')
                    ->nullOnDelete();
            }

            // Add pod_port_id foreign key (nullable)
            if (!Schema::hasColumn('robaws_articles_cache', 'pod_port_id')) {
                $table->foreignId('pod_port_id')
                    ->nullable()
                    ->after('pod_code')
                    ->constrained('ports')
                    ->nullOnDelete();
            }

            // Add requires_manual_review column if it doesn't exist (it should already exist from initial migration)
            if (!Schema::hasColumn('robaws_articles_cache', 'requires_manual_review')) {
                $table->boolean('requires_manual_review')
                    ->default(false)
                    ->after('is_active');
            }
        });

        // Add indexes separately (after columns are created)
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            if (Schema::hasColumn('robaws_articles_cache', 'pol_port_id')) {
                $table->index('pol_port_id');
            }
            if (Schema::hasColumn('robaws_articles_cache', 'pod_port_id')) {
                $table->index('pod_port_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // Drop indexes first
            if (Schema::hasColumn('robaws_articles_cache', 'pol_port_id')) {
                $table->dropIndex(['pol_port_id']);
            }
            if (Schema::hasColumn('robaws_articles_cache', 'pod_port_id')) {
                $table->dropIndex(['pod_port_id']);
            }

            // Drop foreign key constraints
            if (Schema::hasColumn('robaws_articles_cache', 'pol_port_id')) {
                $table->dropForeign(['pol_port_id']);
                $table->dropColumn('pol_port_id');
            }
            if (Schema::hasColumn('robaws_articles_cache', 'pod_port_id')) {
                $table->dropForeign(['pod_port_id']);
                $table->dropColumn('pod_port_id');
            }

            // Note: Do NOT drop requires_manual_review as it may have been created in initial migration
            // Only drop it if this migration created it (but we check, so we won't drop existing ones)
        });
    }
};
