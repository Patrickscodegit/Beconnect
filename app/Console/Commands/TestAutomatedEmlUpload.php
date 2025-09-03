<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use App\Services\IntakeCreationService;

class TestAutomatedEmlUpload extends Command
{
    protected $signature = 'test:automated-eml-upload';
    protected $description = 'Test automated .eml file upload to Robaws';

    public function handle()
    {
        $this->info('Testing automated .eml file upload to Robaws...');
        $this->newLine();

        // Find a BMW .eml test intake that hasn't been exported yet
        $intake = Intake::where('project_reference', 'like', 'BMW%')
            ->whereNull('client')
            ->latest()
            ->first();

        if (!$intake) {
            $this->info('❌ No BMW test intake found. Let\'s create one...');
            
            // Get or create test BMW .eml file
            $emlFile = base_path('bmw_serie7_french.eml');
            if (!file_exists($emlFile)) {
                $this->error("❌ BMW .eml test file not found at: $emlFile");
                return 1;
            }
            
            // Create intake from .eml
            $creationService = new IntakeCreationService();
            $emailContent = file_get_contents($emlFile);
            $intake = $creationService->createFromEmail($emailContent);
            $this->info("✅ Created new test intake: {$intake->id}");
        } else {
            $this->info("✅ Found existing test intake: {$intake->id}");
        }

        $this->info("Project Reference: {$intake->project_reference}");
        $this->info("Status: {$intake->status}");

        // Check if intake has files
        $intakeFiles = $intake->files;
        $this->info("Intake Files: " . $intakeFiles->count());
        foreach ($intakeFiles as $file) {
            $this->info("  - {$file->original_filename} ({$file->mime_type})");
        }

        // Check if intake has documents
        $documents = $intake->documents;
        $this->info("Documents: " . $documents->count());
        foreach ($documents as $doc) {
            $this->info("  - {$doc->original_filename} ({$doc->mime_type})");
        }

        // Now test export
        $this->newLine();
        $this->info('🚀 Starting export to Robaws...');

        $exportService = app(RobawsExportService::class);

        try {
            $result = $exportService->exportIntake($intake);
            
            $this->info("Export result structure:");
            $this->info(json_encode($result, JSON_PRETTY_PRINT));
            
            if ($result['success']) {
                $this->info("✅ Export successful!");
                
                // Check various possible offer ID keys
                $offerId = $result['quotation_id'] ?? $result['offer_id'] ?? $result['id'] ?? $result['offerId'] ?? 'Unknown';
                $this->info("Offer ID: $offerId");
                
                // Check if files were attached
                if (isset($result['attached_files'])) {
                    $this->info("Attached Files: " . count($result['attached_files']));
                    foreach ($result['attached_files'] as $file) {
                        $this->info("  - {$file['filename']} ({$file['type']})");
                    }
                }
                
                // Refresh intake to see if client was set
                $intake->refresh();
                $this->info("Client ID: " . ($intake->client ?: 'Not set'));
                
            } else {
                $this->error("❌ Export failed: " . ($result['message'] ?? 'Unknown error'));
                if (isset($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $this->error("  - $error");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("❌ Exception during export: " . $e->getMessage());
            $this->error("Stack trace:");
            $this->error($e->getTraceAsString());
        }

        $this->newLine();
        $this->info('✅ Test completed.');
        
        return 0;
    }
}
