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
            // Export tracking fields
            $table->string('export_payload_hash')->nullable();
            $table->integer('export_attempt_count')->default(0);
            $table->text('last_export_error')->nullable();
            
            // Add indexes for performance
            $table->index(['exported_at', 'export_attempt_count'], 'idx_intake_export_status');
            $table->index('export_payload_hash', 'idx_intake_export_hash');
        });
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
