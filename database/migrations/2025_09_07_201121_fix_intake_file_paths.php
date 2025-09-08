<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix file paths that have 'documents/' prefix
     */
    public function up(): void
    {
        // Check if intake_files table exists
        if (!Schema::hasTable('intake_files')) {
            Log::info('intake_files table does not exist, skipping migration');
            return;
        }

        // Check which column exists for file paths
        $hasStoragePath = Schema::hasColumn('intake_files', 'storage_path');
        $hasFilePath = Schema::hasColumn('intake_files', 'file_path');
        
        $columnToFix = null;
        if ($hasStoragePath) {
            $columnToFix = 'storage_path';
        } elseif ($hasFilePath) {
            $columnToFix = 'file_path';
        } else {
            Log::info('No suitable column found in intake_files table for path fixing');
            return;
        }

        // Fix file paths that have 'documents/' prefix
        $files = DB::table('intake_files')
            ->where($columnToFix, 'like', 'documents/%')
            ->get();
        
        $fixedCount = 0;
        foreach ($files as $file) {
            $oldPath = $file->{$columnToFix};
            $newPath = preg_replace('/^documents\//', '', $oldPath);
            
            DB::table('intake_files')
                ->where('id', $file->id)
                ->update([$columnToFix => $newPath]);
            
            Log::info('Fixed file path', [
                'file_id' => $file->id,
                'column' => $columnToFix,
                'old_path' => $oldPath,
                'new_path' => $newPath
            ]);
            
            $fixedCount++;
        }
        
        if ($fixedCount > 0) {
            Log::info("Fixed {$fixedCount} file paths in intake_files table using column: {$columnToFix}");
        } else {
            Log::info("No file paths needed fixing in intake_files table (column: {$columnToFix})");
        }
    }

    /**
     * Revert by adding 'documents/' prefix back
     */
    public function down(): void
    {
        if (!Schema::hasTable('intake_files')) {
            return;
        }

        // Check which column exists for file paths
        $hasStoragePath = Schema::hasColumn('intake_files', 'storage_path');
        $hasFilePath = Schema::hasColumn('intake_files', 'file_path');
        
        $columnToRevert = null;
        if ($hasStoragePath) {
            $columnToRevert = 'storage_path';
        } elseif ($hasFilePath) {
            $columnToRevert = 'file_path';
        } else {
            return;
        }

        $files = DB::table('intake_files')
            ->whereRaw("{$columnToRevert} NOT LIKE 'documents/%'")
            ->whereRaw("{$columnToRevert} != ''")
            ->whereNotNull($columnToRevert)
            ->get();
        
        foreach ($files as $file) {
            $oldPath = $file->{$columnToRevert};
            $newPath = 'documents/' . $oldPath;
            
            DB::table('intake_files')
                ->where('id', $file->id)
                ->update([$columnToRevert => $newPath]);
            
            Log::info('Reverted file path', [
                'file_id' => $file->id,
                'column' => $columnToRevert,
                'reverted_path' => $newPath
            ]);
        }
    }
};
