<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Jobs\ExtractDocumentData;
use App\Services\AiRouter;
use App\Helpers\FileInput;
use Illuminate\Console\Command;

class TestEmailExtraction extends Command
{
    protected $signature = 'test:email-extraction {document_id?}';
    protected $description = 'Test email extraction for debugging';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        
        if (!$documentId) {
            // Get latest .eml document
            $document = Document::where('filename', 'LIKE', '%.eml')->latest()->first();
        } else {
            $document = Document::find($documentId);
        }
        
        if (!$document) {
            $this->error('No .eml document found');
            return;
        }
        
        $this->info("Testing extraction for: {$document->filename}");
        $this->info("Document ID: {$document->id}");
        $this->info("MIME Type: {$document->mime_type}");
        $this->info("File Path: {$document->file_path}");
        
                // Check if file exists - try both locations
        $fullPath = storage_path('app/' . $document->file_path);
        $privatePath = storage_path('app/private/' . $document->file_path);
        
        if (file_exists($fullPath)) {
            $actualPath = $fullPath;
        } elseif (file_exists($privatePath)) {
            $actualPath = $privatePath;
        } else {
            $this->error("File not found at: $fullPath");
            $this->error("Also not found at: $privatePath");
            return;
        }
        
        $this->info("Testing extraction for: " . $document->filename);
        $this->info("Document ID: " . $document->id);
        $this->info("MIME Type: " . $document->mime_type);
        $this->info("File Path: " . $document->file_path);
        $this->info("Actual file location: $actualPath");
        $this->info("File exists: ✓");

        // Test step 1: Check analysis type determination
        $job = new ExtractDocumentData($document);
        $analysisType = $this->callProtectedMethod($job, 'determineAnalysisType', [$actualPath, $document->mime_type]);
        
        $this->info("Analysis Type: $analysisType");
        
        if ($analysisType === 'basic') {
            $this->error("❌ PROBLEM: .eml file is being classified as 'basic' instead of 'shipping'");
            $this->info("This is why AI extraction isn't working properly!");
        } else {
            $this->info("✅ Analysis type is correct: $analysisType");
        }

        // Test file input creation
        try {
            $fileInput = FileInput::forExtractor(
                $document->file_path,
                $document->mime_type ?? 'message/rfc822'
            );
            
            $this->info("FileInput created successfully");
            $this->info("FileInput type: " . (isset($fileInput['url']) ? 'URL' : 'BYTES'));
            
        } catch (\Exception $e) {
            $this->error("FileInput creation failed: " . $e->getMessage());
            return;
        }
        
        // Test AI Router with shipping analysis
        $aiRouter = new AiRouter(app('log'));
        
        $this->info("\nTesting AI extraction with 'shipping' analysis...");
        
        try {
            $result = $aiRouter->extractAdvanced($fileInput, 'shipping');
            
            $this->info("✓ AI extraction successful!");
            $this->info("Result status: " . ($result['status'] ?? 'unknown'));
            $this->info("Analysis type: " . ($result['analysis_type'] ?? 'unknown'));
            
            if (isset($result['extracted_data'])) {
                $extractedKeys = array_keys($result['extracted_data']);
                $this->info("Extracted data keys: " . implode(', ', $extractedKeys));
                
                // Show some key extracted data
                if (isset($result['extracted_data']['contact'])) {
                    $contact = $result['extracted_data']['contact'];
                    $this->info("Contact name: " . ($contact['name'] ?? 'NOT SET'));
                }
                
                if (isset($result['extracted_data']['vehicle'])) {
                    $vehicle = $result['extracted_data']['vehicle'];
                    $this->info("Vehicle: " . ($vehicle['brand'] ?? 'NOT SET') . ' ' . ($vehicle['model'] ?? 'NOT SET'));
                }
                
                if (isset($result['extracted_data']['shipment'])) {
                    $shipment = $result['extracted_data']['shipment'];
                    $this->info("Route: " . ($shipment['origin'] ?? 'NOT SET') . ' → ' . ($shipment['destination'] ?? 'NOT SET'));
                }
            }
            
        } catch (\Exception $e) {
            $this->error("AI extraction failed: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . ":" . $e->getLine());
            
            // Try with basic analysis
            $this->info("\nTrying with 'basic' analysis...");
            try {
                $result = $aiRouter->extractAdvanced($fileInput, 'basic');
                $this->info("Basic extraction result: " . json_encode($result, JSON_PRETTY_PRINT));
            } catch (\Exception $e2) {
                $this->error("Basic extraction also failed: " . $e2->getMessage());
            }
        }
    }
    
    /**
     * Helper method to call protected methods for testing
     */
    private function callProtectedMethod($object, $methodName, $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }
}
