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
            // AI Extraction fields
            $table->json('extraction_data')->nullable()->after('page_count');
            $table->decimal('extraction_confidence', 5, 4)->nullable()->after('extraction_data');
            $table->string('extraction_service')->nullable()->after('extraction_confidence');
            $table->string('extraction_status')->nullable()->after('extraction_service');
            $table->timestamp('extracted_at')->nullable()->after('extraction_status');
            
            // Robaws integration fields
            $table->string('robaws_quotation_id')->nullable()->after('extracted_at');
            $table->json('robaws_quotation_data')->nullable()->after('robaws_quotation_id');
            
            // Add indexes for performance
            $table->index(['extraction_status']);
            $table->index(['robaws_quotation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['extraction_status']);
            $table->dropIndex(['robaws_quotation_id']);
            
            $table->dropColumn([
                'extraction_data',
                'extraction_confidence',
                'extraction_service',
                'extraction_status',
                'extracted_at',
                'robaws_quotation_id',
                'robaws_quotation_data',
            ]);
        });
    }
};
