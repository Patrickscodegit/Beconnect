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
        Schema::table('documents', function (Blueprint $table) {
            // Only add robaws_last_sync_at if it doesn't exist
            if (!Schema::hasColumn('documents', 'robaws_last_sync_at')) {
                $table->timestamp('robaws_last_sync_at')->nullable()->after('robaws_uploaded_at');
            }
            
            // Update robaws_sync_status to support our new values if it exists
            if (Schema::hasColumn('documents', 'robaws_sync_status')) {
                // Drop and recreate to update enum values
                $table->dropColumn('robaws_sync_status');
            }
            $table->enum('robaws_sync_status', ['ready', 'needs_review', 'synced', 'not_found', 'error'])->nullable()->after('robaws_last_sync_at');
        });

        Schema::table('extractions', function (Blueprint $table) {
            if (!Schema::hasColumn('extractions', 'robaws_quotation_exists')) {
                $table->boolean('robaws_quotation_exists')->default(true)->after('robaws_quotation_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'robaws_last_sync_at')) {
                $table->dropColumn('robaws_last_sync_at');
            }
            if (Schema::hasColumn('documents', 'robaws_sync_status')) {
                $table->dropColumn('robaws_sync_status');
            }
        });

        Schema::table('extractions', function (Blueprint $table) {
            if (Schema::hasColumn('extractions', 'robaws_quotation_exists')) {
                $table->dropColumn('robaws_quotation_exists');
            }
        });
    }
};
