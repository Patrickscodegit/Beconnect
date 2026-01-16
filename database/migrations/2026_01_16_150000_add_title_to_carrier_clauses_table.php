<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('carrier_clauses', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('clause_type');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_clauses', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
