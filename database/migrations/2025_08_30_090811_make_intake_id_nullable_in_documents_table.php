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
            // Drop the foreign key constraint first
            $table->dropForeign(['intake_id']);
            
            // Make intake_id nullable
            $table->foreignId('intake_id')->nullable()->change();
            
            // Re-add the foreign key constraint as nullable
            $table->foreign('intake_id')->references('id')->on('intakes')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['intake_id']);
            
            // Make intake_id required again
            $table->foreignId('intake_id')->nullable(false)->change();
            
            // Re-add the foreign key constraint as required
            $table->foreign('intake_id')->references('id')->on('intakes')->onDelete('cascade');
        });
    }
};
