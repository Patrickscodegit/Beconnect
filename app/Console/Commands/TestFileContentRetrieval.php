<?php

namespace App\Console\Commands;

use App\Models\IntakeFile;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;

class TestFileContentRetrieval extends Command
{
    protected $signature = 'test:file-content';
    protected $description = 'Test the enhanced file content retrieval method';

    public function handle()
    {
        $this->info('=== Testing Enhanced File Content Retrieval ===');
        $this->newLine();

        $file = IntakeFile::first();
        if (!$file) {
            $this->error('No IntakeFile found in database.');
            return;
        }

        $this->info("File: {$file->filename}");
        $this->info("Storage Path: {$file->storage_path}");
        $this->info("Storage Disk: {$file->storage_disk}");
        $this->newLine();

        // Get the absolute path like RobawsExportService does
        $absolutePath = Storage::disk($file->storage_disk)->path($file->storage_path);
        $this->info("Absolute Path (from Storage::disk()->path()): {$absolutePath}");
        $this->newLine();

        // Create API client and test the getFileContent method
        $apiClient = new RobawsApiClient();

        try {
            // Use reflection to call the private method
            $reflection = new ReflectionClass($apiClient);
            $method = $reflection->getMethod('getFileContent');
            $method->setAccessible(true);
            
            $content = $method->invoke($apiClient, $absolutePath);
            
            $this->info('✅ SUCCESS: File content retrieved');
            $this->info('Content size: ' . strlen($content) . ' bytes');
            $this->info('First 100 chars: ' . substr($content, 0, 100) . '...');
            
        } catch (\Exception $e) {
            $this->error('❌ FAILED: ' . $e->getMessage());
            $this->error('Full error: ' . $e);
        }
    }
}
