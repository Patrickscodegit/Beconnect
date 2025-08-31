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
        Schema::table('robaws_documents', function (Blueprint $table) {
            $table->unique(['robaws_offer_id', 'sha256'], 'robaws_offer_sha_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_documents', function (Blueprint $table) {
            $table->dropUnique('robaws_offer_sha_unique');
        });
    }
};
