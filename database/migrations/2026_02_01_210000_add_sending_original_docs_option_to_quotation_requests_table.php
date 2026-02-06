<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('quotation_requests', 'sending_original_docs_option')) {
                $table->string('sending_original_docs_option')
                    ->nullable()
                    ->after('special_requirements');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            if (Schema::hasColumn('quotation_requests', 'sending_original_docs_option')) {
                $table->dropColumn('sending_original_docs_option');
            }
        });
    }
};
