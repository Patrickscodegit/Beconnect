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
        // Extractions table - Add missing indexes
        Schema::table('extractions', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'document_id', 'extractions_document_id_index');
            $this->addIndexIfNotExists($table, 'status', 'extractions_status_index');
            $this->addIndexIfNotExists($table, 'service_used', 'extractions_service_used_index');
            $this->addIndexIfNotExists($table, 'analysis_type', 'extractions_analysis_type_index');
            $this->addIndexIfNotExists($table, 'verified_at', 'extractions_verified_at_index');
            $this->addIndexIfNotExists($table, 'created_at', 'extractions_created_at_index');
            $this->addIndexIfNotExists($table, 'updated_at', 'extractions_updated_at_index');
        });

        // Quotations table - Add missing indexes
        Schema::table('quotations', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'document_id', 'quotations_document_id_index');
            $this->addIndexIfNotExists($table, 'quotation_number', 'quotations_quotation_number_index');
            $this->addIndexIfNotExists($table, 'client_name', 'quotations_client_name_index');
            $this->addIndexIfNotExists($table, 'client_email', 'quotations_client_email_index');
            $this->addIndexIfNotExists($table, 'origin_port', 'quotations_origin_port_index');
            $this->addIndexIfNotExists($table, 'destination_port', 'quotations_destination_port_index');
            $this->addIndexIfNotExists($table, 'cargo_type', 'quotations_cargo_type_index');
            $this->addIndexIfNotExists($table, 'valid_until', 'quotations_valid_until_index');
            $this->addIndexIfNotExists($table, 'sent_at', 'quotations_sent_at_index');
            $this->addIndexIfNotExists($table, 'accepted_at', 'quotations_accepted_at_index');
            $this->addIndexIfNotExists($table, 'rejected_at', 'quotations_rejected_at_index');
        });

        // Intake files table - Add missing indexes
        Schema::table('intake_files', function (Blueprint $table) {
            $this->addIndexIfNotExists($table, 'mime_type', 'intake_files_mime_type_index');
            $this->addIndexIfNotExists($table, 'filename', 'intake_files_filename_index');
            $this->addIndexIfNotExists($table, 'updated_at', 'intake_files_updated_at_index');
        });
    }

    /**
     * Add single column index if it doesn't exist
     */
    private function addIndexIfNotExists(Blueprint $table, string $column, string $indexName): void
    {
        try {
            $table->index($column, $indexName);
        } catch (\Exception $e) {
            // Index might already exist, continue silently
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'duplicate key') === false &&
                strpos($e->getMessage(), 'Duplicate table') === false &&
                strpos($e->getMessage(), 'relation') === false) {
                // Re-throw if it's a different error
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop extractions table indexes
        Schema::table('extractions', function (Blueprint $table) {
            $table->dropIndex('extractions_document_id_index');
            $table->dropIndex('extractions_status_index');
            $table->dropIndex('extractions_service_used_index');
            $table->dropIndex('extractions_analysis_type_index');
            $table->dropIndex('extractions_verified_at_index');
            $table->dropIndex('extractions_created_at_index');
            $table->dropIndex('extractions_updated_at_index');
        });

        // Drop quotations table indexes
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotations_document_id_index');
            $table->dropIndex('quotations_quotation_number_index');
            $table->dropIndex('quotations_client_name_index');
            $table->dropIndex('quotations_client_email_index');
            $table->dropIndex('quotations_origin_port_index');
            $table->dropIndex('quotations_destination_port_index');
            $table->dropIndex('quotations_cargo_type_index');
            $table->dropIndex('quotations_valid_until_index');
            $table->dropIndex('quotations_sent_at_index');
            $table->dropIndex('quotations_accepted_at_index');
            $table->dropIndex('quotations_rejected_at_index');
        });

        // Drop intake_files table indexes
        Schema::table('intake_files', function (Blueprint $table) {
            $table->dropIndex('intake_files_mime_type_index');
            $table->dropIndex('intake_files_filename_index');
            $table->dropIndex('intake_files_updated_at_index');
        });
    }
};