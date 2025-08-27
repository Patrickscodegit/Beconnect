<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Jobs\ExtractDocumentData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestExtractionDebug extends Command
{
    protected $signature = 'test:extraction-debug {document_id}';
    protected $description = 'Debug extraction process step by step';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        $document = Document::find($documentId);
        
        if (!$document) {
            $this->error("Document {$documentId} not found");
            return 1;
        }
        
        $this->info("=== Testing extraction for Document #{$document->id} ===");
        $this->line("Filename: {$document->filename}");
        $this->line("MIME type: {$document->mime_type}");
        $this->line("File path: {$document->file_path}");
        
        // Test OpenAI configuration
        $this->info("\n1. Checking OpenAI Configuration:");
        $config = config('services.openai');
        $this->line("API Key present: " . (!empty($config['api_key']) ? 'Yes' : 'No'));
        if (!empty($config['api_key'])) {
            $this->line("API Key preview: " . substr($config['api_key'], 0, 20) . "...");
        }
        $this->line("Vision model: " . ($config['vision_model'] ?? 'gpt-4o'));
        $this->line("Main model: " . ($config['model'] ?? 'not set'));
        
        // Test file existence
        $this->info("\n2. Checking File:");
        $disk = \Storage::disk(config('filesystems.default'));
        $exists = $disk->exists($document->file_path);
        $this->line("File exists in storage: " . ($exists ? 'Yes' : 'No'));
        if ($exists) {
            $size = $disk->size($document->file_path);
            $this->line("File size: " . number_format($size) . " bytes");
        }
        
        // Test extraction job
        $this->info("\n3. Running extraction job:");
        try {
            // Clear any previous extractions for clean test
            $document->extractions()->delete();
            
            // Dispatch synchronously
            $this->line("Dispatching ExtractDocumentData job...");
            dispatch_sync(new ExtractDocumentData($document));
            
            // Check results
            $extraction = $document->extractions()->latest()->first();
            
            if ($extraction) {
                $this->info("✅ Extraction created successfully!");
                $this->line("Status: {$extraction->status}");
                $this->line("Service: {$extraction->service_used}");
                $this->line("Confidence: " . ($extraction->confidence * 100) . "%");
                
                // Check for errors in extracted data
                if (isset($extraction->extracted_data['error'])) {
                    $this->error("❌ Error in extracted data: " . $extraction->extracted_data['error']);
                } else {
                    $this->info("✅ Clean extraction data (no errors)");
                    $dataKeys = array_keys($extraction->extracted_data);
                    $this->line("Data keys: " . implode(', ', $dataKeys));
                }
                
                // Show extracted data preview
                if ($extraction->extracted_data && !isset($extraction->extracted_data['error'])) {
                    $this->info("\n4. Extracted Data Preview:");
                    foreach ($extraction->extracted_data as $key => $value) {
                        if (is_array($value)) {
                            $this->line("  {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE));
                        } else {
                            $this->line("  {$key}: " . (strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value));
                        }
                    }
                }
                
            } else {
                $this->error("❌ No extraction created");
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Extraction failed with exception: " . $e->getMessage());
            $this->line("Exception class: " . get_class($e));
            if ($this->option('verbose')) {
                $this->line("Stack trace:");
                $this->line($e->getTraceAsString());
            }
        }
        
        return 0;
    }
}
