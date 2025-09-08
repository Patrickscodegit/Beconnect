<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Fix file paths that have 'documents/' prefix
     */
    public function up(): void
    {
        // Fix file paths that have 'documents/' prefix
        $files = DB::table('intake_files')
            ->where('file_path', 'like', 'documents/%')
            ->get();
        
        $fixedCount = 0;
        foreach ($files as $file) {
            $newPath = preg_replace('/^documents\//', '', $file->file_path);
            
            DB::table('intake_files')
                ->where('id', $file->id)
                ->update(['file_path' => $newPath]);
            
            Log::info('Fixed file path', [
                'file_id' => $file->id,
                'old_path' => $file->file_path,
                'new_path' => $newPath
            ]);
            
            $fixedCount++;
        }
        
        if ($fixedCount > 0) {
            Log::info("Fixed {$fixedCount} file paths in intake_files table");
        } else {
            Log::info('No file paths needed fixing in intake_files table');
        }
    }

    /**
     * Revert by adding 'documents/' prefix back
     */
    public function down(): void
    {
        $files = DB::table('intake_files')
            ->whereRaw("file_path NOT LIKE 'documents/%'")
            ->whereRaw("file_path != ''")
            ->get();
        
        foreach ($files as $file) {
            DB::table('intake_files')
                ->where('id', $file->id)
                ->update(['file_path' => 'documents/' . $file->file_path]);
            
            Log::info('Reverted file path', [
                'file_id' => $file->id,
                'reverted_path' => 'documents/' . $file->file_path
            ]);
        }
    }
};
