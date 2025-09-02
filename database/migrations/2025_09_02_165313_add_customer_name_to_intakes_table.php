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
        Schema::table('intakes', function (Blueprint $table) {
            if (!Schema::hasColumn('intakes', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('priority');
            }
            if (!Schema::hasColumn('intakes', 'extraction_data')) {
                $table->json('extraction_data')->nullable()->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'extraction_data']);
        });
    }
};
