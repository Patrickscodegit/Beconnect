<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\RobawsIntegration\JsonFieldMapper;
use Illuminate\Console\Command;

class ManageRobawsJsonMapping extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:json-mapping {action} {--document=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Robaws JSON field mapping';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        
        switch ($action) {
            case 'test':
                $this->testMapping();
                break;
                
            case 'reload':
                $this->reloadMapping();
                break;
                
            case 'info':
                $this->showMappingInfo();
                break;
                
            case 'validate':
                $this->validateMapping();
                break;
                
            case 'export-config':
                $this->exportConfiguration();
                break;
                
            default:
                $this->error("Unknown action: {$action}");
                $this->info("Available actions: test, reload, info, validate, export-config");
        }
    }
    
    private function testMapping(): void
    {
        $service = app(EnhancedRobawsIntegrationService::class);
        $documentId = $this->option('document');
        
        if ($documentId) {
            $document = Document::find($documentId);
        } else {
            $document = Document::whereHas('extractions', function($q) {
                $q->where('status', 'completed');
            })->latest()->first();
        }
        
        if (!$document) {
            $this->error('No document found for testing');
            return;
        }
        
        $extraction = $document->extractions()->latest()->first();
        if (!$extraction || !$extraction->extracted_data) {
            $this->error('No extraction data found');
            return;
        }
        
        $this->info("Testing JSON mapping for Document ID: {$document->id}");
        $this->info("Filename: {$document->filename}");
        $this->newLine();
        
        // Process with new JSON mapper
        $result = $service->processDocument($document, $extraction->extracted_data);
        
        if ($result) {
            $this->info('✓ Mapping successful!');
            
            // Show mapped fields
            $document->refresh();
            $robawsData = $document->robaws_quotation_data;
            
            $this->table(['Field', 'Value'], [
                ['Customer', $robawsData['customer'] ?? 'NOT SET'],
                ['Customer Reference', $robawsData['customer_reference'] ?? 'NOT SET'],
                ['Email', $robawsData['client_email'] ?? 'NOT SET'],
                ['POR', $robawsData['por'] ?? 'NOT SET'],
                ['POL', $robawsData['pol'] ?? 'NOT SET'],
                ['POD', $robawsData['pod'] ?? 'NOT SET'],
                ['Cargo', $robawsData['cargo'] ?? 'NOT SET'],
                ['Dimensions', $robawsData['dim_bef_delivery'] ?? 'NOT SET'],
                ['Vehicle Brand', $robawsData['vehicle_brand'] ?? 'NOT SET'],
                ['Vehicle Model', $robawsData['vehicle_model'] ?? 'NOT SET'],
                ['Mapping Version', $robawsData['mapping_version'] ?? 'NOT SET'],
                ['Sync Status', $document->robaws_sync_status ?? 'NOT SET']
            ]);
            
        } else {
            $this->error('✗ Mapping failed!');
        }
    }
    
    private function reloadMapping(): void
    {
        $this->info('Reloading JSON mapping configuration...');
        
        try {
            $service = app(EnhancedRobawsIntegrationService::class);
            $service->reloadMappingConfiguration();
            $this->info('✓ Configuration reloaded successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to reload configuration: ' . $e->getMessage());
        }
    }
    
    private function showMappingInfo(): void
    {
        $service = app(EnhancedRobawsIntegrationService::class);
        $info = $service->getMappingInfo();
        
        $this->info('Robaws JSON Mapping Configuration');
        $this->info('================================');
        $this->info("Version: {$info['version']}");
        $this->info("Total Fields: {$info['total_fields']}");
        $this->newLine();
        
        $this->info('Fields by Section:');
        foreach ($info['fields_by_section'] as $section => $count) {
            $this->info("  • {$section}: {$count} fields");
        }
        
        $this->newLine();
        $summary = $service->getIntegrationSummary();
        $this->info('Integration Status:');
        $this->info("  Total Documents: {$summary['total_documents']}");
        $this->info("  Ready for Sync: {$summary['ready_for_sync']}");
        $this->info("  Needs Review: {$summary['needs_review']}");
        $this->info("  Success Rate: {$summary['success_rate']}%");
    }
    
    private function validateMapping(): void
    {
        $configPath = config_path('robaws-field-mapping.json');
        
        if (!file_exists($configPath)) {
            $this->error('Configuration file not found!');
            return;
        }
        
        $content = file_get_contents($configPath);
        json_decode($content);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: ' . json_last_error_msg());
            return;
        }
        
        $this->info('✓ JSON configuration is valid!');
        
        // Test instantiation
        try {
            $mapper = app(JsonFieldMapper::class);
            $this->info('✓ Field mapper instantiated successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to instantiate field mapper: ' . $e->getMessage());
        }
    }
    
    private function exportConfiguration(): void
    {
        $configPath = config_path('robaws-field-mapping.json');
        
        if (!file_exists($configPath)) {
            $this->error('Configuration file not found!');
            return;
        }
        
        $outputPath = storage_path('app/exports/robaws-field-mapping-' . date('Y-m-d-His') . '.json');
        $outputDir = dirname($outputPath);
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        copy($configPath, $outputPath);
        
        $this->info("Configuration exported to: {$outputPath}");
    }
}
