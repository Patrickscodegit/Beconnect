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
        Schema::table('extractions', function (Blueprint $table) {
            if (!Schema::hasColumn('extractions', 'document_id')) {
                $table->unsignedBigInteger('document_id')->nullable()->after('id');
                $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('extractions', 'status')) {
                $table->string('status')->default('pending')->after('document_id');
            }
            if (!Schema::hasColumn('extractions', 'extracted_data')) {
                $table->json('extracted_data')->nullable()->after('status');
            }
            if (!Schema::hasColumn('extractions', 'service_used')) {
                $table->string('service_used')->nullable()->after('confidence');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extractions', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropColumn(['document_id', 'status', 'extracted_data', 'service_used']);
        });
    }
};
