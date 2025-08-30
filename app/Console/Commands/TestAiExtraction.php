<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Jobs\ExtractDocumentData;
use Illuminate\Console\Command;

class TestAiExtraction extends Command
{
    protected $signature = 'ai:test-extraction {document_id?}';
    protected $description = 'Test AI extraction with the latest document';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        
        $document = $documentId 
            ? Document::findOrFail($documentId)
            : Document::latest()->first();
            
        if (!$document) {
            $this->error('No documents found');
            return 1;
        }
        
        $this->info("Testing AI extraction for Document #{$document->id}");
        $this->line("File: {$document->file_path}");
        $this->line("Storage: " . config('filesystems.default'));
        $this->line("MIME Type: " . ($document->mime_type ?? 'unknown'));
        
        try {
            // Run extraction synchronously for testing
            dispatch_sync(new ExtractDocumentData($document));
            
            // Reload document
            $document->refresh();
            $document->load('extractions');
            
            if ($document->extractions->isNotEmpty()) {
                $extraction = $document->extractions->latest()->first();
                $this->info("✅ Extraction successful!");
                $this->line("Status: " . $extraction->status);
                $this->line("Service: " . $extraction->service_used);
                $this->line("Confidence: " . ($extraction->confidence * 100) . "%");
                $this->newLine();
                
                if (!empty($extraction->extracted_data)) {
                    $this->line("Extracted data keys: " . implode(', ', array_keys($extraction->extracted_data)));
                }
            } else {
                $this->error("❌ No extraction created");
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Extraction failed: " . $e->getMessage());
            $this->line("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
    }
}
