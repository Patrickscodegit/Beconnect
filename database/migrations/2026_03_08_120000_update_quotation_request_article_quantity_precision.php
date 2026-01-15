<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('quotation_request_articles') || !Schema::hasColumn('quotation_request_articles', 'quantity')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE quotation_request_articles MODIFY quantity DECIMAL(10,4) NOT NULL DEFAULT 1');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE quotation_request_articles ALTER COLUMN quantity TYPE NUMERIC(10,4)');
            DB::statement('ALTER TABLE quotation_request_articles ALTER COLUMN quantity SET DEFAULT 1');
            return;
        }

        // SQLite uses dynamic typing; keep column as-is to avoid table rebuild.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('quotation_request_articles') || !Schema::hasColumn('quotation_request_articles', 'quantity')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE quotation_request_articles MODIFY quantity INTEGER NOT NULL DEFAULT 1');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE quotation_request_articles ALTER COLUMN quantity TYPE INTEGER');
            DB::statement('ALTER TABLE quotation_request_articles ALTER COLUMN quantity SET DEFAULT 1');
            return;
        }

        // SQLite uses dynamic typing; keep column as-is to avoid table rebuild.
    }
};
