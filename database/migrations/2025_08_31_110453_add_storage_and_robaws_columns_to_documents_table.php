<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create index if it does not exist, cross-DB compatible.
     */
    private function createIndexIfMissing(string $table, string $index, array $columns): void
    {
        $driver = DB::getDriverName();

        // PostgreSQL supports IF NOT EXISTS
        if ($driver === 'pgsql') {
            $cols = implode(',', array_map(fn($c) => "\"{$c}\"", $columns));
            DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON \"{$table}\" ({$cols})");
            return;
        }

        // MySQL/MariaDB 8+ supports IF NOT EXISTS, but to be safe we probe first
        if ($driver === 'mysql') {
            $exists = DB::selectOne("
                SELECT 1
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND index_name = ?
                LIMIT 1
            ", [$table, $index]);
            if (!$exists) {
                $cols = implode('`,`', $columns);
                DB::statement("CREATE INDEX `{$index}` ON `{$table}` (`{$cols}`)");
            }
            return;
        }

        // SQLite: check pragma schema, then create if missing
        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            $found = collect($indexes)->contains(function ($row) use ($index) {
                // name property can be array or object depending on PDO
                $name = is_array($row) ? ($row['name'] ?? null) : ($row->name ?? null);
                return $name === $index;
            });
            if (!$found) {
                $cols = implode('","', $columns);
                DB::statement("CREATE INDEX \"{$index}\" ON \"{$table}\" (\"{$cols}\")");
            }
            return;
        }

        // Fallback: try Schema builder (works if index doesn't already exist)
        Schema::table($table, function (Blueprint $t) use ($columns, $index) {
            $t->index($columns, $index);
        });
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Add columns (guards prevent re-adding)
            if (!Schema::hasColumn('documents', 'storage_disk')) {
                $table->string('storage_disk', 64)->nullable()->after('path');
            }
            if (!Schema::hasColumn('documents', 'storage_path')) {
                $table->string('storage_path', 512)->nullable()->after('storage_disk');
            }
            if (!Schema::hasColumn('documents', 'robaws_document_id')) {
                $table->string('robaws_document_id', 64)->nullable()->after('storage_path');
            }
            if (!Schema::hasColumn('documents', 'sha256')) {
                $table->string('sha256', 64)->nullable()->after('robaws_document_id');
            }
            if (!Schema::hasColumn('documents', 'robaws_last_upload_sha')) {
                $table->string('robaws_last_upload_sha', 64)->nullable()->after('sha256');
            }
            if (!Schema::hasColumn('documents', 'robaws_quotation_id')) {
                $table->unsignedBigInteger('robaws_quotation_id')->nullable()->after('robaws_last_upload_sha');
            }
            if (!Schema::hasColumn('documents', 'processing_status')) {
                $table->string('processing_status', 50)->default('pending')->after('robaws_quotation_id');
            }
        });

        // Create indexes idempotently using cross-database method
        $this->createIndexIfMissing('documents', 'documents_sha256_index', ['sha256']);
        $this->createIndexIfMissing('documents', 'documents_storage_disk_path_index', ['storage_disk', 'storage_path']);
        $this->createIndexIfMissing('documents', 'documents_robaws_document_id_index', ['robaws_document_id']);
        $this->createIndexIfMissing('documents', 'documents_robaws_dedup_idx', ['robaws_quotation_id', 'robaws_last_upload_sha']);
        $this->createIndexIfMissing('documents', 'documents_processing_status_index', ['processing_status']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes safely (ignore if not present)
        try { Schema::table('documents', fn (Blueprint $t) => $t->dropIndex('documents_sha256_index')); } catch (\Throwable $e) {}
        try { Schema::table('documents', fn (Blueprint $t) => $t->dropIndex('documents_storage_disk_path_index')); } catch (\Throwable $e) {}
        try { Schema::table('documents', fn (Blueprint $t) => $t->dropIndex('documents_robaws_document_id_index')); } catch (\Throwable $e) {}
        try { Schema::table('documents', fn (Blueprint $t) => $t->dropIndex('documents_robaws_dedup_idx')); } catch (\Throwable $e) {}
        try { Schema::table('documents', fn (Blueprint $t) => $t->dropIndex('documents_processing_status_index')); } catch (\Throwable $e) {}

        Schema::table('documents', function (Blueprint $table) {
            foreach (['processing_status','robaws_quotation_id','robaws_last_upload_sha','sha256','robaws_document_id','storage_path','storage_disk'] as $col) {
                if (Schema::hasColumn('documents', $col)) $table->dropColumn($col);
            }
        });
    }
};
