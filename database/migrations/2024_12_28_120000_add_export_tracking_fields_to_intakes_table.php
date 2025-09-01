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
            // Check if columns don't already exist to prevent migration conflicts
            if (!Schema::hasColumn('intakes', 'export_payload_hash')) {
                $table->string('export_payload_hash')->nullable()->after('exported_at');
            }
            if (!Schema::hasColumn('intakes', 'export_attempt_count')) {
                $table->integer('export_attempt_count')->default(0)->after('export_payload_hash');
            }
            if (!Schema::hasColumn('intakes', 'last_export_error')) {
                $table->text('last_export_error')->nullable()->after('export_attempt_count');
            }
        });

        // Add indexes only if they don't exist
        if (!Schema::hasIndex('intakes', 'idx_intake_export_status')) {
            Schema::table('intakes', function (Blueprint $table) {
                $table->index(['exported_at', 'export_attempt_count'], 'idx_intake_export_status');
            });
        }
        
        if (!Schema::hasIndex('intakes', 'idx_intake_export_hash')) {
            Schema::table('intakes', function (Blueprint $table) {
                $table->index('export_payload_hash', 'idx_intake_export_hash');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $table->dropIndex('idx_intake_export_status');
            $table->dropIndex('idx_intake_export_hash');
            $table->dropColumn([
                'export_payload_hash',
                'export_attempt_count', 
                'last_export_error'
            ]);
        });
    }
};
