<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;

class TestSimpleFileUpload extends Command
{
    protected $signature = 'test:simple-file-upload {intake_id} {--offer-id=}';
    protected $description = 'Test simple file upload to an existing Robaws offer';

    public function handle()
    {
        $intakeId = $this->argument('intake_id');
        $offerId = $this->option('offer-id');
        
        $intake = Intake::find($intakeId);
        if (!$intake) {
            $this->error("Intake {$intakeId} not found");
            return 1;
        }
        
        if (!$offerId) {
            // Find recent offer from logs or use the latest one
            $this->info("No offer ID provided. Looking for recent offers...");
            // For now, let's use a known working offer
            $offerId = 11645; // From our recent test
        }
        
        $this->info("Testing file upload to offer {$offerId}...");
        
        $files = $intake->files;
        $this->info("Intake has {$files->count()} files:");
        
        foreach ($files as $file) {
            $this->info("  - {$file->filename} ({$file->mime_type})");
            
            // Get the file path
            $storage = Storage::disk($file->storage_disk);
            $absolutePath = $storage->path($file->storage_path);
            
            if (!file_exists($absolutePath)) {
                $this->error("    File not found at: {$absolutePath}");
                continue;
            }
            
            $this->info("    File exists at: {$absolutePath}");
            $this->info("    File size: " . filesize($absolutePath) . " bytes");
            
            // Test the API directly
            $exportService = app(RobawsExportService::class);
            $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
            
            $this->info("    Uploading to Robaws...");
            $result = $apiClient->attachFileToOffer($offerId, $absolutePath, $file->filename);
            
            if ($result['success']) {
                $this->info("    ✅ Upload successful!");
                $this->info("    Response: " . json_encode($result['data'], JSON_PRETTY_PRINT));
            } else {
                $this->error("    ❌ Upload failed!");
                $this->error("    Status: " . ($result['status'] ?? 'Unknown'));
                $this->error("    Error: " . ($result['error'] ?? 'Unknown'));
                
                // Try to decode the error
                if (isset($result['error'])) {
                    $decoded = json_decode($result['error'], true);
                    if ($decoded) {
                        $this->error("    Decoded error: " . json_encode($decoded, JSON_PRETTY_PRINT));
                    }
                }
            }
        }
        
        return 0;
    }
}
