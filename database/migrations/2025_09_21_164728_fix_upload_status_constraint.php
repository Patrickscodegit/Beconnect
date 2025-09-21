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
            // Only drop the column if it exists
            if (Schema::hasColumn('documents', 'upload_status')) {
                // Drop the index first if it exists
                try {
                    $table->dropIndex(['upload_status']);
                } catch (\Exception $e) {
                    // Index might not exist, continue
                }
                $table->dropColumn('upload_status');
            }
        });
        
        Schema::table('documents', function (Blueprint $table) {
            // Recreate with all the values we need
            $table->enum('upload_status', [
                'pending', 
                'uploading', 
                'uploaded', 
                'failed', 
                'failed_permanent'
            ])->nullable()->after('robaws_upload_attempted_at');
            
            // Recreate the index
            $table->index('upload_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'upload_status')) {
                try {
                    $table->dropIndex(['upload_status']);
                } catch (\Exception $e) {
                    // Index might not exist, continue
                }
                $table->dropColumn('upload_status');
            }
        });
        
        Schema::table('documents', function (Blueprint $table) {
            // Restore original constraint
            $table->enum('upload_status', ['pending', 'uploaded', 'failed'])->nullable()->after('robaws_upload_attempted_at');
            $table->index('upload_status');
        });
    }
};