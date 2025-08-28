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
            $table->timestamp('robaws_formatted_at')->nullable()->after('robaws_quotation_data');
            $table->string('robaws_sync_status')->nullable()->after('robaws_formatted_at');
            $table->timestamp('robaws_synced_at')->nullable()->after('robaws_sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'robaws_formatted_at',
                'robaws_sync_status',
                'robaws_synced_at'
            ]);
        });
    }
};
