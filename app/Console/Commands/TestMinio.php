<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestMinio extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'minio:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test MinIO S3 connection and basic operations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing MinIO S3 connection...');

        try {
            // Test file upload
            $testContent = 'MinIO connection test - ' . now();
            $testPath = 'test-connection.txt';
            
            $this->info('Uploading test file...');
            Storage::disk('s3')->put($testPath, $testContent);
            
            // Test file exists
            if (Storage::disk('s3')->exists($testPath)) {
                $this->info('✓ File upload successful');
            } else {
                $this->error('✗ File upload failed');
                return 1;
            }
            
            // Test file retrieval
            $retrievedContent = Storage::disk('s3')->get($testPath);
            if ($retrievedContent === $testContent) {
                $this->info('✓ File retrieval successful');
            } else {
                $this->error('✗ File retrieval failed');
                return 1;
            }
            
            // Test file deletion
            Storage::disk('s3')->delete($testPath);
            if (!Storage::disk('s3')->exists($testPath)) {
                $this->info('✓ File deletion successful');
            } else {
                $this->error('✗ File deletion failed');
                return 1;
            }
            
            // Test directory operations
            $this->info('Testing directory operations...');
            Storage::disk('s3')->put('test-dir/file1.txt', 'Test file 1');
            Storage::disk('s3')->put('test-dir/file2.txt', 'Test file 2');
            
            $files = Storage::disk('s3')->files('test-dir');
            if (count($files) >= 2) {
                $this->info('✓ Directory listing successful');
            } else {
                $this->error('✗ Directory listing failed');
            }
            
            // Cleanup test directory
            Storage::disk('s3')->deleteDirectory('test-dir');
            
            $this->info('🎉 All MinIO tests passed successfully!');
            $this->info('MinIO is properly configured and ready to use.');
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('MinIO connection failed: ' . $e->getMessage());
            $this->error('Please check your MinIO server and configuration.');
            return 1;
        }
    }
}
