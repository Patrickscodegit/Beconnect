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
            // Robaws file upload tracking
            $table->string('robaws_document_id')->nullable()->after('robaws_quotation_id');
            $table->timestamp('robaws_uploaded_at')->nullable()->after('robaws_document_id');
            $table->timestamp('robaws_upload_attempted_at')->nullable()->after('robaws_uploaded_at');
            
            // Upload status tracking
            $table->enum('upload_status', ['pending', 'uploaded', 'failed'])->nullable()->after('robaws_upload_attempted_at');
            $table->text('upload_error')->nullable()->after('upload_status');
            $table->string('upload_method')->nullable()->after('upload_error'); // 'direct' or 'bucket'
            
            // Original filename for better tracking
            $table->string('original_filename')->nullable()->after('filename');
            
            // Indexes for performance
            $table->index('robaws_document_id');
            $table->index('upload_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['robaws_document_id']);
            $table->dropIndex(['upload_status']);
            
            $table->dropColumn([
                'robaws_document_id',
                'robaws_uploaded_at', 
                'robaws_upload_attempted_at',
                'upload_status',
                'upload_error',
                'upload_method',
                'original_filename'
            ]);
        });
    }
};
