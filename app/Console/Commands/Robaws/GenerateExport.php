<?php

namespace App\Console\Commands\Robaws;

use Illuminate\Console\Command;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

class GenerateExport extends Command
{
    protected $signature = 'robaws:export-file {path=storage/app/robaws-export.json : Output file path}';
    protected $description = 'Generate a consolidated Robaws quotations export JSON';

    public function handle(EnhancedRobawsIntegrationService $svc): int
    {
        $path = $this->argument('path');
        
        $this->info('Generating Robaws export file...');
        
        try {
            $data = $svc->generateExportFile();
            
            $fullPath = base_path($path);
            $directory = dirname($fullPath);
            
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $this->info("✅ Export written to: {$fullPath}");
            $this->line("Documents exported: {$data['export_metadata']['document_count']}");
            $this->line("Mapping version: {$data['export_metadata']['mapping_version']}");
            $this->line("Generated at: {$data['export_metadata']['generated_at']}");
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to generate export: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
