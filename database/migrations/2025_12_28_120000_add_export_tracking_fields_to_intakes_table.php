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
        // Skip safely if the table doesn't exist (e.g., test DB)
        if (! Schema::hasTable('intakes')) {
            return;
        }

        Schema::table('intakes', function (Blueprint $table) {
            // Export tracking fields
            if (! Schema::hasColumn('intakes', 'export_payload_hash')) {
                $table->string('export_payload_hash')->nullable();
            }
            
            if (! Schema::hasColumn('intakes', 'export_attempt_count')) {
                $table->integer('export_attempt_count')->default(0);
            }
            
            if (! Schema::hasColumn('intakes', 'last_export_error')) {
                $table->text('last_export_error')->nullable();
            }
            
            // Add indexes for performance (only if columns exist)
            if (Schema::hasColumn('intakes', 'exported_at') && Schema::hasColumn('intakes', 'export_attempt_count')) {
                try {
                    $table->index(['exported_at', 'export_attempt_count'], 'idx_intake_export_status');
                } catch (Exception $e) {
                    // Index might already exist
                }
            }
            
            if (Schema::hasColumn('intakes', 'export_payload_hash')) {
                try {
                    $table->index('export_payload_hash', 'idx_intake_export_hash');
                } catch (Exception $e) {
                    // Index might already exist
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('intakes')) {
            return;
        }

        Schema::table('intakes', function (Blueprint $table) {
            try {
                $table->dropIndex('idx_intake_export_status');
            } catch (Exception $e) {
                // Index might not exist
            }
            
            try {
                $table->dropIndex('idx_intake_export_hash');
            } catch (Exception $e) {
                // Index might not exist
            }
            
            $drops = array_filter([
                Schema::hasColumn('intakes', 'export_payload_hash') ? 'export_payload_hash' : null,
                Schema::hasColumn('intakes', 'export_attempt_count') ? 'export_attempt_count' : null,
                Schema::hasColumn('intakes', 'last_export_error') ? 'last_export_error' : null,
            ]);

            if ($drops) {
                $table->dropColumn($drops);
            }
        });
    }
};
