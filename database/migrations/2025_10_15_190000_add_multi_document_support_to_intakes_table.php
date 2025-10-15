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
        Schema::table('intakes', function (Blueprint $table) {
            // robaws_offer_id already exists, skip it
            
            // Store aggregated extraction data from all documents
            if (!Schema::hasColumn('intakes', 'aggregated_extraction_data')) {
                $table->json('aggregated_extraction_data')->nullable()->after('extraction_data');
            }
            
            // Flag to indicate if this intake has multiple documents
            if (!Schema::hasColumn('intakes', 'is_multi_document')) {
                $table->boolean('is_multi_document')->default(false)->after('source');
            }
            
            // Track total number of documents
            if (!Schema::hasColumn('intakes', 'total_documents')) {
                $table->integer('total_documents')->default(1)->after('is_multi_document');
            }
            
            // Track how many documents have completed extraction
            if (!Schema::hasColumn('intakes', 'processed_documents')) {
                $table->integer('processed_documents')->default(0)->after('total_documents');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $drops = array_filter([
                Schema::hasColumn('intakes', 'aggregated_extraction_data') ? 'aggregated_extraction_data' : null,
                Schema::hasColumn('intakes', 'is_multi_document') ? 'is_multi_document' : null,
                Schema::hasColumn('intakes', 'total_documents') ? 'total_documents' : null,
                Schema::hasColumn('intakes', 'processed_documents') ? 'processed_documents' : null,
            ]);

            if (!empty($drops)) {
                $table->dropColumn($drops);
            }
        });
    }
};
