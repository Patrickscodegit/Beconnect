<?php

namespace App\Database\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ManagesCrossDatabaseIndexes
{
    /**
     * Create index if it does not exist, cross-database compatible.
     */
    protected function createIndexIfMissing(string $table, string $index, array $columns): void
    {
        $driver = DB::getDriverName();

        // PostgreSQL supports IF NOT EXISTS
        if ($driver === 'pgsql') {
            $cols = implode(',', array_map(fn($c) => "\"{$c}\"", $columns));
            DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON \"{$table}\" ({$cols})");
            return;
        }

        // MySQL/MariaDB
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

        // SQLite
        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            $found = collect($indexes)->contains(function ($row) use ($index) {
                $name = is_array($row) ? ($row['name'] ?? null) : ($row->name ?? null);
                return $name === $index;
            });
            if (!$found) {
                $cols = implode('","', $columns);
                DB::statement("CREATE INDEX \"{$index}\" ON \"{$table}\" (\"{$cols}\")");
            }
            return;
        }

        // Fallback
        Schema::table($table, function (Blueprint $t) use ($columns, $index) {
            $t->index($columns, $index);
        });
    }

    /**
     * Drop index safely across database drivers.
     */
    protected function dropIndexSafely(string $table, string $index): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
        } catch (\Throwable $e) {
            // Index doesn't exist, that's fine
        }
    }
}
