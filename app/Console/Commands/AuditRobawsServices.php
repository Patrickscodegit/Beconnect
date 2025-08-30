<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Document;
use App\Services\RobawsIntegrationService;
use App\Services\SimpleRobawsIntegration;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

class AuditRobawsServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:robaws-services';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit all Robaws services for functionality and consistency';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Auditing Robaws Services');
        $this->line('================================');

        // 1. Check service instantiation
        $this->info('1. Testing Service Instantiation');
        $this->checkServiceInstantiation();

        // 2. Check database schema
        $this->info('2. Checking Database Schema');
        $this->checkDatabaseSchema();

        // 3. Test document processing
        $this->info('3. Testing Document Processing');
        $this->testDocumentProcessing();

        // 4. Check for redundant services
        $this->info('4. Checking for Redundant Services');
        $this->checkRedundantServices();

        $this->info('✅ Audit complete!');
    }

    private function checkServiceInstantiation()
    {
        try {
            $robawsService = app(RobawsIntegrationService::class);
            $this->line('✅ RobawsIntegrationService - OK');
        } catch (\Exception $e) {
            $this->error("❌ RobawsIntegrationService - FAILED: " . $e->getMessage());
        }

        try {
            $simpleService = app(SimpleRobawsIntegration::class);
            $this->line('✅ SimpleRobawsIntegration - OK');
        } catch (\Exception $e) {
            $this->error("❌ SimpleRobawsIntegration - FAILED: " . $e->getMessage());
        }

        try {
            $enhancedService = app(EnhancedRobawsIntegrationService::class);
            $this->line('✅ EnhancedRobawsIntegrationService - OK');
        } catch (\Exception $e) {
            $this->error("❌ EnhancedRobawsIntegrationService - FAILED: " . $e->getMessage());
        }
    }

    private function checkDatabaseSchema()
    {
        $document = Document::first();
        if (!$document) {
            $this->warn('⚠️  No documents found to test schema');
            return;
        }

        $requiredFields = [
            'robaws_sync_status',
            'robaws_formatted_at',
        ];

        foreach ($requiredFields as $field) {
            if (array_key_exists($field, $document->getAttributes())) {
                $this->line("✅ Field '$field' exists");
            } else {
                $this->error("❌ Field '$field' missing");
            }
        }
    }

    private function testDocumentProcessing()
    {
        $document = Document::with('extractions')->first();
        if (!$document) {
            $this->warn('⚠️  No documents found to test processing');
            return;
        }

        $this->line("Testing with document: {$document->filename}");

        // Test RobawsIntegrationService
        try {
            $robawsService = app(RobawsIntegrationService::class);
            $offer = $robawsService->createOfferFromDocument($document);
            $this->line('✅ RobawsIntegrationService::createOfferFromDocument - OK');
        } catch (\Exception $e) {
            $this->error("❌ RobawsIntegrationService::createOfferFromDocument - FAILED: " . $e->getMessage());
        }

        // Test SimpleRobawsIntegration
        try {
            $simpleService = app(SimpleRobawsIntegration::class);
            $readyDocs = $simpleService->getDocumentsReadyForExport();
            $this->line("✅ SimpleRobawsIntegration::getDocumentsReadyForExport - OK (" . count($readyDocs) . " documents)");
        } catch (\Exception $e) {
            $this->error("❌ SimpleRobawsIntegration::getDocumentsReadyForExport - FAILED: " . $e->getMessage());
        }
    }

    private function checkRedundantServices()
    {
        $serviceFiles = [
            'app/Services/SimpleRobawsClient.php',
            'app/Services/RobawsService.php',
        ];

        foreach ($serviceFiles as $file) {
            $fullPath = base_path($file);
            if (file_exists($fullPath)) {
                $this->warn("⚠️  Redundant service found: $file");
            } else {
                $this->line("✅ Redundant service removed: $file");
            }
        }
    }
}
