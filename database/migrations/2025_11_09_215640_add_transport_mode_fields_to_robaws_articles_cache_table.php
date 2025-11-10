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
            if (!Schema::hasColumn('robaws_articles_cache', 'transport_mode')) {
                $table->string('transport_mode', 50)->nullable()->after('shipping_line');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'article_type')) {
                $table->string('article_type', 100)->nullable()->after('is_parent_item');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'cost_side')) {
                $table->string('cost_side', 50)->nullable()->after('article_type');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'pol_code')) {
                $table->string('pol_code', 10)->nullable()->after('pol_terminal');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'pod_code')) {
                $table->string('pod_code', 10)->nullable()->after('pol_code');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'is_mandatory')) {
                $table->boolean('is_mandatory')->default(false)->after('commodity_type');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'mandatory_condition')) {
                $table->string('mandatory_condition', 255)->nullable()->after('is_mandatory');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'notes')) {
                $table->text('notes')->nullable()->after('mandatory_condition');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'service_type')) {
                $table->string('service_type', 100)->nullable()->after('transport_mode');
            }

            $table->index(['transport_mode'], 'idx_articles_transport_mode');
            $table->index(['transport_mode', 'pol_code', 'pod_code'], 'idx_articles_mode_pol_pod');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            if (Schema::hasColumn('robaws_articles_cache', 'transport_mode')) {
                $table->dropIndex('idx_articles_transport_mode');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'pol_code') || Schema::hasColumn('robaws_articles_cache', 'pod_code')) {
                $table->dropIndex('idx_articles_mode_pol_pod');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'service_type')) {
                $table->dropColumn('service_type');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'notes')) {
                $table->dropColumn('notes');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'mandatory_condition')) {
                $table->dropColumn('mandatory_condition');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'is_mandatory')) {
                $table->dropColumn('is_mandatory');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'pod_code')) {
                $table->dropColumn('pod_code');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'pol_code')) {
                $table->dropColumn('pol_code');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'cost_side')) {
                $table->dropColumn('cost_side');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'article_type')) {
                $table->dropColumn('article_type');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'transport_mode')) {
                $table->dropColumn('transport_mode');
            }
        });
    }
};
