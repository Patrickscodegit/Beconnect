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
            $table->string('source_message_id')->nullable()->unique()->after('file_path');
            $table->string('source_content_sha', 64)->nullable()->unique()->after('source_message_id');
            
            $table->index(['source_message_id']);
            $table->index(['source_content_sha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['source_message_id', 'source_content_sha']);
        });
    }
};
