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
            $table->string('robaws_quotation_id')->nullable()->after('service_used');
            $table->index('robaws_quotation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extractions', function (Blueprint $table) {
            $table->dropIndex(['robaws_quotation_id']);
            $table->dropColumn('robaws_quotation_id');
        });
    }
};
