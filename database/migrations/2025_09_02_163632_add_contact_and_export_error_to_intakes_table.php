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
            if (!Schema::hasColumn('intakes', 'contact_email')) {
                $table->string('contact_email')->nullable()->index();
            }
            if (!Schema::hasColumn('intakes', 'contact_phone')) {
                $table->string('contact_phone')->nullable()->index();
            }
            if (!Schema::hasColumn('intakes', 'last_export_error')) {
                $table->text('last_export_error')->nullable();
            }
            if (!Schema::hasColumn('intakes', 'last_export_error_at')) {
                $table->timestamp('last_export_error_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone', 'last_export_error', 'last_export_error_at']);
        });
    }
};
