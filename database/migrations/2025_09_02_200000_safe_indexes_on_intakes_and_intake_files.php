<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // ---------------------------
        // intakes(status)
        // ---------------------------
        if (!$this->indexExists('intakes', 'intakes_status_index', $driver)) {
            $this->createIndex($driver,
                pg: "CREATE INDEX IF NOT EXISTS intakes_status_index ON intakes (status)",
                mysql: "CREATE INDEX intakes_status_index ON intakes (status)",
                sqlite: "CREATE INDEX IF NOT EXISTS intakes_status_index ON intakes (status)"
            );
        }

        // intakes(robaws_offer_id) - skip if already exists
        if (!$this->indexExists('intakes', 'intakes_robaws_offer_id_index', $driver)) {
            $this->createIndex($driver,
                pg: "CREATE INDEX IF NOT EXISTS intakes_robaws_offer_id_index ON intakes (robaws_offer_id)",
                mysql: "CREATE INDEX intakes_robaws_offer_id_index ON intakes (robaws_offer_id)",
                sqlite: "CREATE INDEX IF NOT EXISTS intakes_robaws_offer_id_index ON intakes (robaws_offer_id)"
            );
        }

        // ---------------------------
        // intake_files(intake_id, mime_type)
        // ---------------------------
        if (!$this->indexExists('intake_files', 'intake_files_intake_id_mime_type_idx', $driver)) {
            $this->createIndex($driver,
                pg: "CREATE INDEX IF NOT EXISTS intake_files_intake_id_mime_type_idx ON intake_files (intake_id, mime_type)",
                mysql: "CREATE INDEX intake_files_intake_id_mime_type_idx ON intake_files (intake_id, mime_type)",
                sqlite: "CREATE INDEX IF NOT EXISTS intake_files_intake_id_mime_type_idx ON intake_files (intake_id, mime_type)"
            );
        }

        // intake_files(storage_disk)
        if (!$this->indexExists('intake_files', 'intake_files_storage_disk_idx', $driver)) {
            $this->createIndex($driver,
                pg: "CREATE INDEX IF NOT EXISTS intake_files_storage_disk_idx ON intake_files (storage_disk)",
                mysql: "CREATE INDEX intake_files_storage_disk_idx ON intake_files (storage_disk)",
                sqlite: "CREATE INDEX IF NOT EXISTS intake_files_storage_disk_idx ON intake_files (storage_disk)"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        $this->dropIndex($driver, 'intake_files_storage_disk_idx', 'intake_files');
        $this->dropIndex($driver, 'intake_files_intake_id_mime_type_idx', 'intake_files');
        $this->dropIndex($driver, 'intakes_robaws_offer_id_index', 'intakes');
        $this->dropIndex($driver, 'intakes_status_index', 'intakes');
    }

    // -------- helpers (no Doctrine needed) --------

    private function indexExists(string $table, string $index, string $driver): bool
    {
        return match ($driver) {
            'pgsql' => (bool) DB::selectOne("
                SELECT 1 FROM pg_indexes
                WHERE schemaname = ANY (current_schemas(false))
                  AND tablename = ?
                  AND indexname = ?
            ", [$table, $index]),

            'mysql', 'mariadb' => (bool) DB::selectOne("
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND index_name = ?
                LIMIT 1
            ", [$table, $index]),

            'sqlite' => (function() use ($table, $index) {
                $rows = DB::select("PRAGMA index_list('$table')");
                foreach ($rows as $row) {
                    // name property can be 'name' or index 1 depending on pdo driver mapping
                    $name = is_object($row) ? ($row->name ?? ($row->Name ?? null)) : null;
                    if ($name === $index) return true;
                }
                return false;
            })(),

            default => false,
        };
    }

    private function createIndex(string $driver, string $pg, string $mysql, string $sqlite): void
    {
        try {
            match ($driver) {
                'pgsql'  => DB::statement($pg),
                'mysql', 'mariadb' => DB::statement($mysql),
                'sqlite' => DB::statement($sqlite),
                default  => null,
            };
        } catch (\Throwable $e) {
            // swallow "already exists" or test-driver quirks
            // you can log here if you want visibility
        }
    }

    private function dropIndex(string $driver, string $index, string $table): void
    {
        try {
            match ($driver) {
                'pgsql'  => DB::statement("DROP INDEX IF EXISTS $index"),
                'mysql', 'mariadb' => function() use ($index, $table) {
                    // MySQL has no IF EXISTS for DROP INDEX
                    $exists = DB::selectOne("
                        SELECT 1 FROM information_schema.statistics
                        WHERE table_schema = DATABASE()
                          AND table_name = ?
                          AND index_name = ?
                        LIMIT 1
                    ", [$table, $index]);
                    if ($exists) DB::statement("DROP INDEX $index ON $table");
                },
                'sqlite' => DB::statement("DROP INDEX IF EXISTS $index"),
                default  => null,
            };
        } catch (\Throwable $e) {}
    }
};
