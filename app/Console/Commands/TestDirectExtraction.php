<?php

namespace App\Console\Commands;

use App\Services\AiRouter;
use App\Helpers\FileInput;
use App\Models\Document;
use Illuminate\Console\Command;

class TestDirectExtraction extends Command
{
    protected $signature = 'test:direct-extraction';
    protected $description = 'Test direct AI extraction';

    public function handle(AiRouter $aiRouter)
    {
        $this->info('=== Direct AI Extraction Test ===');
        
        // Get latest image document
        $document = Document::where('mime_type', 'like', 'image/%')
            ->latest()
            ->first();
            
        if (!$document) {
            $this->error('No image document found');
            return 1;
        }
        
        $this->line("Testing with Document ID: {$document->id}");
        $this->line("Filename: {$document->filename}");
        
        try {
            // Step 1: Test FileInput
            $this->info("\n1. Creating FileInput...");
            $fileInput = FileInput::forExtractor(
                $document->file_path,
                $document->mime_type ?? 'image/png'
            );
            $this->line("✅ FileInput created - Type: " . (isset($fileInput['url']) ? 'URL' : 'Bytes'));
            
            // Step 2: Test direct AI router call
            $this->info("\n2. Calling AiRouter::extractAdvanced...");
            $result = $aiRouter->extractAdvanced($fileInput, 'shipping');
            
            $this->info("✅ Extract completed!");
            $this->line("Status: " . ($result['status'] ?? 'unknown'));
            $this->line("Document Type: " . ($result['document_type'] ?? 'unknown'));
            
            // Check if it's an error response
            if (isset($result['extracted_data']['error'])) {
                $this->error("❌ Error in extracted data: " . $result['extracted_data']['error']);
            } else {
                $this->info("✅ Successful extraction");
                $this->line("Data keys: " . implode(', ', array_keys($result['extracted_data'] ?? [])));
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Exception thrown: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
