<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Robaws upload deduplication (storage_disk already exists)
            if (!Schema::hasColumn('documents', 'robaws_last_upload_sha')) {
                $table->string('robaws_last_upload_sha', 64)->nullable()->after('source_content_sha');
            }
            if (!Schema::hasColumn('documents', 'robaws_quotation_id')) {
                $table->unsignedBigInteger('robaws_quotation_id')->nullable()->after('robaws_last_upload_sha');
            }
            if (!Schema::hasColumn('documents', 'processing_status')) {
                $table->string('processing_status', 50)->default('pending')->after('robaws_quotation_id');
            }
        });
        
        // Add indexes in a separate call to avoid conflicts
        Schema::table('documents', function (Blueprint $table) {
            // Check if indexes don't already exist
            $indexes = collect(DB::select("PRAGMA index_list(documents)"))->pluck('name')->toArray();
            
            if (!in_array('documents_robaws_dedup_idx', $indexes)) {
                $table->index(['robaws_quotation_id', 'robaws_last_upload_sha'], 'documents_robaws_dedup_idx');
            }
            if (!in_array('documents_processing_status_index', $indexes)) {
                $table->index(['processing_status']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_robaws_dedup_idx');
            $table->dropIndex(['processing_status']);
            $table->dropColumn(['storage_disk', 'robaws_last_upload_sha', 'robaws_quotation_id', 'processing_status']);
        });
    }
};
