<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
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
        });
    }

    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            foreach (['notes', 'mandatory_condition', 'is_mandatory', 'pod_code', 'pol_code', 'cost_side', 'article_type'] as $column) {
                if (Schema::hasColumn('robaws_articles_cache', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};


