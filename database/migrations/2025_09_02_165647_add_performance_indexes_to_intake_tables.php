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
        // Add performance indexes to intakes table
        Schema::table('intakes', function (Blueprint $table) {
            $table->index(['status']);
            $table->index(['robaws_offer_id']);
            $table->index(['created_at']);
        });

        // Add performance indexes to intake_files table
        Schema::table('intake_files', function (Blueprint $table) {
            $table->index(['intake_id', 'mime_type']);
            $table->index(['storage_disk']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['robaws_offer_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('intake_files', function (Blueprint $table) {
            $table->dropIndex(['intake_id', 'mime_type']);
            $table->dropIndex(['storage_disk']);
            $table->dropIndex(['created_at']);
        });
    }
};
