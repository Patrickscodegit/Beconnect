<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\RobawsIntegrationService;
use Illuminate\Console\Command;

class TestContactInfoFix extends Command
{
    protected $signature = 'test:contactinfo-fix {document_id?}';
    protected $description = 'Test the ContactInfo object fix';

    public function handle()
    {
        $documentId = $this->argument('document_id') ?? Document::latest()->first()?->id;
        
        if (!$documentId) {
            $this->error('No documents found');
            return 1;
        }
        
        $document = Document::find($documentId);
        if (!$document) {
            $this->error("Document {$documentId} not found");
            return 1;
        }
        
        $this->info("Testing ContactInfo fix with document: {$document->filename}");
        
        try {
            $service = app(RobawsIntegrationService::class);
            
            // Test building the enhanced extraction JSON (this is where the error occurred)
            $extraction = $document->extractions()->latest()->first();
            $extractedData = $extraction ? json_decode($extraction->raw_json, true) : [];
            
            $reflection = new \ReflectionClass($service);
            $method = $reflection->getMethod('buildEnhancedExtractionJson');
            $method->setAccessible(true);
            
            $result = $method->invoke($service, $extractedData, $extraction);
            
            $this->info('✅ ContactInfo fix working! Enhanced extraction JSON built successfully.');
            $this->info('Contact info extracted:');
            
            $contactInfo = $result['contact_info'] ?? [];
            $this->table(
                ['Field', 'Value'],
                [
                    ['Name', $contactInfo['name'] ?? 'N/A'],
                    ['Email', $contactInfo['email'] ?? 'N/A'],
                    ['Phone', $contactInfo['phone'] ?? 'N/A'],
                    ['Company', $contactInfo['company'] ?? 'N/A'],
                    ['Confidence', $contactInfo['_confidence'] ?? 'N/A'],
                ]
            );
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('❌ ContactInfo issue still present!');
            $this->error($e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
