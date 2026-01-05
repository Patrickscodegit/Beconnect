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
            // Override columns to prevent Robaws sync from overwriting local edits
            $table->date('update_date_override')->nullable()->after('validity_date');
            $table->date('validity_date_override')->nullable()->after('update_date_override');
            $table->string('dates_override_source')->nullable()->after('validity_date_override');
            $table->timestamp('dates_override_at')->nullable()->after('dates_override_source');
            $table->timestamp('last_pushed_dates_at')->nullable()->after('dates_override_at');
            
            // Store what was pushed for audit trail
            $table->date('last_pushed_update_date')->nullable()->after('last_pushed_dates_at');
            $table->date('last_pushed_validity_date')->nullable()->after('last_pushed_update_date');
            
            $table->index('dates_override_source');
            $table->index('dates_override_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            $table->dropIndex(['dates_override_source']);
            $table->dropIndex(['dates_override_at']);
            $table->dropColumn([
                'update_date_override',
                'validity_date_override',
                'dates_override_source',
                'dates_override_at',
                'last_pushed_dates_at',
                'last_pushed_update_date',
                'last_pushed_validity_date',
            ]);
        });
    }
};

