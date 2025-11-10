<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attempt to ensure both transport_mode and service_type columns exist.
     *
     * Historically this migration renamed the legacy service_type column to
     * transport_mode and then reintroduced service_type as a new field.
     * The environment this project is running in may or may not require the
     * rename, so we make the operation idempotent and guard every change.
     */
    public function up(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            if (!Schema::hasColumn('robaws_articles_cache', 'transport_mode')) {
                $table->string('transport_mode', 50)->nullable()->after('shipping_line');
            }

            if (!Schema::hasColumn('robaws_articles_cache', 'service_type')) {
                $table->string('service_type', 100)->nullable()->after('transport_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            if (Schema::hasColumn('robaws_articles_cache', 'service_type')) {
                $table->dropColumn('service_type');
            }

            if (Schema::hasColumn('robaws_articles_cache', 'transport_mode')) {
                $table->dropColumn('transport_mode');
            }
        });
    }
};


